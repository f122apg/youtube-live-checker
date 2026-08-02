#!/bin/bash

###############################################################################
# VPN Gate Library (コンテナ版)
#
# このスクリプトは other scripts から source される想定
# Usage: source vpngate_lib_container.sh
###############################################################################

# Log functions
vpn_log_info() {
    echo "[VPN-INFO] $1"
}

vpn_log_warn() {
    echo "[VPN-WARN] $1"
}

vpn_log_error() {
    echo "[VPN-ERROR] $1"
}

# Variables to hold VPN connection state
VPN_WORK_DIR=""
VPN_PID=""
VPN_CONNECTED=0
VPN_IP=""
VPN_SERVER_IP=""

###############################################################################
# Helper function to check if tun0 exists
###############################################################################
_check_tun0_exists() {
    ip addr show tun0 &>/dev/null 2>&1
    return $?
}

###############################################################################
# Helper function to get tun0 IP
###############################################################################
_get_tun0_ip() {
    ip addr show tun0 2>/dev/null | grep "inet " | awk '{print $2}' | cut -d'/' -f1
}

###############################################################################
# 接続確立後のDNS設定
#
# ホスト版は update-resolv-conf やフォールバックが書いた内容を退避・復元して
# いたが、コンテナの /etc/resolv.conf は使い捨てなので固定するだけでよい。
# (周期的なDNS解決失敗=Errno -9 対策として固定DNSを使う)
###############################################################################
_configure_container_dns() {
    vpn_log_info "Pinning DNS servers to 8.8.8.8 / 1.1.1.1 for stability..."
    cat > /etc/resolv.conf << 'EOF'
nameserver 8.8.8.8
nameserver 1.1.1.1
EOF
    getent hosts www.youtube.com >/dev/null 2>&1 \
        && vpn_log_info "DNS resolution OK." \
        || vpn_log_warn "DNS resolution check failed."
}

###############################################################################
# Kill switch
#
# tun0 以外へ出ていく通信を遮断する。VPN が切れた状態で yt-dlp が
# コンテナの素のIP (= GCP所有のIP) から YouTube を叩くのを防ぐのが目的。
#
# 適用に失敗しても録画自体は継続する (kill switch 無しの現行と同じ状態になるだけで、
# ここで録画を止める方が損失が大きいため)。
###############################################################################
vpn_enable_killswitch() {
    if [ -z "$VPN_SERVER_IP" ]; then
        vpn_log_warn "VPN server IP is unknown. Skipping kill switch."
        return 1
    fi

    vpn_log_info "Enabling kill switch (allow only tun0 and the VPN server $VPN_SERVER_IP)..."

    {
        iptables -F OUTPUT &&
        iptables -A OUTPUT -o lo -j ACCEPT &&
        iptables -A OUTPUT -o tun0 -j ACCEPT &&
        iptables -A OUTPUT -d "$VPN_SERVER_IP" -j ACCEPT &&
        iptables -A OUTPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT &&
        iptables -P OUTPUT DROP
    } 2>&1 || {
        vpn_log_warn "Failed to apply kill switch. Continuing without it."
        iptables -P OUTPUT ACCEPT 2>/dev/null || true
        return 1
    }

    vpn_log_info "Kill switch enabled."
    return 0
}

vpn_disable_killswitch() {
    iptables -P OUTPUT ACCEPT 2>/dev/null || true
    iptables -F OUTPUT 2>/dev/null || true
}

