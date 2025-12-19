#!/bin/bash

###############################################################################
# VPN Gate Library for GCP Batch (Routing Fixed Version)
#
# This script is intended to be sourced by other scripts
# Usage: source vpngate_lib.sh
#
# FIXES for GCP Batch:
# - Added cipher compatibility for OpenVPN 2.6+ (AES-128-CBC support)
# - GCP service endpoints routing exclusion (Cloud Logging, Metadata, etc.)
# - Prevents VPN from hijacking all traffic
###############################################################################

# # dnsmasq インストール
# apt-get update && apt-get install -y dnsmasq

# # dnsmasq設定（IPv4のみ）
# cat > /etc/dnsmasq.d/googleapis.conf << 'EOF'
# # すべての *.googleapis.com をIPv4の199.36.153.4に解決
# address=/googleapis.com/199.36.153.4

# # 特定のドメインも明示
# address=/batch.googleapis.com/199.36.153.4
# address=/logging.googleapis.com/199.36.153.4
# address=/logging-alv.googleapis.com/199.36.153.4
# address=/storage.googleapis.com/199.36.153.4
# address=/containerregistry.googleapis.com/199.36.153.4
# address=/artifactregistry.googleapis.com/199.36.153.4
# address=/file.googleapis.com/199.36.153.4
# address=/compute.googleapis.com/199.36.153.4
# address=/iam.googleapis.com/199.36.153.4
# address=/workflows.googleapis.com/199.36.153.4

# # IPv6クエリには応答しない
# filter-aaaa
# EOF

# # resolv.conf バックアップ
# cp /etc/resolv.conf /etc/resolv.conf.backup

# # dnsmasq起動
# systemctl restart dnsmasq 2>/dev/null || dnsmasq

# # 自分自身をDNSサーバーに
# cat > /etc/resolv.conf << 'EOF'
# nameserver 127.0.0.1
# nameserver 8.8.8.8
# EOF

# # 確認
# echo "=== DNS Resolution Check ==="
# getent hosts logging.googleapis.com
# getent hosts storage.googleapis.com

# # IPv6が無効化されているか確認
# ip -6 addr show || echo "IPv6 is disabled or not available"


# echo "=== Starting setup ==="

# # Step 1: IPv6無効化を試みる
# echo "Attempting to disable IPv6..."
# sysctl -w net.ipv6.conf.all.disable_ipv6=1 2>/dev/null || echo "sysctl unavailable, using alternative method"
# sysctl -w net.ipv6.conf.default.disable_ipv6=1 2>/dev/null || true

# # Step 2: IPv4優先設定
# echo "Setting IPv4 preference..."
# cat > /etc/gai.conf << 'EOF'
# precedence ::ffff:0:0/96  100
# EOF

# # Step 3: /etc/hosts設定（すべてのエンドポイントを列挙）
# echo "Configuring /etc/hosts..."
# cat >> /etc/hosts << 'EOF'
# # Google APIs via restricted.googleapis.com (IPv4 only)
# 199.36.153.4 logging.googleapis.com
# 199.36.153.4 logging-alv.googleapis.com
# 199.36.153.4 cloudlogging.googleapis.com
# 199.36.153.4 storage.googleapis.com
# 199.36.153.4 storage-api.googleapis.com
# 199.36.153.4 batch.googleapis.com
# 199.36.153.4 workflows.googleapis.com
# 199.36.153.4 workflowexecutions.googleapis.com
# 199.36.153.4 iam.googleapis.com
# 199.36.153.4 iamcredentials.googleapis.com
# 199.36.153.4 compute.googleapis.com
# 199.36.153.4 cloudresourcemanager.googleapis.com
# 199.36.153.4 oauth2.googleapis.com
# 199.36.153.4 accounts.google.com
# 199.36.153.4 www.googleapis.com
# 199.36.153.4 monitoring.googleapis.com
# 199.36.153.4 pubsub.googleapis.com
# 199.36.153.4 containerregistry.googleapis.com
# 199.36.153.4 artifactregistry.googleapis.com
# 199.36.153.4 file.googleapis.com
# EOF

