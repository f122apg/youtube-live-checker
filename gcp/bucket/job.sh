#!/bin/bash

###############################################################################
# Batch VM のホスト側で実行されるオーケストレータ
#
# VPN を張って YouTube からダウンロードする処理はコンテナ (別の network
# namespace) に隔離し、このスクリプト自身は VPN に一切触らない。
# これにより、ホストの default route が VPN に奪われなくなるため、
# GCP API / gcsfuse / Batch agent との疎通を守るための回避ハック
# (除外ルート群・/etc/hosts への restricted VIP 注入・resolv.conf の退避復元)
# が全て不要になった。
#
# 副次的に VM へ外部IPを付けられるようになり、Cloud NAT のデータ処理料
# (送受信 両方向 $0.045/GiB) が不要になる。
#
# 責務の分割:
#   ホスト (このスクリプト) : yt-dlp の取得 / リトライ制御 / 進捗監視 /
#                             info.json の整形 / Wasabi へのアップロード
#   コンテナ (record_in_vpn.sh) : VPN 接続 / kill switch / yt-dlp の実行
#
# AWS の認証情報はホスト側にのみ存在し、コンテナには渡らない。
###############################################################################

set -u

# ---------------------------------------------------------------------------
# 設定
# ---------------------------------------------------------------------------
YTLC_DIR=/opt/ytlc               # yt-dlp とコンテナ用スクリプトの置き場 (コンテナと共有)
WORK_DIR=/work                   # 録画物の出力先 (コンテナと共有)。Wasabi へはここを丸ごと上げる
SHARE_DIR=/mnt/share             # gcsfuse でマウントされた GCS バケット
YTDLP="${YTLC_DIR}/bin/yt-dlp"

MAX_RETRY=10
LOG_FILE="/tmp/ytdlp_$$.log"
CHECK_INTERVAL=60                               # ログ監視間隔（秒）
STALL_STORM_SECS=${STALL_STORM_SECS:-1200}      # 403ストーム型killまでの無進捗秒数（デフォルト20分）
STALL_GENERIC_SECS=${STALL_GENERIC_SECS:-3600}  # 汎用ストールkillまでの無進捗秒数（デフォルト60分）

# 録画用コンテナイメージ (Workflow から環境変数で渡される)
RECORDER_IMAGE="${RECORDER_IMAGE:-}"
if [ -z "$RECORDER_IMAGE" ]; then
    echo "ERROR: RECORDER_IMAGE is not set."
    exit 2
fi

# コンテナ名は英数字始まりでなければならない。
# CONTENT_ID はハイフン始まりになり得るため必ず接頭辞を付ける
CONTAINER_PREFIX="ytlc-$(echo "${CONTENT_ID}" | tr -c 'a-zA-Z0-9_.-' '-')"

# ---------------------------------------------------------------------------
# 後始末
#
# docker run クライアントを kill してもコンテナ自体は止まらないため、
# 異常終了経路でも必ず docker stop を通す（コンテナの孤児化防止）
# ---------------------------------------------------------------------------
_stop_container() {
    local name="$1"
    docker stop --time 30 "$name" > /dev/null 2>&1 || true
}

_cleanup() {
    if [ -n "${TAIL_PID:-}" ]; then
        kill "$TAIL_PID" 2>/dev/null || true
    fi
    local orphans
    orphans=$(docker ps -q --filter "name=^${CONTAINER_PREFIX}" 2>/dev/null)
    if [ -n "$orphans" ]; then
        echo "Stopping orphan containers: $orphans"
        docker stop --time 30 $orphans > /dev/null 2>&1 || true
    fi
    rm -f "$LOG_FILE"
}
trap _cleanup EXIT

# ---------------------------------------------------------------------------
# ホスト側の依存関係
#
# ffmpeg / deno / openvpn / python3 はコンテナイメージ側にあるため不要。
# ここで入れるのは info.json の整形とアップロードに使うものだけ。
# ---------------------------------------------------------------------------
echo "=== Installing host dependencies ==="
apt-get update
apt-get install -y jq moreutils zip unzip curl

