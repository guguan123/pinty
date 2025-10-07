#!/usr/bin/env python3

import json
import time
import logging
import subprocess
import sys
import os
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
LOG_FILE = "/tmp/pinty_monitor_client.log"

# 首次运行标志文件
FIRST_RUN_FLAG = f"/tmp/pinty_first_run_{os.getpid()}"

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

def get_processes():
	"""获取进程数"""
	try:
		output = subprocess.check_output(['ps', '-e', '--no-headers']).decode()
		return len(output.splitlines())
	except:
		return 0

def get_connections():
	"""获取连接数"""
	try:
		output = subprocess.check_output(['ss', '-tn']).decode()
		lines = output.splitlines()
		# Skip header
		if lines and 'State' in lines[0]:
			return len(lines) - 1
		else:
			return len(lines)
	except:
		try:
			output = subprocess.check_output(['netstat', '-nt']).decode()
			lines = output.splitlines()
			# Skip header
			if lines and 'State' in lines[0]:
				return len(lines) - 1
			else:
				return len(lines)
		except:
			return 0

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
		default_lines = [line for line in output.split('\n') if 'default' in line]
		if default_lines:
			interface = default_lines[0].split()[-1]
		else:
			# Fallback interfaces
			for iface in ['eth0', 'venet0', 'ens3']:
				try:
					subprocess.check_output(['ip', 'link', 'show', iface], stderr=subprocess.DEVNULL)
					interface = iface
					break
				except:
					continue
			else:
				raise Exception("No valid interface found")

		# Check stats exist
		rx_path = f'/sys/class/net/{interface}/statistics/rx_bytes'
		tx_path = f'/sys/class/net/{interface}/statistics/tx_bytes'
		if not (os.path.exists(rx_path) and os.path.exists(tx_path)):
			raise Exception(f"Statistics not found for {interface}")

		with open(tx_path) as f:
			tx1 = int(f.read().strip())
		with open(rx_path) as f:
			rx1 = int(f.read().strip())
		time.sleep(1)
		with open(tx_path) as f:
			tx2 = int(f.read().strip())
		with open(rx_path) as f:
			rx2 = int(f.read().strip())

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
		# CPU model
		try:
			output = subprocess.check_output(['grep', 'model name', '/proc/cpuinfo']).decode()
			if output:
				static['cpu_model'] = output.split(':', 1)[1].strip()
			else:
				static['cpu_model'] = 'unknown'
		except:
			static['cpu_model'] = 'unknown'

		# CPU cores
		try:
			static['cpu_cores'] = int(subprocess.check_output(['nproc']).decode().strip())
		except:
			static['cpu_cores'] = 0

		# Mem total bytes
		try:
			output = subprocess.check_output(['free', '-b']).decode()
			lines = output.splitlines()
			static['mem_total_bytes'] = int(lines[1].split()[1])
		except:
			static['mem_total_bytes'] = 0

		# Disk total bytes
		try:
			output = subprocess.check_output(['df', '-B1', '/']).decode()
			lines = output.splitlines()
			static['disk_total_bytes'] = int(lines[1].split()[1])
		except:
			static['disk_total_bytes'] = 0

		# OS info
		try:
			output = subprocess.check_output(['lsb_release', '-ds'], stderr=subprocess.DEVNULL).decode().strip()
			if output:
				static['system'] = output.replace('"', '')
			else:
				try:
					output = subprocess.check_output(['cat', '/etc/*-release'], stderr=subprocess.DEVNULL).decode()
					lines = output.splitlines()
					if lines:
						static['system'] = lines[0].replace('"', '')
					else:
						static['system'] = subprocess.check_output(['uname', '-om']).decode().strip().replace('"', '')
				except:
					static['system'] = 'unknown'
		except:
			static['system'] = 'unknown'

		# Arch
		try:
			static['arch'] = subprocess.check_output(['uname', '-m']).decode().strip()
		except:
			static['arch'] = 'unknown'

	except Exception as e:
		logger.error(f"Failed to get static info: {e}")
	return static

def schedule_flag_cleanup(flag_file):
	"""Schedule cleanup of flag file (simulate 'at' with a simple approach)"""
	# For simplicity, use a background thread or just ignore; here we can use subprocess to run a sleep and rm
	try:
		subprocess.Popen(['bash', '-c', f'sleep 300 && rm -f {flag_file}'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
	except:
		pass  # Ignore if can't schedule

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
	logger.info(f"Starting Pinty monitoring script for {SERVER_ID}...")
	logger.info(f"Reporting to {API_ENDPOINT}. Errors will be logged to {LOG_FILE}")

	# 首次运行检查
	send_static = not os.path.exists(FIRST_RUN_FLAG)
	if send_static:
		os.touch(FIRST_RUN_FLAG)
		schedule_flag_cleanup(FIRST_RUN_FLAG)

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
			'processes': get_processes(),
			'connections': get_connections(),
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
			payload['static_info'] = static
			logger.info("Sent static info on first run.")
			send_static = False

		# 发送
		status_code, response_body = send_report(payload)

		if status_code == 200:
			logger.info("Reported successfully.")
		else:
			error_msg = f"Failed to report data. Status: {status_code}, Response: {response_body}"
			logger.error(error_msg)
			print(error_msg, file=sys.stderr)

		time.sleep(8)  # Total interval ~9s with 1s in measurements

if __name__ == '__main__':
	main()