# # Step 4: DNS確認
# echo "=== DNS Resolution Check ==="
# echo "logging.googleapis.com:"
# getent hosts logging.googleapis.com | head -1
# echo "logging-alv.googleapis.com:"
# getent hosts logging-alv.googleapis.com | head -1
# echo "storage.googleapis.com:"
# getent hosts storage.googleapis.com | head -1

# # Step 5: ネットワーク状態確認
# echo "=== Network Status ==="
# ip -4 addr show | grep "inet " | head -3
# echo "IPv6 status:"
# ip -6 addr show 2>/dev/null | grep "inet6" | head -1 || echo "IPv6 disabled or not available"

# echo "=== Setup complete ==="


echo "=== Setting up VPN routing exceptions ==="

# デフォルトゲートウェイを取得
echo "Getting default gateways..."

echo "IPv6 default gateway: $original_gw_ipv6"

# IPv4の除外ルート
echo "Adding IPv4 routes..."
ip route add 169.254.169.254/32 via "$original_gw" 2>/dev/null || echo "169.254.169.254 route already exists"
ip route add 199.36.153.4/30 via "$original_gw" 2>/dev/null || echo "199.36.153.4/30 route already exists"
ip route add 199.36.153.8/30 via "$original_gw" 2>/dev/null || echo "199.36.153.8/30 route already exists"
ip route add 34.126.0.0/18 via "$original_gw" 2>/dev/null || echo "34.126.0.0/18 route already exists"



# 確認
echo "=== Current routing table (IPv4) ==="
ip route show | grep -E "199.36.153|34.126|169.254"

echo "=== Current routing table (IPv6) ==="
ip -6 route show 2>/dev/null | grep "2600:2d00" || echo "No IPv6 routes configured"

echo "=== VPN routing setup complete ==="


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

# GCP service IP ranges to exclude from VPN routing
GCP_METADATA_IP="169.254.169.254"
GCP_DNS_IP="169.254.169.254"

###############################################################################
# Helper function to check if tun0 exists
###############################################################################
_check_tun0_exists() {
    if command -v ip &> /dev/null; then
        ip addr show tun0 &>/dev/null 2>&1
        return $?
    elif command -v ifconfig &> /dev/null; then
        ifconfig tun0 &>/dev/null 2>&1
        return $?
    elif [ -d /sys/class/net/tun0 ]; then
        return 0
    else
        return 1
    fi
}

###############################################################################
# Helper function to get tun0 IP
###############################################################################
_get_tun0_ip() {
    if command -v ip &> /dev/null; then
        ip addr show tun0 2>/dev/null | grep "inet " | awk '{print $2}' | cut -d'/' -f1
    elif command -v ifconfig &> /dev/null; then
        ifconfig tun0 2>/dev/null | grep "inet " | awk '{print $2}'
    else
        echo "N/A"
    fi
}

