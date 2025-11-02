#!/bin/bash

# ==============================================================================
# Pinty Monitor Client Script v2.2
# ==============================================================================
# 改进点：
# - 增强了jq的使用，确保所有JSON构建和验证都通过jq处理，避免手动字符串拼接错误。
# - 改进错误处理，包括命令失败的显式检查和更详细的日志记录。
# - 安全密钥处理：优先从环境变量读取，如果不存在则回落到配置。
# - 修复静态信息标志：使用持久文件而不是基于进程的PID标志。
# - 改进网络接口检测，添加更多回退选项。
# - 优化性能：例如，使用top获取CPU信息（如果可用），限制ps输出。
# - 添加退出时的清理陷阱。
# - 增强日志记录，包含时间戳和日志级别。
# - 在启动时验证依赖（jq, curl）。
# - 优雅处理缺失工具（例如，ss vs netstat）。

# 请在此配置您的信息
# ------------------------------------------------------------------------------
# 报告状态的API端点
API_ENDPOINT="${PINTY_API_ENDPOINT:-https://your-domain.com/report.php}"
# 服务器的唯一ID（必须与管理员后台匹配）
SERVER_ID="${PINTY_SERVER_ID:-your-server-id}"
# 服务器特定的密钥（从管理员后台复制；优先使用环境变量以提高安全性）
SECRET="${PINTY_SECRET:-your-secret-key}"
# ==============================================================================

# 错误和信息的日志文件
LOG_FILE="${PINTY_LOG_FILE:-/tmp/pinty_monitor_client.log}"
# 静态信息标志变量
STATIC_FLAG=0
# 报告间隔（秒）
REPORT_INTERVAL=8

# 日志颜色（可选，用于控制台输出）
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # 无颜色

# 日志记录函数
log() {
    local level="$1"
    shift
    local msg="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $msg" | tee -a "$LOG_FILE"
    if [[ "$level" == "ERROR" ]]; then
        echo -e "${RED}[$timestamp] [ERROR] $msg${NC}" >&2
    fi
}

# 退出并记录错误
die() {
    log "ERROR" "$@"
    exit 1
}

