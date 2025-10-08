<?php
// cron.php

namespace GuGuan123\Pinty;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use GuGuan123\Pinty\Services\MonitoringService;

try {
	$monitoringService = new MonitoringService($db_config); // 只传db_config
	$monitoringService->checkAndNotify(); // 一键检查
} catch (\Exception $e) {
	error_log("cron.php Error: " . $e->getMessage());
	exit(1);
}
