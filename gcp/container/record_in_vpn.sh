#!/bin/bash

###############################################################################
# コンテナ内で実行される録画スクリプト
#
# ホスト側の job.sh から docker run 経由で起動される。
# このスクリプトが動く network namespace はコンテナ専用なので、VPN が
# default route を奪ってもホスト側 (GCP API / gcsfuse / Batch agent) には
# 一切影響しない。
#
# Usage:
#   record_in_vpn.sh download      配信のダウンロード (1試行ぶん)
#   record_in_vpn.sh channelinfo   チャンネルのアバター/バナー取得
#
# このスクリプトはイメージに焼かれておらず、job.sh が /mnt/share から
# ${YTLC_DIR}/scripts へ配置したものを bind mount 経由で実行する。
# GCS 上のファイルを差し替えるだけで反映され、イメージの再ビルドは不要。
#
# 渡される環境変数:
#   CONTENT_ID                必須。YouTube のコンテンツID
#   YTLC_DIR                  既定 /opt/ytlc。yt-dlp とスクリプトの置き場
#   YTDLP_TIMEOUT_SECONDS     既定 86400 (24時間)
#   VPN_COUNTRIES             既定 "JP KR US"
#   VPN_MIN_THROUGHPUT_KIBPS  既定 1024
###############################################################################

set -u

MODE="${1:-download}"

YTLC_DIR="${YTLC_DIR:-/opt/ytlc}"
YTDLP="${YTLC_DIR}/bin/yt-dlp"
OUT_DIR="${YTLC_DIR}/out"
WORK_DIR=/work

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "${SCRIPT_DIR}/vpngate_lib_container.sh"

if [ -z "${CONTENT_ID:-}" ]; then
    echo "ERROR: CONTENT_ID is not set."
    exit 2
fi

if [ ! -x "$YTDLP" ]; then
    echo "ERROR: yt-dlp not found at ${YTDLP}. The host is responsible for downloading it."
    exit 2
fi

mkdir -p "$OUT_DIR" "$WORK_DIR" ~/.config/yt-dlp

###############################################################################
# yt-dlp の設定ファイル
###############################################################################
_write_download_config() {
    cat <<EOF > ~/.config/yt-dlp/config
# EJS Setup - Enable Deno runtime
--js-runtimes deno

# Remote components - Download EJS scripts from GitHub
--remote-components ejs:github

# Change language
--add-header 'Accept-Language:ja-JP'
--extractor-args "youtube:lang=ja;youtube:player-client=default,-ios"
--parse-metadata " Japanese: %(meta_language)s"

# Download base directory
--paths ${WORK_DIR}

# Save file path
--output '%(id)s.%(ext)s'

# With thumbnail image
--write-all-thumbnails
--output 'thumbnail:%(id)s_thumbnail.%(ext)s'

# Write metadata
--write-info-json

# Live download
--live-from-start

# Audio Format
--audio-quality 0

# Video Format
--format 'bestvideo*+bestaudio/best'
EOF
}

_write_channelinfo_config() {
    cat <<EOF > ~/.config/yt-dlp/config
# EJS Setup - Enable Deno runtime
--js-runtimes deno

# Remote components - Download EJS scripts from GitHub
--remote-components ejs:github

# Change language
--add-header 'Accept-Language:ja-JP'
--extractor-args "youtube:lang=ja"
--parse-metadata " Japanese: %(meta_language)s"

# Download base directory
--paths ${WORK_DIR}
EOF
}

###############################################################################
# VPN 接続 (失敗したら録画を始めない)
###############################################################################
_connect() {
    if ! vpn_connect; then
        echo "ERROR: Failed to establish VPN connection. Aborting to avoid accessing YouTube from the bare GCP IP."
        return 1
    fi

    # VPN が切れた状態で素のIP (GCP所有IP) から YouTube を叩かないようにする
    vpn_enable_killswitch || true
    return 0
}

###############################################################################
# download モード
###############################################################################
_run_download() {
    _write_download_config
    _connect || return 1

    echo "=== Starting yt-dlp (content: ${CONTENT_ID}) ==="

    # 24時間でタイムアウト、60秒猶予後に強制終了
    timeout -s SIGTERM -k 60 "${YTDLP_TIMEOUT_SECONDS:-86400}" \
        "$YTDLP" -v -4 \
        --socket-timeout 300 \
        --retries 10 \
        --fragment-retries 10 \
        -- "${CONTENT_ID}"

    return $?
}