###############################################################################
# Function to fix GCP routing after VPN connection
###############################################################################
_fix_gcp_routing() {
    local vpn_server_ip=$1
    local ssh_client_ip=$2
    shift 2
    local gcp_ip_ranges=($@)
    vpn_log_info "Configuring routing to preserve GCP services access and route traffic via VPN..."

    local original_gw
    local vpn_peer

    if ! command -v ip &> /dev/null; then
        vpn_log_warn "ip command not available. Cannot fix routing."
        return 1
    fi

    # Get the original default gateway
    original_gw=$(ip route show default | grep -v tun0 | grep -oP 'via \K[\d.]+' | head -n1)
    if [ -z "$original_gw" ]; then
        vpn_log_warn "Could not detect original gateway. GCP/VPN Server routes may not be set correctly."
    fi
    vpn_log_info "Original gateway: $original_gw"

    # Get the VPN peer IP from tun0. This will be our new gateway.
    vpn_peer=$(ip addr show tun0 2>/dev/null | grep "inet " | awk '{print $4}' | cut -d'/' -f1)
    if [ -z "$vpn_peer" ]; then
        vpn_log_warn "Could not detect VPN peer IP. Cannot set up VPN default route."
        return 1
    fi
    vpn_log_info "VPN peer IP (new gateway): $vpn_peer"

    # Save routing info for disconnect
    echo "$original_gw" > "$VPN_WORK_DIR/routing_info"
    echo "$vpn_server_ip" >> "$VPN_WORK_DIR/routing_info"
    echo "$vpn_peer" >> "$VPN_WORK_DIR/routing_info"
    echo "$ssh_client_ip" >> "$VPN_WORK_DIR/routing_info"
    printf "%s\n" "${gcp_ip_ranges[@]}" >> "$VPN_WORK_DIR/routing_info"

    # Add specific routes to bypass the VPN. This must be done BEFORE changing the default route.
    if [ -n "$original_gw" ]; then
        vpn_log_info "Adding routes to bypass VPN..."

        # 1. Route for the VPN server itself to prevent routing loops
        if [ -n "$vpn_server_ip" ]; then
            vpn_log_info "Adding route for VPN server $vpn_server_ip via $original_gw"
            ip route add "$vpn_server_ip" via "$original_gw" 2>/dev/null || true
        fi

        # 2. Route for the current SSH client to maintain connection
        if [ -n "$ssh_client_ip" ]; then
            vpn_log_info "Adding route for SSH client $ssh_client_ip via $original_gw"
            ip route add "$ssh_client_ip" via "$original_gw" 2>/dev/null || true
        fi

        # 3. Route for GCP DNS
        vpn_log_info "Adding route for GCP DNS server 169.254.169.254 via $original_gw"
        ip route add 169.254.169.254 via "$original_gw" 2>/dev/null || true

        # 4. Routes for GCP services
        vpn_log_info "Adding routes for GCP services via $original_gw"
        ip route add $GCP_METADATA_IP via $original_gw 2>/dev/nul34.126.0.0/18l || true
        for range in 34.126.0.0/18 199.36.153.8/30 199.36.153.4/30 \
                     142.250.0.0/15 172.217.0.0/16 216.58.192.0/19; do
            ip route add $range via $original_gw 2>/dev/null || true
        done
    fi

    local original_gw_ipv6
    original_gw_ipv6=$(ip -6 route show default 2>/dev/null | awk '/default/ {print $3}')

    # IPv6の除外ルート
    if [ -n "$original_gw_ipv6" ]; then
        echo "Adding IPv6 routes..."
        ip -6 route add 2600:2d00:0002:1000::/64 via "$original_gw_ipv6" 2>/dev/null || echo "2600:2d00:0002:1000::/64 route already exists"
    else
        echo "No IPv6 default gateway found, skipping IPv6 routes"
    fi

    # Change the default route to go through the VPN
    vpn_log_info "Changing default route to VPN..."
    ip route add 0.0.0.0/1 via "$vpn_peer" dev tun0
    ip route add 128.0.0.0/1 via "$vpn_peer" dev tun0

    vpn_log_info "Routing configuration complete."

    # Show current routing table
    vpn_log_info "Current routing table:"
    ip route

    # Update DNS
    vpn_log_info "Updating DNS settings..."
    if [ -f /etc/openvpn/update-resolv-conf ]; then
        /etc/openvpn/update-resolv-conf tun0 1500 0 "$VPN_IP" "$vpn_peer" init
    fi

    # Fallback DNS if update-resolv-conf fails or doesn't set a nameserver
    if ! grep -q "nameserver" /etc/resolv.conf; then
        vpn_log_warn "No nameservers found in /etc/resolv.conf. Adding Google DNS as a fallback."
        echo "nameserver 8.8.8.8" >> /etc/resolv.conf
        echo "nameserver 8.8.4.4" >> /etc/resolv.conf
        echo "fallback_dns_added" > "$VPN_WORK_DIR/dns_info"
    fi

    # Step 1: DNS設定（/etc/hosts）
    echo "Configuring DNS..."
cat >> /etc/hosts << 'EOF'
# Google APIs via restricted.googleapis.com (IPv4)
199.36.153.4 logging.googleapis.com logging-alv.googleapis.com
199.36.153.4 storage.googleapis.com
199.36.153.4 batch.googleapis.com
199.36.153.4 workflows.googleapis.com
199.36.153.4 iam.googleapis.com
199.36.153.4 compute.googleapis.com
199.36.153.4 containerregistry.googleapis.com
199.36.153.4 artifactregistry.googleapis.com

# Google APIs via restricted.googleapis.com (IPv6)
2600:2d00:0002:1000:: logging.googleapis.com logging-alv.googleapis.com
2600:2d00:0002:1000:: storage.googleapis.com
2600:2d00:0002:1000:: batch.googleapis.com
2600:2d00:0002:1000:: workflows.googleapis.com
2600:2d00:0002:1000:: iam.googleapis.com
2600:2d00:0002:1000:: compute.googleapis.com
2600:2d00:0002:1000:: containerregistry.googleapis.com
2600:2d00:0002:1000:: artifactregistry.googleapis.com
EOF

    getent hosts logging.googleapis.com

    return 0
}