# prepare aws cli
if ! command -v aws > /dev/null 2>&1; then
    TMP_AWS_DIR=$(mktemp -d)
    curl -sL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "${TMP_AWS_DIR}/awscliv2.zip"
    unzip -q "${TMP_AWS_DIR}/awscliv2.zip" -d "${TMP_AWS_DIR}"
    "${TMP_AWS_DIR}/aws/install"
    rm -rf "${TMP_AWS_DIR}"
fi
aws --version

# コンテナ内で OpenVPN を張るために TUN デバイスがホストに必要
if [ ! -c /dev/net/tun ]; then
    echo 'Loading tun kernel module...'
    modprobe tun || echo 'WARN: failed to load the tun module.'
fi

# swap を張る。
# e2-micro (1GB) に dockerd + containerd が加わるぶんメモリ余裕が減るため、
# ffmpeg のマージなど瞬間的なピークをディスクへ逃がして OOM を避ける。
# ブートディスクは無料枠 (30 GB-months) の範囲に十分収まっているので追加費用はかからない。
# 不要なら RECORDER_SWAP_SIZE=0 を渡すことで無効化できる。
RECORDER_SWAP_SIZE="${RECORDER_SWAP_SIZE:-2G}"
if [ "$RECORDER_SWAP_SIZE" != "0" ] && [ "$(swapon --show --noheadings | wc -l)" -eq 0 ]; then
    echo "=== Creating ${RECORDER_SWAP_SIZE} swap ==="
    if fallocate -l "$RECORDER_SWAP_SIZE" /swapfile 2>/dev/null; then
        chmod 600 /swapfile
        mkswap /swapfile > /dev/null
        swapon /swapfile && echo "swap enabled." || echo 'WARN: swapon failed.'
    else
        echo 'WARN: failed to allocate the swap file.'
    fi
fi
free -m

# ---------------------------------------------------------------------------
# 作業ディレクトリの準備
# ---------------------------------------------------------------------------
mkdir -p "${YTLC_DIR}/bin" "${YTLC_DIR}/scripts" "${YTLC_DIR}/out" "${WORK_DIR}"

