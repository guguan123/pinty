#!/usr/bin/env python3

import json
import time
import logging
import subprocess
import sys
from datetime import datetime
try:
    import psutil
    HAS_PSUTIL = True
except ImportError:
    HAS_PSUTIL = False
    print("Warning: psutil not installed. Falling back to /proc reads.", file=sys.stderr)

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False
    print("Warning: requests not installed. Using urllib fallback.", file=sys.stderr)

# 配置
API_ENDPOINT = "http://0.d.f.a.a.2.8.f.0.7.4.0.1.0.0.2.ip6.arpa/report.php"
SERVER_ID = "a"
SECRET = "a7d23a5ded98c84c06263e237fea4e48"
LOG_FILE = "/tmp/monitor_client.log"

# 设置日志
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stderr)
    ]
)
logger = logging.getLogger(__name__)

def get_cpu_usage():
    """获取CPU使用率（%）"""
    if HAS_PSUTIL:
        return round(psutil.cpu_percent(interval=1), 2)
    # Fallback: /proc/stat
    try:
        with open('/proc/stat') as f:
            cpu1 = [int(x) for x in f.readline().split()[1:5]]
        time.sleep(1)
        with open('/proc/stat') as f:
            cpu2 = [int(x) for x in f.readline().split()[1:5]]
        idle1, total1 = cpu1[3], sum(cpu1)
        idle2, total2 = cpu2[3], sum(cpu2)
        return round(((total2 - total1) - (idle2 - idle1)) / (total2 - total1) * 100, 2)
    except:
        return 0.0

def get_mem_usage_percent():
    """获取内存使用率（%）"""
    if HAS_PSUTIL:
        return round(psutil.virtual_memory().percent, 2)
    # Fallback: free
    try:
        output = subprocess.check_output(['free']).decode()
        lines = output.split('\n')
        mem = list(map(int, lines[1].split()[1:3]))
        return round(mem[1] / mem[0] * 100, 2)
    except:
        return 0.0

def get_disk_usage_percent():
    """获取根分区磁盘使用率（%）"""
    if HAS_PSUTIL:
        return round(psutil.disk_usage('/').percent)
    # Fallback: df
    try:
        output = subprocess.check_output(['df', '/']).decode()
        return int(output.split('\n')[1].split()[4].rstrip('%'))
    except:
        return 0

def get_uptime():
    """获取uptime"""
    try:
        with open('/proc/uptime') as f:
            return f.read().split()[0] + ' seconds'
    except:
        try:
            output = subprocess.check_output(['uptime', '-p']).decode()
            return output.strip().replace('up ', '')
        except:
            return 'unknown'

def get_load_avg():
    """获取负载平均（1min）"""
    if HAS_PSUTIL:
        return psutil.getloadavg()[0]
    # Fallback: /proc/loadavg
    try:
        with open('/proc/loadavg') as f:
            return float(f.read().split()[0])
    except:
        return 0.0

def get_network_usage():
    """获取网络使用：速度（B/s）和总量（B）"""
    if HAS_PSUTIL:
        net_io1 = psutil.net_io_counters()
        time.sleep(1)
        net_io2 = psutil.net_io_counters()
        net_up_speed = net_io2.bytes_sent - net_io1.bytes_sent
        net_down_speed = net_io2.bytes_recv - net_io1.bytes_recv
        total_up = net_io2.bytes_sent
        total_down = net_io2.bytes_recv
        return net_up_speed, net_down_speed, total_up, total_down

    # Fallback: /sys/class/net
    try:
        # 找默认接口
        output = subprocess.check_output(['ip', 'route']).decode()
        interface = [line.split()[-1] for line in output.split('\n') if 'default' in line]
        interface = interface[0] if interface else 'eth0'
        if not any(subprocess.check_output(['ip', 'link', 'show', interface]).decode()):
            interface = 'ens3'  # 云常见fallback

        rx1 = int(subprocess.check_output(['cat', f'/sys/class/net/{interface}/statistics/rx_bytes']).decode())
        tx1 = int(subprocess.check_output(['cat', f'/sys/class/net/{interface}/statistics/tx_bytes']).decode())
        time.sleep(1)
        rx2 = int(subprocess.check_output(['cat', f'/sys/class/net/{interface}/statistics/rx_bytes']).decode())
        tx2 = int(subprocess.check_output(['cat', f'/sys/class/net/{interface}/statistics/tx_bytes']).decode())

        net_up_speed = tx2 - tx1
        net_down_speed = rx2 - rx1
        total_up = tx2
        total_down = rx2
        return net_up_speed, net_down_speed, total_up, total_down
    except:
        return 0, 0, 0, 0

