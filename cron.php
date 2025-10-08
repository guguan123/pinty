<?php
// cron.php

namespace GuGuan123\Pinty;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

try {
	$monitoringService = new GuGuan123\Pinty\Services\MonitoringService($db_config);
	$monitoringService->checkAndNotify();
} catch (\Exception $e) {
	error_log("cron.php Error: " . $e->getMessage());
	exit(1);
}