###############################################################################
# Function to connect to VPN
# 引数なしで呼ぶと VPN_COUNTRIES の順にサーバーを探す
# Return value: 0=success, 1=failure
###############################################################################
vpn_connect() {
    # Check if already connected
    if [ $VPN_CONNECTED -eq 1 ]; then
        vpn_log_warn "Already connected to VPN."
        return 0
    fi

    # Check for root privileges
    if [[ $EUID -ne 0 ]]; then
        vpn_log_error "Root privileges are required for VPN connection."
        return 1
    fi

    # Check for OpenVPN
    if ! command -v openvpn &> /dev/null; then
        vpn_log_error "OpenVPN is not installed."
        return 1
    fi

    # TUN デバイスの確認 (docker run に --device=/dev/net/tun が渡っていないと落ちる)
    if [ ! -c /dev/net/tun ]; then
        vpn_log_error "/dev/net/tun is not available. Did you pass --device=/dev/net/tun to docker run?"
        return 1
    fi

    vpn_log_info "=========================================="
    vpn_log_info "Starting VPN connection."
    vpn_log_info "=========================================="

    # Create working directory
    VPN_WORK_DIR="/tmp/vpngate_$$"
    mkdir -p "$VPN_WORK_DIR"

    vpn_log_info "Downloading server list..."
    if ! curl -s "http://www.vpngate.net/api/iphone/" -o "$VPN_WORK_DIR/vpngate.csv"; then
        vpn_log_error "Failed to download server list."
        rm -rf "$VPN_WORK_DIR"
        return 1
    fi

    tail -n +3 "$VPN_WORK_DIR/vpngate.csv" > "$VPN_WORK_DIR/vpngate_clean.csv"

    vpn_log_info "Searching for suitable servers..."

    # Local function to find the best servers
    _find_best_servers() {
        local country_code=$1
        local min_bps="${VPN_MIN_ADVERTISED_BPS:-10000000}"
        # ソートキーにSpeed($5)を先頭で出力し(降順ソート)、cutで剥がして
        # 従来通り Score|IP|Country|OVPN_DATA|Operator の5フィールド形式を維持する
        awk -F',' -v country="$country_code" -v min_bps="$min_bps" '
        BEGIN { OFS="|" }
        {
            if (NF < 15) next
            if ($7 != country) next
            if ($13 ~ /Academic Use Only/) next
            if (length($15) < 100) next
            if ($5 < min_bps) next
            print $5, $3, $2, $6, $15, $13
        }' "$VPN_WORK_DIR/vpngate_clean.csv" | sort -t'|' -k1 -nr | head -n 15 | shuf | cut -d'|' -f2-
    }

    ALL_CANDIDATE_SERVERS=""

    # 国の優先順（先頭の国から順に候補リストを構築し、前の国の候補を使い切ったら次の国へ）
    VPN_COUNTRIES="${VPN_COUNTRIES:-JP KR US}"
    for country in $VPN_COUNTRIES; do
        vpn_log_info "Collecting servers from $country..."
        COUNTRY_SERVERS=$(_find_best_servers "$country")
        if [ -n "$COUNTRY_SERVERS" ]; then
            if [ -n "$ALL_CANDIDATE_SERVERS" ]; then
                ALL_CANDIDATE_SERVERS=$(printf "%s\n%s" "$ALL_CANDIDATE_SERVERS" "$COUNTRY_SERVERS")
            else
                ALL_CANDIDATE_SERVERS="$COUNTRY_SERVERS"
            fi
        else
            vpn_log_warn "No $country servers found."
        fi
    done

    if [ -z "$ALL_CANDIDATE_SERVERS" ]; then
        vpn_log_error "Could not find any suitable servers from: $VPN_COUNTRIES."
        rm -rf "$VPN_WORK_DIR"
        return 1
    fi

    SERVER_INDEX=0
    VPN_MIN_THROUGHPUT_KIBPS="${VPN_MIN_THROUGHPUT_KIBPS:-1024}"

    # 全候補が実測スループットゲートで不合格だった場合のフォールバック用
    # （最良だったサーバーの情報を保持しておき、最後に再接続して警告付きで続行する）
    BEST_THROUGHPUT_KIBPS=-1
    BEST_CANDIDATE_SCORE=""
    BEST_CANDIDATE_IP=""
    BEST_CANDIDATE_COUNTRY=""
    BEST_CANDIDATE_OVPN_DATA=""
    BEST_CANDIDATE_OPERATOR=""

    # Use process substitution instead of pipe to avoid subshell
    while IFS='|' read -r SCORE IP COUNTRY OVPN_DATA OPERATOR; do
        SERVER_INDEX=$((SERVER_INDEX + 1))
        vpn_log_info "------------------------------------------"
        vpn_log_info "Attempting to connect to server #$SERVER_INDEX..."

        vpn_log_info "  Country: $COUNTRY"
        vpn_log_info "  IP: $IP"
        vpn_log_info "  Score: $SCORE"
        vpn_log_info "  Operator: $OPERATOR"

        if ! _write_ovpn_config "$OVPN_DATA"; then
            vpn_log_error "Failed to build OpenVPN config for server $IP. Trying next server."
            continue
        fi

        vpn_log_info "Starting OpenVPN connection for $IP..."
        openvpn --config "$VPN_WORK_DIR/server.ovpn" --daemon --log "$VPN_WORK_DIR/openvpn.log" \
                --writepid "$VPN_WORK_DIR/openvpn.pid"

        # Read PID file
        sleep 2
        if [ -f "$VPN_WORK_DIR/openvpn.pid" ]; then
            VPN_PID=$(cat "$VPN_WORK_DIR/openvpn.pid")
        else
            VPN_PID=""
        fi

        # Early error detection from log
        if [ -f "$VPN_WORK_DIR/openvpn.log" ]; then
            if grep -q "Cannot open TUN/TAP" "$VPN_WORK_DIR/openvpn.log"; then
                vpn_log_error "FATAL: Cannot access TUN/TAP device for server $IP"
                vpn_log_error "docker run に --cap-add=NET_ADMIN --device=/dev/net/tun が渡っているか確認すること"
                rm -rf "$VPN_WORK_DIR"
                return 1
            fi

            if grep -q "AUTH_FAILED" "$VPN_WORK_DIR/openvpn.log"; then
                vpn_log_warn "Server $IP authentication failed. Trying next server."
                _kill_openvpn
                continue
            fi
        fi

        # Waiting for connection
        vpn_log_info "Waiting for connection to be established..."
        TIMEOUT=30
        COUNTER=0

        while [ $COUNTER -lt $TIMEOUT ]; do
            if [ -f "$VPN_WORK_DIR/openvpn.log" ]; then
                if grep -q "AUTH_FAILED" "$VPN_WORK_DIR/openvpn.log"; then
                    vpn_log_warn "Authentication failed. Trying next server."
                    _kill_openvpn
                    break
                fi
            fi

            if _check_tun0_exists; then
                VPN_CONNECTED=1
                VPN_SERVER_IP="$IP"
                vpn_log_info "VPN connection established successfully with $IP!"

                VPN_IP=$(_get_tun0_ip)
                vpn_log_info "VPN Interface: tun0"
                vpn_log_info "Assigned IP: $VPN_IP"

                # ルーティングは VPN が push する内容をそのまま使う (route-nopull しない)。
                # このコンテナには VPN を迂回させたい通信が存在しないため。
                sleep 2
                vpn_log_info "Current routing table:"
                ip route
                _configure_container_dns

                # --- 接続後の実測スループットゲート ---
                vpn_log_info "Measuring actual throughput (min required: ${VPN_MIN_THROUGHPUT_KIBPS} KiB/s)..."
                MEASURED_BPS=$(curl -o /dev/null -s --max-time 15 -w '%{speed_download}' "https://speed.cloudflare.com/__down?bytes=20000000" 2>/dev/null)
                if ! [[ "$MEASURED_BPS" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
                    MEASURED_BPS=0
                fi
                MEASURED_KIBPS=$(awk -v b="$MEASURED_BPS" 'BEGIN { printf "%d", b / 1024 }')
                vpn_log_info "Measured throughput for $IP: ${MEASURED_KIBPS} KiB/s"

                # このサーバーがこれまでで最良なら記録しておく（全滅時のフォールバック用）
                if [ "$MEASURED_KIBPS" -gt "$BEST_THROUGHPUT_KIBPS" ]; then
                    BEST_THROUGHPUT_KIBPS=$MEASURED_KIBPS
                    BEST_CANDIDATE_SCORE="$SCORE"
                    BEST_CANDIDATE_IP="$IP"
                    BEST_CANDIDATE_COUNTRY="$COUNTRY"
                    BEST_CANDIDATE_OVPN_DATA="$OVPN_DATA"
                    BEST_CANDIDATE_OPERATOR="$OPERATOR"
                fi

                if [ "$MEASURED_KIBPS" -lt "$VPN_MIN_THROUGHPUT_KIBPS" ]; then
                    vpn_log_warn "Throughput ${MEASURED_KIBPS} KiB/s is below threshold (${VPN_MIN_THROUGHPUT_KIBPS} KiB/s) for $IP. Disconnecting and trying next server."
                    vpn_disconnect
                    # vpn_disconnectは$VPN_WORK_DIRを削除するため、後続候補の接続処理のために再作成する
                    mkdir -p "$VPN_WORK_DIR"
                    # ここはTIMEOUT待ちの内側whileの中なので、continue 2で候補ループ側を次に進める
                    continue 2
                fi

                # Display external IP
                EXTERNAL_IP=$(curl -s --max-time 10 https://api.ipify.org 2>/dev/null || echo "Failed to retrieve")
                vpn_log_info "External IP: $EXTERNAL_IP"

                vpn_log_info "=========================================="
                # Successfully connected, return from function
                return 0
            fi

            sleep 1
            COUNTER=$((COUNTER + 1))

            if [ $((COUNTER % 5)) -eq 0 ]; then
                vpn_log_info "Waiting... ($COUNTER/$TIMEOUT sec)"
            fi
        done

        vpn_log_error "VPN connection to $IP timed out."

        if [ -f "$VPN_WORK_DIR/openvpn.log" ]; then
            vpn_log_info "Last log entries:"
            tail -n 10 "$VPN_WORK_DIR/openvpn.log" 2>/dev/null || true
        fi

        _kill_openvpn
    done < <(echo "$ALL_CANDIDATE_SERVERS")

    # 全候補が実測スループットゲート不合格だった場合、最良だったサーバーに
    # 再接続して警告付きで続行する（録画自体は必ず実施するため接続を諦めない）
    if [ -n "$BEST_CANDIDATE_IP" ]; then
        vpn_log_warn "No server met the throughput threshold (${VPN_MIN_THROUGHPUT_KIBPS} KiB/s). Reconnecting to the best-measured server ($BEST_CANDIDATE_IP, ${BEST_THROUGHPUT_KIBPS} KiB/s, score=$BEST_CANDIDATE_SCORE) and continuing anyway."

        if ! _write_ovpn_config "$BEST_CANDIDATE_OVPN_DATA"; then
            vpn_log_error "Failed to build OpenVPN config for fallback server $BEST_CANDIDATE_IP."
            rm -rf "$VPN_WORK_DIR"
            return 1
        fi

        vpn_log_info "Starting OpenVPN connection for fallback server $BEST_CANDIDATE_IP..."
        openvpn --config "$VPN_WORK_DIR/server.ovpn" --daemon --log "$VPN_WORK_DIR/openvpn.log" \
                --writepid "$VPN_WORK_DIR/openvpn.pid"

        sleep 2
        if [ -f "$VPN_WORK_DIR/openvpn.pid" ]; then
            VPN_PID=$(cat "$VPN_WORK_DIR/openvpn.pid")
        else
            VPN_PID=""
        fi

        TIMEOUT=30
        COUNTER=0
        while [ $COUNTER -lt $TIMEOUT ]; do
            if _check_tun0_exists; then
                VPN_CONNECTED=1
                VPN_SERVER_IP="$BEST_CANDIDATE_IP"
                vpn_log_warn "Reconnected to fallback server $BEST_CANDIDATE_IP (measured ${BEST_THROUGHPUT_KIBPS} KiB/s, below the ${VPN_MIN_THROUGHPUT_KIBPS} KiB/s threshold). Continuing recording anyway."

                VPN_IP=$(_get_tun0_ip)
                vpn_log_info "VPN Interface: tun0"
                vpn_log_info "Assigned IP: $VPN_IP"

                sleep 2
                ip route
                _configure_container_dns

                EXTERNAL_IP=$(curl -s --max-time 10 https://api.ipify.org 2>/dev/null || echo "Failed to retrieve")
                vpn_log_info "External IP: $EXTERNAL_IP"

                vpn_log_info "=========================================="
                return 0
            fi

            sleep 1
            COUNTER=$((COUNTER + 1))
        done

        vpn_log_error "Fallback reconnection to $BEST_CANDIDATE_IP also failed."
        _kill_openvpn
    fi

    vpn_log_error "Failed to connect to any of the top servers."
    rm -rf "$VPN_WORK_DIR"
    return 1
}

###############################################################################
# OpenVPN の設定ファイルを組み立てる
###############################################################################
_write_ovpn_config() {
    local ovpn_data="$1"

    if ! echo "$ovpn_data" | tr -d '\n\r ' | base64 -d > "$VPN_WORK_DIR/server.ovpn" 2>/dev/null; then
        return 1
    fi

    if [ ! -s "$VPN_WORK_DIR/server.ovpn" ]; then
        return 1
    fi

    echo "" >> "$VPN_WORK_DIR/server.ovpn"

    # CRITICAL FIX: Add cipher compatibility for VPN Gate servers
    # OpenVPN 2.6+ defaults to modern ciphers, but VPN Gate uses AES-128-CBC
    echo "# Cipher compatibility for VPN Gate (OpenVPN 2.6+ fix)" >> "$VPN_WORK_DIR/server.ovpn"
    echo "data-ciphers AES-128-CBC:AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305" >> "$VPN_WORK_DIR/server.ovpn"
    echo "data-ciphers-fallback AES-128-CBC" >> "$VPN_WORK_DIR/server.ovpn"

    # ホスト版にあった route-nopull は付けない。
    # コンテナ内には VPN を迂回させたい通信が無いため、push されたルートを受け入れる。

    return 0
}

_kill_openvpn() {
    if [ -n "$VPN_PID" ] && kill -0 "$VPN_PID" 2>/dev/null; then
        kill "$VPN_PID" 2>/dev/null || true
    else
        pkill -x openvpn 2>/dev/null || true
    fi
    VPN_PID=""
}

###############################################################################
# Function to disconnect VPN
#
# ホスト版はルートとDNSを元の状態へ復元していたが、コンテナは使い捨てなので
# OpenVPN を止めるだけでよい (スループットゲートで次候補へ移るときに使う)。
# Return value: 0=success, 1=failure
###############################################################################
vpn_disconnect() {
    if [ $VPN_CONNECTED -eq 0 ]; then
        vpn_log_warn "VPN is not connected."
        return 0
    fi

    vpn_log_info "=========================================="
    vpn_log_info "Disconnecting VPN."
    vpn_log_info "=========================================="

    # kill switch を張ったままだと切断後に何も通信できなくなるため先に解除する
    vpn_disable_killswitch

    _kill_openvpn
    sleep 2

    # Confirm disconnection
    TIMEOUT=10
    COUNTER=0
    while [ $COUNTER -lt $TIMEOUT ]; do
        if ! _check_tun0_exists; then
            vpn_log_info "VPN connection has been disconnected."
            VPN_CONNECTED=0
            VPN_SERVER_IP=""

            if [ -n "$VPN_WORK_DIR" ] && [ -d "$VPN_WORK_DIR" ]; then
                rm -rf "$VPN_WORK_DIR"
            fi

            vpn_log_info "=========================================="
            return 0
        fi
        sleep 1
        COUNTER=$((COUNTER + 1))
    done

    vpn_log_warn "Could not confirm VPN disconnection."
    VPN_CONNECTED=0
    VPN_SERVER_IP=""

    if [ -n "$VPN_WORK_DIR" ] && [ -d "$VPN_WORK_DIR" ]; then
        rm -rf "$VPN_WORK_DIR"
    fi

    return 1
}