def get_static_info():
    """获取静态硬件信息（只调用一次）"""
    static = {}
    try:
        if HAS_PSUTIL:
            static['cpu_cores'] = psutil.cpu_count()
            static['cpu_model'] = psutil.cpu_freq()._asdict().get('model', '') or 'unknown'
            static['mem_total'] = f"{psutil.virtual_memory().total / (1024**2):.0f} MB"
            static['disk_total'] = f"{psutil.disk_usage('/').total / (1024**3):.0f} GB"
        else:
            # Fallback
            output = subprocess.check_output(['grep', 'model name', '/proc/cpuinfo']).decode()
            static['cpu_model'] = output.split(':', 1)[1].strip() if output else 'unknown'
            static['cpu_cores'] = int(subprocess.check_output(['nproc']).decode())
            output = subprocess.check_output(['free', '-m']).decode()
            static['mem_total'] = f"{output.split()[6]} MB"  # total in line 1
            output = subprocess.check_output(['df', '-h', '/']).decode()
            static['disk_total'] = output.split()[8]  # size in line 1
    except Exception as e:
        logger.error(f"Failed to get static info: {e}")
    return static

def send_report(payload):
    """发送报告到API"""
    headers = {'Content-Type': 'application/json'}
    if HAS_REQUESTS:
        try:
            response = requests.post(API_ENDPOINT, json=payload, headers=headers, verify=False, timeout=10)
            return response.status_code, response.text
        except Exception as e:
            logger.error(f"Requests error: {e}")
            return 500, str(e)
    else:
        # Fallback: urllib
        import urllib.request
        import urllib.error
        data = json.dumps(payload).encode('utf-8')
        req = urllib.request.Request(API_ENDPOINT, data=data, headers=headers, method='POST')
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                return resp.getcode(), resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()
        except Exception as e:
            return 500, str(e)

def main():
    logger.info(f"Starting monitoring script for {SERVER_ID}. Reporting to {API_ENDPOINT}.")
    send_static = True  # 只第一次发静态

    while True:
        # 动态数据
        payload = {
            'server_id': SERVER_ID,
            'secret': SECRET,
            'cpu_usage': get_cpu_usage(),
            'mem_usage_percent': get_mem_usage_percent(),
            'disk_usage_percent': get_disk_usage_percent(),
            'uptime': get_uptime(),
            'load_avg': get_load_avg(),
        }

        # 网络
        net_up_speed, net_down_speed, total_up, total_down = get_network_usage()
        payload.update({
            'net_up_speed': net_up_speed,
            'net_down_speed': net_down_speed,
            'total_up': total_up,
            'total_down': total_down,
        })

        # 静态（只第一次）
        if send_static:
            static = get_static_info()
            payload.update(static)
            logger.info("Sent static info on first run.")
            send_static = False

        # 发送
        status_code, response_body = send_report(payload)
        logger.info(f"Payload preview: {json.dumps(payload, indent=2)[:200]}...")  # 截断预览

        if status_code == 200:
            logger.info("Reported successfully.")
        else:
            logger.error(f"Failed to report. Status: {status_code}, Response: {response_body}")

        time.sleep(9)

if __name__ == '__main__':
    main()