###############################################################################
# Function to connect to VPN
# When called without arguments, it searches for servers in the order of US -> JP.
# Return value: 0=success, 1=failure
###############################################################################
vpn_connect() {
    # Check for jq
    if ! command -v jq &> /dev/null; then
        vpn_log_error "'jq' is not installed. Please install it to use this script."
        vpn_log_info "Install command: sudo apt update && sudo apt install -y jq"
        return 1
    fi

    # Check for root privileges
    if [[ $EUID -ne 0 ]]; then
        vpn_log_error "Root privileges are required for VPN connection."
        return 1
    fi

    # Check if already connected
    if [ $VPN_CONNECTED -eq 1 ]; then
        vpn_log_warn "Already connected to VPN."
        return 0
    fi

    # Check for OpenVPN
    if ! command -v openvpn &> /dev/null; then
        vpn_log_error "OpenVPN is not installed."
        vpn_log_info "Install command: sudo apt update && sudo apt install -y openvpn curl"
        return 1
    fi

    vpn_log_info "=========================================="
    vpn_log_info "Starting VPN connection."
    vpn_log_info "=========================================="

    # Detect SSH client IP BEFORE starting any VPN process
    vpn_log_info "--- Debugging SSH client IP detection ---"
    local ssh_client_ip=""

    # 1. Check for SSH_CLIENT variable
    vpn_log_info "Checking for \$SSH_CLIENT variable..."
    if [ -n "$SSH_CLIENT" ]; then
        vpn_log_info "\$SSH_CLIENT is set: '$SSH_CLIENT'"
        ssh_client_ip=$(echo "$SSH_CLIENT" | awk '{print $1}')
    else
        vpn_log_info "\$SSH_CLIENT is not set. Trying 'who -m'..."

        # 2. Check for 'who' command
        if ! command -v who &> /dev/null; then
            vpn_log_warn "'who' command not found."
        else
            vpn_log_info "'who' command found."
            local who_output
            who_output=$(who -m)
            vpn_log_info "Output of 'who -m': '$who_output'"

            # 3. Check if 'who -m' output contains parenthesis
            if echo "$who_output" | grep -q '('; then
                vpn_log_info "'who -m' output contains '('. Attempting to extract IP."
                ssh_client_ip=$(echo "$who_output" | sed 's/.*(\(.*\))/\1/')
            else
                vpn_log_info "'who -m' output does not contain '('. Cannot extract IP."
            fi
        fi
    fi
    vpn_log_info "--- End of SSH IP detection debug ---"

    if [ -n "$ssh_client_ip" ]; then
        vpn_log_info "Successfully detected SSH client IP: $ssh_client_ip. A route will be added to maintain the connection."
    fi

    # Create working directory
    VPN_WORK_DIR="/tmp/vpngate_$$"
    mkdir -p "$VPN_WORK_DIR"

    # Get GCP IP ranges
    vpn_log_info "Downloading GCP IP ranges..."
    gcp_ip_ranges=()
    if curl -s "https://www.gstatic.com/ipranges/cloud.json" -o "$VPN_WORK_DIR/cloud.json"; then
        mapfile -t gcp_ip_ranges < <(jq -r '.prefixes[] | .ipv4Prefix' "$VPN_WORK_DIR/cloud.json" | grep -v null)
        vpn_log_info "Successfully downloaded and parsed ${#gcp_ip_ranges[@]} GCP IP ranges."
    else
        vpn_log_warn "Failed to download GCP IP ranges. GCP services might be unreachable."
    fi

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
        awk -F',' -v country="$country_code" '
        BEGIN { OFS="|" }
        {
            if (NF < 15) next
            if ($7 != country) next
            if ($13 ~ /Academic Use Only/) next
            if (length($15) < 100) next
            print $3, $2, $6, $15, $13
        }' "$VPN_WORK_DIR/vpngate_clean.csv" | sort -t'|' -k1 -nr | head -n 15 | shuf
    }

    ALL_CANDIDATE_SERVERS=""

    # vpn_log_info "Collecting servers from US (United States)..."
    # US_SERVERS=$(_find_best_servers "US")
    # if [ -n "$US_SERVERS" ]; then
    #     ALL_CANDIDATE_SERVERS="$US_SERVERS"
    # else
    #     vpn_log_warn "No US servers found."
    # fi

    vpn_log_info "Collecting servers from JP (Japan)..."
    JP_SERVERS=$(_find_best_servers "JP")
    if [ -n "$JP_SERVERS" ]; then
        if [ -n "$ALL_CANDIDATE_SERVERS" ]; then
            ALL_CANDIDATE_SERVERS=$(printf "%s\n%s" "$ALL_CANDIDATE_SERVERS" "$JP_SERVERS")
        else
            ALL_CANDIDATE_SERVERS="$JP_SERVERS"
        fi
    else
        vpn_log_warn "No JP servers found."
    fi

    if [ -z "$ALL_CANDIDATE_SERVERS" ]; then
        vpn_log_error "Could not find any suitable servers from US or JP."
        rm -rf "$VPN_WORK_DIR"
        return 1
    fi

    # Use process substitution instead of pipe to avoid subshell
    SERVER_INDEX=0
    while IFS='|' read -r SCORE IP COUNTRY OVPN_DATA OPERATOR; do
        SERVER_INDEX=$((SERVER_INDEX + 1))
        vpn_log_info "------------------------------------------"
        vpn_log_info "Attempting to connect to server #$SERVER_INDEX..."

        vpn_log_info "  Country: $COUNTRY"
        vpn_log_info "  IP: $IP"
        vpn_log_info "  Score: $SCORE"
        vpn_log_info "  Operator: $OPERATOR"

        # Create OpenVPN configuration file
        if ! echo "$OVPN_DATA" | tr -d '\n\r ' | base64 -d > "$VPN_WORK_DIR/server.ovpn" 2>/dev/null; then
            vpn_log_error "Failed to decode base64 OpenVPN data for server $IP. Trying next server."
            continue
        fi

        # Verify the decoded file is valid
        if [ ! -s "$VPN_WORK_DIR/server.ovpn" ]; then
            vpn_log_error "Decoded OpenVPN config is empty for server $IP. Trying next server."
            continue
        fi

        echo "" >> "$VPN_WORK_DIR/server.ovpn"

        # CRITICAL FIX: Add cipher compatibility for VPN Gate servers
        # OpenVPN 2.6+ defaults to modern ciphers, but VPN Gate uses AES-128-CBC
        echo "# Cipher compatibility for VPN Gate (OpenVPN 2.6+ fix)" >> "$VPN_WORK_DIR/server.ovpn"
        echo "data-ciphers AES-128-CBC:AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305" >> "$VPN_WORK_DIR/server.ovpn"
        echo "data-ciphers-fallback AES-128-CBC" >> "$VPN_WORK_DIR/server.ovpn"

        # GCP BATCH FIX: Use custom routing script to preserve GCP services access
        echo "# GCP Batch routing fix" >> "$VPN_WORK_DIR/server.ovpn"
        echo "route-nopull" >> "$VPN_WORK_DIR/server.ovpn"  # Don't accept server's routing

        echo "script-security 2" >> "$VPN_WORK_DIR/server.ovpn"
        echo "up-restart" >> "$VPN_WORK_DIR/server.ovpn"
        # Use standard update-resolv-conf for DNS management, called manually later

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
                rm -rf "$VPN_WORK_DIR"
                return 1
            fi

            if grep -q "AUTH_FAILED" "$VPN_WORK_DIR/openvpn.log"; then
                vpn_log_warn "Server $IP authentication failed. Trying next server."
                if [ -n "$VPN_PID" ] && kill -0 "$VPN_PID" 2>/dev/null; then
                    kill "$VPN_PID" 2>/dev/null || true
                fi
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
                    if [ -n "$VPN_PID" ] && kill -0 "$VPN_PID" 2>/dev/null; then
                        kill "$VPN_PID" 2>/dev/null || true
                    fi
                    break
                fi
            fi

            if _check_tun0_exists; then
                VPN_CONNECTED=1
                vpn_log_info "VPN connection established successfully with $IP!"

                VPN_IP=$(_get_tun0_ip)
                vpn_log_info "VPN Interface: tun0"
                vpn_log_info "Assigned IP: $VPN_IP"

                # Fix routing for GCP services
                sleep 2
                _fix_gcp_routing "$IP" "$ssh_client_ip" "${gcp_ip_ranges[@]}"

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

        if [ -n "$VPN_PID" ] && kill -0 "$VPN_PID" 2>/dev/null; then
            kill "$VPN_PID" 2>/dev/null || true
        fi
    done < <(echo "$ALL_CANDIDATE_SERVERS")

    vpn_log_error "Failed to connect to any of the top servers."
    rm -rf "$VPN_WORK_DIR"
    return 1
}