# コンテナ内で実行するスクリプトを GCS から配置する。
# イメージに焼かずここから配ることで、スクリプト修正時にイメージの
# 再ビルドが不要になる（バケットを差し替えれば即反映される）
echo "=== Deploying container scripts from ${SHARE_DIR}/container ==="
cp "${SHARE_DIR}"/container/*.sh "${YTLC_DIR}/scripts/"
chmod +x "${YTLC_DIR}/scripts/"*.sh
ls -l "${YTLC_DIR}/scripts/"

# yt-dlp は毎ジョブ最新版を取得する。
# YouTube の仕様変更への追従を優先するためイメージには焼かない。
# ここでのダウンロードは VPN を通らない（素の外部IP → GitHub）ので速く、
# YouTube とは無関係なので Bot 判定のリスクもない。
echo "=== Fetching the latest yt-dlp ==="
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux -o "$YTDLP"
chmod +x "$YTDLP"
"$YTDLP" --version

# ---------------------------------------------------------------------------
# コンテナイメージの取得
#
# Docker Hub の public イメージを匿名で pull する。認証は不要。
# Docker Hub の匿名 pull レート制限は送信元IP単位だが、この VM の外部IPは
# ジョブごとに変わるエフェメラルIPなので実質影響しない。
# ---------------------------------------------------------------------------
echo "=== Pulling ${RECORDER_IMAGE} ==="
docker pull "${RECORDER_IMAGE}"
docker image inspect "${RECORDER_IMAGE}" --format 'image: {{.Id}} ({{.Size}} bytes)'

# ---------------------------------------------------------------------------
# コンテナ起動の共通引数
#
#   --cap-add=NET_ADMIN --device=/dev/net/tun : コンテナ内で OpenVPN を張るため
#   -v ${YTLC_DIR} : yt-dlp とスクリプトの受け渡し
#   -v ${WORK_DIR} : 録画物の出力先。ここだけがアップロード対象になるよう
#                    補助ファイルは ${YTLC_DIR} 側に置いている
#   /mnt/share (GCS) はコンテナに渡さない
# ---------------------------------------------------------------------------
# VPN_COUNTRIES は空白区切り（"JP KR US"）のため、文字列で渡すと引数が
# 分割されてしまう。必ず配列で展開すること
DOCKER_RUN_ARGS=(
    --rm
    --cap-add=NET_ADMIN
    --device=/dev/net/tun
    -e "CONTENT_ID=${CONTENT_ID}"
    -e "YTLC_DIR=${YTLC_DIR}"
    -e "YTDLP_TIMEOUT_SECONDS=${YTDLP_TIMEOUT_SECONDS:-86400}"
    -e "VPN_COUNTRIES=${VPN_COUNTRIES:-JP KR US}"
    -e "VPN_MIN_THROUGHPUT_KIBPS=${VPN_MIN_THROUGHPUT_KIBPS:-1024}"
    -v "${YTLC_DIR}:${YTLC_DIR}"
    -v "${WORK_DIR}:${WORK_DIR}"
)

# ---------------------------------------------------------------------------
# ダウンロード
#
# Bot判定エラー時などにコンテナを作り直してリトライする（最大10回）。
# コンテナを使い捨てることが、旧実装の vpn_disconnect → vpn_connect
# （別サーバーへの張り直し）に相当する。
# ---------------------------------------------------------------------------
YTDLP_EXIT_CODE=1

for i in $(seq 1 $MAX_RETRY); do
    echo "=== Download attempt $i/$MAX_RETRY ==="

    CONTAINER_NAME="${CONTAINER_PREFIX}-${i}"
    docker rm -f "$CONTAINER_NAME" > /dev/null 2>&1 || true

    # ログファイルを初期化
    > "$LOG_FILE"

    # コンテナをバックグラウンドで起動、ログをファイルに追記
    # (タイムアウトはコンテナ内の timeout が担当する)
    docker run "${DOCKER_RUN_ARGS[@]}" --name "$CONTAINER_NAME" \
        "${RECORDER_IMAGE}" \
        bash "${YTLC_DIR}/scripts/record_in_vpn.sh" download >> "$LOG_FILE" 2>&1 &
    DOCKER_PID=$!

    # tail -f でリアルタイムにCloud Loggingへ出力
    tail -f "$LOG_FILE" &
    TAIL_PID=$!

    # エラー検知フラグ
    ERROR_DETECTED=0

    # ストール検知用の状態（試行ごとに初期化）
    LAST_WORK_BYTES=-1        # /workの合計バイト数（前回計測値）
    STALL_START_TIME=$SECONDS # 無進捗が始まった時刻
    PREV_403_COUNT=0          # 403行数（前回チェック時点）
    STALL_403_INCREASE=0      # 無進捗継続中の403増分の累計
    LAST_DIAG_TIME=$SECONDS   # 前回の診断ログ出力時刻

    # コンテナが動作中はログを監視
    # (監視対象はログファイルと /work のバイト数のみで、どちらも bind mount 越しに
    #  ホストから見えるため、コンテナ化後もロジックはそのまま使える)
    while kill -0 $DOCKER_PID 2>/dev/null; do
        # Bot判定メッセージ、既知のエラーメッセージを検知
        # (スタックトレース・Did not get any data blocksはyt-dlpが自力回復できる
        #  軽微な例外にも反応して誤killするため対象外。致命的クラッシュはyt-dlp自身の
        #  終了コードで外側ループが拾う)
        if grep -qE 'not a bot|botではない|ERROR: unable to download video data' "$LOG_FILE"; then
            echo "Error detected in yt-dlp output. Stopping container..."
            _stop_container "$CONTAINER_NAME"
            wait $DOCKER_PID 2>/dev/null
            ERROR_DETECTED=1
            break
        fi

        # --- 二段ストール検知（403ストーム型・汎用型） ---
        # /work未作成等でduが失敗しても監視ループを壊さないよう、失敗時は前回値を維持する
        CURRENT_WORK_BYTES=$(du -sb "$WORK_DIR" 2>/dev/null | awk '{print $1}')
        if ! [[ "$CURRENT_WORK_BYTES" =~ ^[0-9]+$ ]]; then
            CURRENT_WORK_BYTES=$LAST_WORK_BYTES
        fi

        # 403行数の増分は前回チェック時のgrep -c値との差分で算出
        # (grep -cはマッチ0件でもstdoutに0を出力してexit 1になるため、|| echo 0は使わない。
        #  ファイル不存在で出力が空になるケースのみ:-0で吸収)
        CURRENT_403_COUNT=$(grep -c 'HTTP Error 403.*Retrying fragment' "$LOG_FILE" 2>/dev/null)
        CURRENT_403_COUNT=${CURRENT_403_COUNT:-0}
        DELTA_403=$((CURRENT_403_COUNT - PREV_403_COUNT))
        [ $DELTA_403 -lt 0 ] && DELTA_403=0
        PREV_403_COUNT=$CURRENT_403_COUNT

        if [ "$CURRENT_WORK_BYTES" -gt "$LAST_WORK_BYTES" ]; then
            # バイト増があった時点でストールタイマーと403カウント基準値をリセット
            LAST_WORK_BYTES=$CURRENT_WORK_BYTES
            STALL_START_TIME=$SECONDS
            STALL_403_INCREASE=0
        else
            STALL_403_INCREASE=$((STALL_403_INCREASE + DELTA_403))
            STALL_ELAPSED=$((SECONDS - STALL_START_TIME))

            # 403ストーム型: 無進捗がSTALL_STORM_SECS継続 かつ 403が50件以上増加
            if [ $STALL_ELAPSED -ge $STALL_STORM_SECS ] && [ $STALL_403_INCREASE -ge 50 ]; then
                echo "Stall detected (403 storm): no progress in ${WORK_DIR} for ${STALL_ELAPSED}s, 403 errors +${STALL_403_INCREASE}. Stopping container..."
                _stop_container "$CONTAINER_NAME"
                wait $DOCKER_PID 2>/dev/null
                ERROR_DETECTED=1
                break
            fi

            # 汎用型: 無進捗がSTALL_GENERIC_SECS継続（配信開始待ち中は発火させない）
            if [ $STALL_ELAPSED -ge $STALL_GENERIC_SECS ]; then
                if tail -n 50 "$LOG_FILE" 2>/dev/null | grep -q "Waiting for"; then
                    echo "Stall detected (${STALL_ELAPSED}s) but recent log shows 'Waiting for' (stream not started yet). Skipping generic stall kill."
                else
                    echo "Stall detected (generic): no progress in ${WORK_DIR} for ${STALL_ELAPSED}s. Stopping container..."
                    _stop_container "$CONTAINER_NAME"
                    wait $DOCKER_PID 2>/dev/null
                    ERROR_DETECTED=1
                    break
                fi
            fi
        fi

        # --- 診断ログ（10分ごと。マージ中の無言死=ディスク枯渇/メモリ枯渇疑いの診断用） ---
        if [ $((SECONDS - LAST_DIAG_TIME)) -ge 600 ]; then
            echo "[DIAG] disk usage:"
            df -h "$WORK_DIR" 2>/dev/null || echo "[DIAG] df -h ${WORK_DIR} failed"
            du -sh "$WORK_DIR" 2>/dev/null || echo "[DIAG] du -sh ${WORK_DIR} failed"
            # dockerd を載せたぶん e2-micro (1GB) では余裕が減る。
            # machineType を上げるべきかの判断材料として実測値を残す
            echo "[DIAG] memory:"
            free -m 2>/dev/null || echo "[DIAG] free -m failed"
            docker stats --no-stream --format '[DIAG] container {{.Name}}: mem={{.MemUsage}} cpu={{.CPUPerc}}' 2>/dev/null \
                || echo "[DIAG] docker stats failed"
            LAST_DIAG_TIME=$SECONDS
        fi

        sleep $CHECK_INTERVAL
    done

    # tail プロセスを終了
    kill $TAIL_PID 2>/dev/null
    TAIL_PID=""

    # エラー検知されなかった場合は終了コードを取得
    if [ $ERROR_DETECTED -eq 0 ]; then
        wait $DOCKER_PID
        YTDLP_EXIT_CODE=$?
    else
        YTDLP_EXIT_CODE=1
    fi

    # 成功したら抜ける
    if [ ${YTDLP_EXIT_CODE} -eq 0 ]; then
        break
    fi

    # タイムアウトの場合は即終了
    if [ ${YTDLP_EXIT_CODE} -eq 124 ]; then
        echo "ERROR: yt-dlp timed out after 24 hours"
        exit 124
    fi

    # 最後の試行でなければリトライ
    # (コンテナを作り直すため、VPN は自動的に別サーバーへ張り直される)
    if [ $i -lt $MAX_RETRY ]; then
        echo "Download failed (exit code: $YTDLP_EXIT_CODE, error_detected: $ERROR_DETECTED). Recreating container and retrying..."
        sleep 10
    fi
done

# ダウンロード完了

# 欠落フラグメント数を可視化（「成功だが欠落あり」の検知用。LOG_FILEはattempt毎初期化のため最終attempt分）
SKIPPED_FRAGMENTS=$(grep -c 'Skipping fragment' "$LOG_FILE" 2>/dev/null)
SKIPPED_FRAGMENTS=${SKIPPED_FRAGMENTS:-0}
echo "Skipped fragments in final attempt: ${SKIPPED_FRAGMENTS}"

if [ ${YTDLP_EXIT_CODE} -ne 0 ]; then
    exit ${YTDLP_EXIT_CODE}
fi

echo "[SUCCESS] yt-dlp download completed successfully (attempt $i/$MAX_RETRY)"

jq 'del(.formats, .automatic_captions)' ${WORK_DIR}/${CONTENT_ID}.info.json | sponge ${WORK_DIR}/${CONTENT_ID}.info.json

# ---------------------------------------------------------------------------
# チャンネル情報の取得
#
# アバター/バナーの取得も VPN 経由で行うためコンテナで実行する。
# ホストの素のIP (Google所有IP) から YouTube を叩くと、そのIPが
# Bot 判定に使われる恐れがあるため。
# ---------------------------------------------------------------------------
echo "=== Fetching channel info ==="
CHANNEL_META="${YTLC_DIR}/out/channel_meta.env"
rm -f "$CHANNEL_META"

INFO_CONTAINER_NAME="${CONTAINER_PREFIX}-info"
docker rm -f "$INFO_CONTAINER_NAME" > /dev/null 2>&1 || true
docker run "${DOCKER_RUN_ARGS[@]}" --name "$INFO_CONTAINER_NAME" \
    "${RECORDER_IMAGE}" \
    bash "${YTLC_DIR}/scripts/record_in_vpn.sh" channelinfo || true

avatar_url='null'
avatar_image_ext='null'
channel_banner_url='null'
channel_banner_image_ext='null'

if [ -f "$CHANNEL_META" ]; then
    # shellcheck disable=SC1090
    source "$CHANNEL_META"
else
    echo 'Failed to fetch channel info. Continuing with null values.'
fi

echo 'Writing info.json...'
jq --arg avatar_url $avatar_url '. + {"avatar_url": $avatar_url}' ${WORK_DIR}/${CONTENT_ID}.info.json | sponge ${WORK_DIR}/${CONTENT_ID}.info.json
jq --arg avatar_image_ext $avatar_image_ext '. + {"avatar_image_ext": $avatar_image_ext}' ${WORK_DIR}/${CONTENT_ID}.info.json | sponge ${WORK_DIR}/${CONTENT_ID}.info.json
jq --arg channel_banner_url $channel_banner_url '. + {"channel_banner_url": $channel_banner_url}' ${WORK_DIR}/${CONTENT_ID}.info.json | sponge ${WORK_DIR}/${CONTENT_ID}.info.json
jq --arg channel_banner_image_ext $channel_banner_image_ext '. + {"channel_banner_image_ext": $channel_banner_image_ext}' ${WORK_DIR}/${CONTENT_ID}.info.json | sponge ${WORK_DIR}/${CONTENT_ID}.info.json

echo 'Uploading s3...'

# upload to s3
aws s3 cp --endpoint-url ${WASABI_S3_URL} ${WORK_DIR} s3://${WASABI_BUCKET_NAME}/${CONTENT_ID}/ --recursive

# if failed s3 upload then upload to gcs
if [ $? -ne 0 ]; then
    # compress videos
    zip ${CONTENT_ID}.zip ${WORK_DIR}/*

    # move to bucket
    mv ${CONTENT_ID}.zip ${OUTPUT_PATH}
fi
