<?php
// api.php - Provides monitoring data to the frontend

ini_set('display_errors', 0);
error_reporting(0); // 彻底禁用错误报告，防止任何意外输出破坏JSON
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Universal PDO connection function
function get_pdo_connection() {
    global $db_config;
    try {
        if ($db_config['type'] === 'pgsql') {
            $cfg = $db_config['pgsql'];
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
            return new PDO($dsn, $cfg['user'], $cfg['password']);
        } else { // sqlite
            $dsn = 'sqlite:' . $db_config['sqlite']['path'];
            $pdo = new PDO($dsn);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            return $pdo;
        }
    } catch (PDOException $e) {
        // 在生产环境中，应该记录错误而不是直接暴露
        error_log("Database connection failed: " . $e->getMessage());
        throw new Exception("Database connection failed.");
    }
}

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $response = [
        'nodes' => [],
        'outages' => [],
    ];

    // Fetch all servers' basic info
    // FIX: Removed price_usd_monthly from the query as it does not exist in the new schema.
    $stmt_servers = $pdo->query("SELECT id, name, intro, tags, price_usd_yearly, latitude, longitude FROM servers ORDER BY id ASC");
    $servers = $stmt_servers->fetchAll(PDO::FETCH_ASSOC);

    // Fetch latest online status for all servers
    $stmt_status = $pdo->query("SELECT id, is_online FROM server_status");
    $online_status = $stmt_status->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch latest stats for all servers
    if ($db_config['type'] === 'pgsql') {
        $sql_stats = "SELECT DISTINCT ON (server_id) * FROM server_stats ORDER BY server_id, timestamp DESC";
    } else {
        $sql_stats = "SELECT s.* FROM server_stats s JOIN (SELECT server_id, MAX(timestamp) AS max_ts FROM server_stats GROUP BY server_id) AS m ON s.server_id = m.server_id AND s.timestamp = m.max_ts";
    }
    $stmt_stats = $pdo->query($sql_stats);
    $latest_stats_raw = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);
    $latest_stats = [];
    foreach($latest_stats_raw as $stat) {
        $latest_stats[$stat['server_id']] = $stat;
    }

    // Prepare statement for fetching history
    $sql_history = 'SELECT cpu_usage, mem_usage_percent, disk_usage_percent, load_avg, net_up_speed, net_down_speed, total_up, total_down, timestamp FROM server_stats WHERE server_id = ? ORDER BY timestamp DESC LIMIT 20';
    $stmt_history = $pdo->prepare($sql_history);

    foreach ($servers as $node) {
        $node_id = $node['id'];
        
        // 坐标映射
        $node['x'] = (float)($node['latitude'] ?? 0);
        $node['y'] = (float)($node['longitude'] ?? 0);
        
        // Assign latest stat
        $node['stats'] = $latest_stats[$node_id] ?? [];
        
        // Assign online status and message
        $node['is_online'] = (bool)($online_status[$node_id] ?? false);
        if (!$node['is_online']) {
            $node['anomaly_msg'] = '服务器掉线';
        }

        // Fetch last 20 history records for the current node
        $stmt_history->execute([$node_id]);
        $history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
        
        // Cast numeric types
        $typed_history = array_map(function($record) {
            $record['cpu_usage'] = (float)$record['cpu_usage'];
            $record['mem_usage_percent'] = (float)$record['mem_usage_percent'];
            $record['disk_usage_percent'] = (float)$record['disk_usage_percent'];
            $record['load_avg'] = (float)$record['load_avg'];
            $record['net_up_speed'] = (int)$record['net_up_speed'];
            $record['net_down_speed'] = (int)$record['net_down_speed'];
            $record['total_up'] = (int)$record['total_up'];
            $record['total_down'] = (int)$record['total_down'];
            $record['timestamp'] = (int)$record['timestamp'];
            return $record;
        }, $history);

        $node['history'] = array_reverse($typed_history);
        
        $response['nodes'][] = $node;
    }

    // Fetch outages
    $response['outages'] = $pdo->query("SELECT * FROM outages ORDER BY start_time DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    // 在响应中返回具体的错误信息，方便调试
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>


