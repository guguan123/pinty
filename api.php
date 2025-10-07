<?php
// api.php

require_once __DIR__ . '/vendor/autoload.php';  // 唯一加载！
require_once __DIR__ . '/config.php';

use GuGuan123\Pinty\Repositories\ServerRepository;
use GuGuan123\Pinty\Repositories\OutagesRepository;

header('Content-Type: application/json');

try {
    $repo = new ServerRepository($db_config); // 注入配置

    $response = [
        'nodes' => [],
        'outages' => [],
    ];

    // 用Repository替换查询
    $servers = $repo->getAllServers();
    $online_status = $repo->getOnlineStatuses(); // 假设你加了这个方法
    $latest_stats = $repo->getLatestStats(); // 同上

    foreach ($servers as $node) {
        $node_id = $node['id'];
        $node['x'] = $node['latitude'];
        $node['y'] = (float)($node['longitude'] ?? 0);
        $node['stats'] = $latest_stats[$node_id] ?? [];
        $node['is_online'] = (bool)($online_status[$node_id] ?? false);
        if (!$node['is_online']) {
            $node['anomaly_msg'] = '服务器掉线';
        }
        $node['history'] = array_reverse($repo->getServerHistory($node_id)); // 直接调用

        $response['nodes'][] = $node;
    }

    // outages可以用另一个Repository处理
    $outagesRepo = new OutagesRepository($db_config);
    $response['outages'] = $outagesRepo->getRecentOutages(50);

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
