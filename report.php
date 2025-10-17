<?php
// report.php

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use GuGuan123\Pinty\Database;
use GuGuan123\Pinty\Repositories\ServerRepository;

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
	http_response_code(400);
	echo json_encode(['error' => 'Invalid JSON payload.']);
	exit;
}

$server_id = $input['server_id'] ?? '';
$secret = $input['secret'] ?? '';

if (empty($server_id) || empty($secret)) {
	http_response_code(400);
	echo json_encode(['error' => 'server_id and secret are required.']);
	exit;
}

try {
	$db = Database::getInstance($db_config);
	$pdo = $db->getPdo();
	$serverRepo = new ServerRepository($db_config);

	// 获取服务器IP
	$request_ip = $_SERVER['REMOTE_ADDR'];

	// 验证服务器（secret + IP）
	$validated = $serverRepo->validateServer($server_id, $secret, $request_ip);
	if ($validated['code'] !== 200) {
		http_response_code($validated['code']);
		echo json_encode(['error' => $validated['msg']]);
		exit;
	}

	$pdo->beginTransaction();

	// Insert server statistics
	$sql_stats = "INSERT INTO server_stats (server_id, timestamp, cpu_usage, mem_usage_percent, disk_usage_percent, uptime, load_avg, net_up_speed, net_down_speed, total_up, total_down, processes, connections) 
				  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
	$stmt_stats = $pdo->prepare($sql_stats);
	$stmt_stats->execute([
		$server_id,
		date('Y-m-d H:i:s'),
		$input['cpu_usage'] ?? 0,
		$input['mem_usage_percent'] ?? 0,
		$input['disk_usage_percent'] ?? 0,
		$input['uptime'] ?? '',
		$input['load_avg'] ?? 0,
		$input['net_up_speed'] ?? 0,
		$input['net_down_speed'] ?? 0,
		$input['total_up'] ?? 0,
		$input['total_down'] ?? 0,
		$input['processes'] ?? NULL,
		$input['connections'] ?? NULL
	]);

	// Update server hardware info (only if provided)
	if (isset($input['static_info'])) {
		$serverRepo->updateHardware($server_id, 
			$input['static_info']['cpu_cores'] ?? null,
			$input['static_info']['cpu_model'] ?? null,
			$input['static_info']['mem_total_bytes'] ?? null,
			$input['static_info']['disk_total_bytes'] ?? null,
			$input['static_info']['system'] ?? null,
			$input['static_info']['arch'] ?? null
		);
	}

	// Update server status to online (DB-specific UPSERT)
	$serverRepo->updateStatus($server_id, true, date('Y-m-d H:i:s'));

	$pdo->commit();

	echo json_encode(['success' => true]);

	if (!isset($monitoring_execution_mode) || (isset($monitoring_execution_mode) && $monitoring_execution_mode == true)) {
		try {
			$monitoringService = new GuGuan123\Pinty\Services\MonitoringService($db_config);
			$monitoringService->checkAndNotify();
		} catch (\Exception $e) {
			error_log("Cron Error: " . $e->getMessage());
		}
	}

} catch (Exception $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	error_log("report.php Error for server '{$server_id}': " . $e->getMessage());
	if (!headers_sent()) {
		http_response_code(500);
	}
	echo json_encode(['error' => $e->getMessage()]);
}
