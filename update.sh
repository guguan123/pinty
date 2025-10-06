#!/bin/bash

# API端点，用于接收状态报告
API_ENDPOINT=""
# 服务器的唯一ID (与管理员后台设置的ID匹配)
SERVER_ID=""
# 每个服务器独立的密钥 (从管理员后台复制)
SECRET=""
# 错误报告日志文件
LOG_FILE="/var/log/monitor_client.log"

# --- 脚本 ---
echo "Starting monitoring script for $SERVER_ID..."
echo "Reporting to $API_ENDPOINT. Errors will be logged to $LOG_FILE"

# 获取网络使用情况的函数
get_network_usage() {
    INTERFACE=$(ip route | grep '^default' | awk '{print $5}' | head -n 1)
    if [ -z "$INTERFACE" ]; then
        if ip link show eth0 > /dev/null 2>&1; then INTERFACE="eth0";
        elif ip link show warp > /dev/null 2>&1; then INTERFACE="warp";
        else echo "Error: Could not determine a valid network interface." >&2; return; fi
    fi
    
    RX_BYTES_START=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)
    TX_BYTES_START=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)
    sleep 1
    RX_BYTES_END=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)
    TX_BYTES_END=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)

    RX_SPEED=$((RX_BYTES_END - RX_BYTES_START))
    TX_SPEED=$((TX_BYTES_END - TX_BYTES_START))
    TOTAL_UP=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)
    TOTAL_DOWN=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)

    echo "\"net_up_speed\": ${TX_SPEED:-0}, \"net_down_speed\": ${RX_SPEED:-0}, \"total_up\": ${TOTAL_UP:-0}, \"total_down\": ${TOTAL_DOWN:-0}"
}

# --- 首次运行时获取静态信息 ---
CPU_MODEL=$(grep 'model name' /proc/cpuinfo | head -n 1 | cut -d ':' -f 2 | sed 's/^[ \t]*//')
CPU_CORES=$(nproc)
TOTAL_MEM=$(free -m | awk 'NR==2{print $2}')" MB"
TOTAL_DISK=$(df -h / | awk 'NR==2{print $2}')

STATIC_INFO_JSON=$(cat <<EOF
,
"static_info": {
    "cpu_model": "$CPU_MODEL",
    "cpu_cores": $CPU_CORES,
    "total_mem": "$TOTAL_MEM",
    "total_disk": "$TOTAL_DISK"
}
EOF
)
# --- 静态信息获取结束 ---

# 主循环
while true; do
    CPU_USAGE=$(awk '{u=$2+$4; t=$2+$4+$5; if (NR==1){u1=u; t1=t;} else print ($2+$4-u1) * 100 / (t-t1); }' <(grep 'cpu ' /proc/stat) <(sleep 1;grep 'cpu ' /proc/stat) | awk '{printf "%.2f", $0}')
    MEM_INFO=$(free | awk 'NR==2{printf "\"mem_usage_percent\": %.2f", $3/$2*100}')
    DISK_USAGE=$(df / | awk 'NR==2{print $5}' | sed 's/%//')
    UPTIME=$(uptime -p | sed 's/up //')
    LOAD_AVG=$(awk '{print $1}' /proc/loadavg)
    NET_USAGE=$(get_network_usage)

    JSON_PAYLOAD=$(cat <<EOF
{
    "server_id": "$SERVER_ID",
    "secret": "$SECRET",
    "cpu_usage": ${CPU_USAGE:-0},
    ${MEM_INFO:-"\"mem_usage_percent\": 0"},
    "disk_usage_percent": ${DISK_USAGE:-0},
    "uptime": "$UPTIME",
    "load_avg": ${LOAD_AVG:-0},
    ${NET_USAGE:-"\"net_up_speed\": 0, ..."}
    ${STATIC_INFO_JSON}
}
EOF
)
    # 首次报告后，不再发送静态信息以节省带宽
    STATIC_INFO_JSON=""

    HTTP_RESPONSE=$(curl --write-out "HTTPSTATUS:%{http_code}" -s -X POST -H "Content-Type: application/json" -d "$JSON_PAYLOAD" "$API_ENDPOINT")
    
    HTTP_STATUS=$(echo "$HTTP_RESPONSE" | tr -d '\n' | sed -e 's/.*HTTPSTATUS://')
    HTTP_BODY=$(echo "$HTTP_RESPONSE" | sed -e 's/HTTPSTATUS\:.*//g')
    
    if [ "$HTTP_STATUS" -ne 200 ]; then
        ERROR_MSG="[$(date)] - Failed to report data. Status: $HTTP_STATUS, Response: $HTTP_BODY"
        echo "$ERROR_MSG" >&2
        echo "$ERROR_MSG" >> "$LOG_FILE"
    else
        :
    fi

    sleep 9
done