###############################################################################
# Function to disconnect VPN
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

    # Restore DNS
    if [ -f "$VPN_WORK_DIR/dns_info" ]; then
        vpn_log_info "Restoring DNS settings (removing fallback Google DNS)..."
        sed -i '/nameserver 8.8.8.8/d' /etc/resolv.conf
        sed -i '/nameserver 8.8.4.4/d' /etc/resolv.conf
    elif [ -f /etc/openvpn/update-resolv-conf ]; then
        vpn_log_info "Restoring DNS settings via update-resolv-conf..."
        /etc/openvpn/update-resolv-conf
    fi

    # Restore routing
    if [ -f "$VPN_WORK_DIR/routing_info" ]; then
        vpn_log_info "Restoring network settings..."
        read -r original_gw < <(sed -n '1p' "$VPN_WORK_DIR/routing_info")
        read -r vpn_server_ip < <(sed -n '2p' "$VPN_WORK_DIR/routing_info")
        read -r vpn_peer < <(sed -n '3p' "$VPN_WORK_DIR/routing_info")
        read -r ssh_client_ip < <(sed -n '4p' "$VPN_WORK_DIR/routing_info")
        mapfile -t gcp_ip_ranges < <(sed '1,4d' "$VPN_WORK_DIR/routing_info")


        # Delete the routes we added
        ip route del 0.0.0.0/1 via "$vpn_peer" dev tun0 2>/dev/null || true
        ip route del 128.0.0.0/1 via "$vpn_peer" dev tun0 2>/dev/null || true

        if [ -n "$original_gw" ]; then
            if [ -n "$vpn_server_ip" ]; then
                ip route del "$vpn_server_ip" via "$original_gw" 2>/dev/null || true
            fi
            if [ -n "$ssh_client_ip" ]; then
                ip route del "$ssh_client_ip" via "$original_gw" 2>/dev/null || true
            fi
            ip route del 169.254.169.254 via "$original_gw" 2>/dev/null || true
            for range in "${gcp_ip_ranges[@]}"; do
                ip route del "$range" via "$original_gw" 2>/dev/null || true
            done
        fi
        vpn_log_info "Network settings restored."
    fi

    # Stop OpenVPN process
    if [ -n "$VPN_PID" ] && kill -0 "$VPN_PID" 2>/dev/null; then
        vpn_log_info "Stopping OpenVPN process (PID: $VPN_PID)..."
        kill "$VPN_PID" 2>/dev/null || true
        sleep 2
    else
        if pgrep -x "openvpn" > /dev/null 2>&1; then
            vpn_log_info "Stopping OpenVPN process..."
            killall openvpn 2>/dev/null || true
            sleep 2
        fi
    fi

    # Confirm disconnection
    TIMEOUT=10
    COUNTER=0
    while [ $COUNTER -lt $TIMEOUT ]; do
        if ! _check_tun0_exists; then
            vpn_log_info "VPN connection has been disconnected."
            VPN_CONNECTED=0

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

    if [ -n "$VPN_WORK_DIR" ] && [ -d "$VPN_WORK_DIR" ]; then
        rm -rf "$VPN_WORK_DIR"
    fi

    return 1
}