###############################################################################
# channelinfo モード
#
# アバター/バナーの取得も VPN 経由で行う。ホストの素のIP (Google所有IP) から
# YouTube を叩くと、そのIPが Bot 判定に使われる恐れがあるため。
# 取得結果は ${OUT_DIR}/channel_meta.env に書き出し、ホスト側 job.sh が source する。
###############################################################################
_run_channelinfo() {
    _write_channelinfo_config
    _connect || return 1

    avatar_url='null'
    avatar_image_ext='null'
    channel_banner_url='null'
    channel_banner_image_ext='null'

    channel_url=`"$YTDLP" --skip-download --exec "pre_process:python3 -c 'import urllib.parse; import sys; print(\"https://\" + urllib.parse.quote(sys.argv[1].replace(\"https://\", \"\")))' %(uploader_url)q" -q -- ${CONTENT_ID}`
    if [ $? -eq 0 ] && [[ "$channel_url" == *"https://"* ]]; then
        avatar_url=`"$YTDLP" --skip-download --exec "pre_process:python3 -c 'import urllib.parse; import sys; print(\"https://\" + urllib.parse.quote(sys.argv[1].replace(\"https://\", \"\")))' %(uploader_url)q | xargs ${YTDLP} -I0 -O \"playlist:%%(thumbnails.-1.url)s\"" -q -- ${CONTENT_ID}`
        if [ $? -eq 0 ] && [[ "$avatar_url" == *"https://"* ]]; then
            echo "Found avatar url: $avatar_url"
            echo 'Downloading avatar image.'

            avatar_image_ext=$(curl -sI $avatar_url | grep -i "content-type:" | awk '{print $2}' | tr -d '\r' | sed 's#image/##g')
            curl -L $avatar_url -o ${WORK_DIR}/avatar.$avatar_image_ext
        else
            echo 'Not found avatar url.'
            avatar_url='null'
        fi

        channel_banner_url=`"$YTDLP" --skip-download --exec "pre_process:python3 -c 'import urllib.parse; import sys; print(\"https://\" + urllib.parse.quote(sys.argv[1].replace(\"https://\", \"\")))' %(uploader_url)q | xargs ${YTDLP} -I0 -O \"playlist:%%(thumbnails.-3.url)s\"" -q -- ${CONTENT_ID}`
        if [ $? -eq 0 ] && [[ "$channel_banner_url" == *"https://"* ]]; then
            echo "Found channel banner url: $channel_banner_url"
            echo 'Downloading channel banner.'

            channel_banner_image_ext=$(curl -sI $channel_banner_url | grep -i "content-type:" | awk '{print $2}' | tr -d '\r' | sed 's#image/##g')
            curl -L $channel_banner_url -o ${WORK_DIR}/banner.$channel_banner_image_ext
        else
            echo 'Not found channel banner url.'
            channel_banner_url='null'
        fi
    fi

    # ホスト側 job.sh が source して info.json への追記に使う
    cat <<EOF > "${OUT_DIR}/channel_meta.env"
avatar_url='${avatar_url}'
avatar_image_ext='${avatar_image_ext}'
channel_banner_url='${channel_banner_url}'
channel_banner_image_ext='${channel_banner_image_ext}'
EOF

    echo "Wrote channel metadata to ${OUT_DIR}/channel_meta.env"
    return 0
}

###############################################################################
# main
###############################################################################
case "$MODE" in
    download)
        _run_download
        EXIT_CODE=$?
        ;;
    channelinfo)
        _run_channelinfo
        EXIT_CODE=$?
        ;;
    *)
        echo "ERROR: Unknown mode '${MODE}'. Use 'download' or 'channelinfo'."
        EXIT_CODE=2
        ;;
esac

# コンテナはこの後破棄されるので厳密な後始末は不要だが、
# kill switch を残したまま終了するとログ送信等が詰まるため解除しておく
vpn_disable_killswitch

exit $EXIT_CODE