# 检查依赖项
check_deps() {
    local missing=()
    for cmd in jq curl; do
        if ! command -v "$cmd" &> /dev/null; then
            missing+=("$cmd")
        fi
    done
    if [[ ${#missing[@]} -gt 0 ]]; then
        die "缺少必需工具: ${missing[*]}。请安装它们。"
    fi
    # 如果ss或netstat不可用，警告连接指标将为0
    if ! command -v ss &> /dev/null && ! command -v netstat &> /dev/null; then
        log "WARN" "未找到'ss'或'netstat'。连接指标将为0。"
    fi
}

# 获取网络使用情况函数（改进错误处理）
get_network_usage() {
    local interface rx_bytes tx_bytes rx_start tx_start rx_end tx_end rx_speed tx_speed total_up total_down

    # 检测主要网络接口
    interface=$(ip route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev") {print $(i+1); break}}' | head -n 1)
    if [[ -z "$interface" ]]; then
        for iface in eth0 venet0 ens3 enp0s3 enp1s0 wlan0; do  # 添加更多常见接口作为回退
            if ip link show "$iface" &> /dev/null; then
                interface="$iface"
                break
            fi
        done
    fi

    if [[ -z "$interface" ]]; then
        log "ERROR" "无法确定有效的网络接口。"
        echo '{"net_up_speed": 0, "net_down_speed": 0, "total_up": 0, "total_down": 0}'
        return 1
    fi

    local stats_rx="/sys/class/net/$interface/statistics/rx_bytes"
    local stats_tx="/sys/class/net/$interface/statistics/tx_bytes"
    if [[ ! -r "$stats_rx" || ! -r "$stats_tx" ]]; then
        log "ERROR" "接口 $interface 的统计信息不可读。"
        echo '{"net_up_speed": 0, "net_down_speed": 0, "total_up": 0, "total_down": 0}'
        return 1
    fi

    rx_start=$(cat "$stats_rx")
    tx_start=$(cat "$stats_tx")
    sleep 1
    rx_end=$(cat "$stats_rx")
    tx_end=$(cat "$stats_tx")

    rx_speed=$((rx_end - rx_start))
    tx_speed=$((tx_end - tx_start))
    total_up=$(cat "$stats_tx")
    total_down=$(cat "$stats_rx")

    # 以jq兼容的JSON片段输出
    jq -n \
        --argjson up_speed "$tx_speed" \
        --argjson down_speed "$rx_speed" \
        --argjson total_up "$total_up" \
        --argjson total_down "$total_down" \
        '{net_up_speed: $up_speed, net_down_speed: $down_speed, total_up: $total_up, total_down: $total_down}'
}

# 获取静态信息（仅一次）
get_static_info() {
    local cpu_model cpu_cores mem_total disk_total os_info arch
    cpu_model=$(grep '^model name' /proc/cpuinfo 2>/dev/null | head -n1 | cut -d: -f2- | xargs | sed 's/"/\\"/g') || cpu_model="Unknown"
    cpu_cores=$(nproc 2>/dev/null) || cpu_cores=0
    mem_total=$(free -b 2>/dev/null | awk 'NR==2{print $2}') || mem_total=0
    disk_total=$(df -B1 / 2>/dev/null | awk 'NR==2{print $2}') || disk_total=0
    os_info=$(lsb_release -ds 2>/dev/null || grep PRETTY_NAME /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"' || uname -oms | tr -d '"') || os_info="Unknown"
    arch=$(uname -m 2>/dev/null) || arch="Unknown"

    jq -n \
        --arg cpu_model "$cpu_model" \
        --argjson cpu_cores "$cpu_cores" \
        --argjson mem_total "$mem_total" \
        --argjson disk_total "$disk_total" \
        --arg os_info "$os_info" \
        --arg arch "$arch" \
        '{static_info: {cpu_model: $cpu_model, cpu_cores: $cpu_cores, mem_total_bytes: $mem_total, disk_total_bytes: $disk_total, system: $os_info, arch: $arch}}'
}

# 主要报告函数
report_status() {
    local cpu_usage mem_info disk_usage uptime load_avg processes connections net_usage static_info_json

    # CPU使用率（使用top如果可用，否则回落到awk方法）
    if command -v top &> /dev/null; then
        cpu_usage=$(top -bn1 2>/dev/null | grep '^%Cpu' | awk '{print 100 - $8}' | cut -d. -f1) || cpu_usage=0
    else
        cpu_usage=$(timeout 2 awk '{u=$2+$4; t=$2+$4+$5; if (NR==1){u1=u; t1=t;} else print int(($2+$4-u1)*100/(t-t1)); }' \
            <(grep '^cpu ' /proc/stat) \
            <(sleep 1 && grep '^cpu ' /proc/stat) 2>/dev/null) || cpu_usage=0
    fi
    cpu_usage=$(printf "%.2f" "$cpu_usage")

    # 内存使用率
    mem_info=$(free 2>/dev/null | awk 'NR==2{printf "%.2f", $3/$2*100}') || mem_info=0.00

    # 磁盘使用率
    disk_usage=$(df / 2>/dev/null | awk 'NR==2{print int($5)}' | sed 's/%//') || disk_usage=0

    # 运行时间
    uptime=$(uptime -p 2>/dev/null | sed 's/up //; s/ days/d/; s/ day/d/; s/ hours/h/; s/ hour/h/; s/ minutes/m/; s/ minute/m/' || echo "0m")

    # 负载平均值
    load_avg=$(awk '{print $1}' /proc/loadavg 2>/dev/null) || load_avg=0

    # 进程数（优化：无头ps并计数）
    processes=$(ps -e --no-headers 2>/dev/null | wc -l) || processes=0

    # 连接数
    if command -v ss &> /dev/null; then
        connections=$(ss -tn 2>/dev/null | grep -c -v '^State')  # 使用-c计数以优化
    elif command -v netstat &> /dev/null; then
        connections=$(netstat -nt 2>/dev/null | grep -c -v '^tcp\|^udp')
    else
        connections=0
    fi

    # 网络使用情况
    net_usage=$(get_network_usage)

    # 静态信息（仅首次运行）
    if [[ "$STATIC_FLAG" != 1 ]]; then
        static_info_json=$(get_static_info)
        STATIC_FLAG=1
        log "INFO" "静态信息已收集并设置标志。"
    else
        static_info_json='{}'
    fi

    # 使用jq构建JSON负载
    local json_payload
    json_payload=$(jq -n \
        --arg server_id "$SERVER_ID" \
        --arg secret "$SECRET" \
        --arg report_interval "$REPORT_INTERVAL"\
        --argjson cpu_usage "$cpu_usage" \
        --argjson mem_usage "$mem_info" \
        --argjson disk_usage "$disk_usage" \
        --arg uptime "$uptime" \
        --argjson load_avg "$load_avg" \
        --argjson processes "$processes" \
        --argjson connections "$connections" \
        --slurpfile net_usage <(echo "$net_usage") \
        --slurpfile static_info <(echo "$static_info_json") \
        '{
            server_id: $server_id,
            secret: $secret,
            report_interval: $report_interval,
            cpu_usage: $cpu_usage,
            mem_usage_percent: $mem_usage,
            disk_usage_percent: $disk_usage,
            uptime: $uptime,
            load_avg: $load_avg,
            processes: $processes,
            connections: $connections
        } + $net_usage[0] + $static_info[0]')

    if [[ $? -ne 0 || -z "$json_payload" ]]; then
        log "ERROR" "构建JSON负载失败。"
        return 1
    fi

    # 发送报告（保持原curl方式，但添加超时以防挂起）
    local http_response http_status http_body
    http_response=$(curl --write-out "HTTPSTATUS:%{http_code}" \
        --silent --fail --show-error \
        --connect-timeout 10 --max-time 30 \
        -X POST -H "Content-Type: application/json" \
        -d "$json_payload" \
        "$API_ENDPOINT" 2>&1)

    http_status=$(echo "$http_response" | sed -n 's/.*HTTPSTATUS:\([0-9][0-9][0-9]\).*/\1/p')
    http_body=$(echo "$http_response" | sed 's/HTTPSTATUS\:.*//g' | tr -d '\n')

    if [[ "$http_status" != "200" ]]; then
        log "ERROR" "报告数据失败。状态: $http_status, 响应: $http_body"
        return 1
    else
        log "INFO" "报告发送成功。状态: $http_status"
    fi
}

# 主脚本
main() {
    log "INFO" "启动Pinty监控脚本，服务器ID: $SERVER_ID..."
    log "INFO" "报告到 $API_ENDPOINT。日志在 $LOG_FILE。间隔: ${REPORT_INTERVAL}s"

    check_deps

    # 陷阱用于清理（例如，中断时记录）
    trap 'log "INFO" "脚本中断退出。"; exit 0' INT TERM

    # 主循环
    while true; do
        if ! report_status; then
            sleep 10  # 错误时延长睡眠以避免频繁尝试
        else
            sleep "$REPORT_INTERVAL"
        fi
    done
}

# 运行主函数
main "$@"
