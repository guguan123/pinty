<?php
// api.php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use GuGuan123\Pinty\Repositories\ServerRepository;
use GuGuan123\Pinty\Repositories\OutagesRepository;
use GuGuan123\Pinty\Repositories\SettingsRepository;

header('Content-Type: application/json');

try {
	$repo = new ServerRepository($db_config);
	$outagesRepo = new OutagesRepository($db_config);
	$settingsRepo = new SettingsRepository($db_config);
	$section = $_GET['action'] ?? 'all'; // 默认 'all' 兼容旧版，但推荐前端指定
	$serverId = $_GET['id'] ?? null;
	$limit = (int)($_GET['limit'] ?? 20); // 历史限量

	$response = [];

	switch ($section) {
		case 'web-info':
			$response['site_name'] = $settingsRepo->getSetting('site_name') ?: NULL;
			break;

		case 'list':
			// 地图只需基本节点 + 状态 + 最新 stats（无历史）
			$servers = $repo->getAllServers();
			$online_status = $repo->getOnlineStatuses();
			$latest_stats = $repo->getLatestStats();

			$nodes = array();
			foreach ($servers as $server) {
				$node_id = $server['id'];
				$stats = $latest_stats[$node_id] ?? NULL;
				
				$node = array(
					'id' => $server['id'],
					'name' => $server['name'],
					'latitude' => $server['latitude'],
					'longitude' => (float)($server['longitude'] ?? 0),
					'intro' => $server['intro'] ?? '',
					'tags' => $server['tags'] ?? null,
					'mem_total' => $server['mem_total'],
					'disk_total' => $server['disk_total'],
					'expiry_date' => $server['expiry_date'] ?? null,
					'country_code' => $server['country_code'],
					'is_online' => (bool)($online_status[$node_id] ?? false),
					'stats' => array(
						'timestamp' => $stats['timestamp'] ?? NULL,
						'cpu_usage' => $stats['cpu_usage'] ?? NULL,
						'mem_usage_percent' => $stats['mem_usage_percent'] ?? NULL,
						'disk_usage_percent' => $stats['disk_usage_percent'] ?? NULL,
						'net_up_speed' => $stats['net_up_speed'] ?? NULL,
						'net_down_speed' => $stats['net_down_speed'] ?? NULL,
						'total_up' => $stats['total_up'] ?? NULL,
						'total_down' => $stats['total_down'] ?? NULL,
						'uptime' => $stats['uptime'] ?? NULL,
						'load_avg' => $stats['load_avg'] ?? NULL,
						'processes' => $stats['processes'] ?? NULL,
						'connections' => $stats['connections'] ?? NULL
					)
				);
				
				$nodes[] = $node;
			}
			$response['nodes'] = $nodes;
			break;

		case 'server':
			// 单服务器详情：基本 + 历史 + stats
			if (!$serverId || !$repo->existsById($serverId)) {
				throw new \Exception('Invalid server ID');
			}
			$server = $repo->getServerById($serverId);
			if (!$server) {
				throw new \Exception('Server not found');
			}
			$server['x'] = $server['latitude'];
			$server['y'] = (float)($server['longitude'] ?? 0);
			$server['stats'] = $repo->getLatestStats()[$serverId] ?? [];
			$server['is_online'] = (bool)($repo->getOnlineStatuses()[$serverId] ?? false);
			if (!$server['is_online']) {
				$server['anomaly_msg'] = '服务器掉线';
			}
			$server['history'] = array_reverse($repo->getServerHistory($serverId, $limit)); // 支持 limit
			$response['node'] = $server;
			break;

		case 'outages':
			// 返回故障列表
			$response['outages'] = $outagesRepo->getRecentOutages($limit); // 支持 limit
			break;

		case 'all':
			// 兼容旧版：全量（但不推荐长期用）
			$servers = $repo->getAllServers();
			$online_status = $repo->getOnlineStatuses();
			$latest_stats = $repo->getLatestStats();

			$nodes = [];
			foreach ($servers as $node) {
				$node_id = $node['id'];
				$node['x'] = $node['latitude'];
				$node['y'] = (float)($node['longitude'] ?? 0);
				$node['stats'] = $latest_stats[$node_id] ?? [];
				$node['is_online'] = (bool)($online_status[$node_id] ?? false);
				if (!$node['is_online']) {
					$node['anomaly_msg'] = '服务器掉线';
				}
				$node['history'] = array_reverse($repo->getServerHistory($node_id, $limit));
				$nodes[] = $node;
			}
			$response['nodes'] = $nodes;
			$response['outages'] = $outagesRepo->getRecentOutages(50);
			break;

		default:
			throw new \Exception('Invalid section: ' . $section);
	}

	echo json_encode($response);

	if (!isset($monitoring_execution_mode) || (isset($monitoring_execution_mode) && $monitoring_execution_mode == true)) {
		try {
			$monitoringService = new GuGuan123\Pinty\Services\MonitoringService($db_config);
			$monitoringService->checkAndNotify();
		} catch (\Exception $e) {
			error_log("Cron Error: " . $e->getMessage());
		}
	}

} catch (\Exception $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
