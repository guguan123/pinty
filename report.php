<?php
// report.php - v1.2 - Receives status updates from monitored servers.
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// --- 自包含函数定义 ---

/**
 * 创建并返回一个 PDO 数据库连接对象。
 * @return PDO
 * @throws Exception
 */
function get_pdo_connection() {
    global $db_config;
    if (empty($db_config)) {
        throw new Exception("数据库配置 (config.php) 丢失或为空。");
    }
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
        throw new Exception("数据库连接失败: " . $e->getMessage());
    }
}


// --- 主要脚本逻辑 ---

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
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch server secret and IP for validation
    $stmt = $pdo->prepare("SELECT secret, ip FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        http_response_code(404);
        throw new Exception('Invalid server_id.');
    }

    // --- SECURITY CHECKS ---
    // 1. Secret Key Validation
    if (empty($server['secret']) || !hash_equals($server['secret'], $secret)) {
        http_response_code(403);
        throw new Exception('Invalid secret.');
    }

    // 2. IP Address Validation
    $stored_ip = $server['ip'] ?? null;
    $request_ip = $_SERVER['REMOTE_ADDR'];

    if (!empty($stored_ip) && $stored_ip !== $request_ip) {
        http_response_code(403);
        error_log("IP validation failed for server '{$server_id}'. Expected '{$stored_ip}', got '{$request_ip}'.");
        throw new Exception('IP address mismatch.');
    }
    
    // --- End Security Checks ---

    $pdo->beginTransaction();

    // Insert server statistics
    $sql_stats = "INSERT INTO server_stats (server_id, timestamp, cpu_usage, mem_usage_percent, disk_usage_percent, uptime, load_avg, net_up_speed, net_down_speed, total_up, total_down) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt_stats = $pdo->prepare($sql_stats);
    $stmt_stats->execute([
        $server_id,
        time(),
        $input['cpu_usage'] ?? 0,
        $input['mem_usage_percent'] ?? 0,
        $input['disk_usage_percent'] ?? 0,
        $input['uptime'] ?? '',
        $input['load_avg'] ?? 0,
        $input['net_up_speed'] ?? 0,
        $input['net_down_speed'] ?? 0,
        $input['total_up'] ?? 0,
        $input['total_down'] ?? 0,
    ]);

    // Update server hardware info (only on report, not every time)
    $sql_update_hw = "UPDATE servers SET cpu_cores = ?, cpu_model = ?, mem_total = ?, disk_total = ? WHERE id = ?";
    $stmt_update_hw = $pdo->prepare($sql_update_hw);
    $stmt_update_hw->execute([
        $input['cpu_cores'] ?? null,
        $input['cpu_model'] ?? null,
        $input['mem_total'] ?? null,
        $input['disk_total'] ?? null,
        $server_id
    ]);


    // Update server status to online
    $sql_status = $db_config['type'] === 'pgsql'
        ? "INSERT INTO server_status (id, is_online, last_checked) VALUES (?, true, ?) ON CONFLICT (id) DO UPDATE SET is_online = true, last_checked = EXCLUDED.last_checked"
        : "INSERT OR REPLACE INTO server_status (id, is_online, last_checked) VALUES (?, 1, ?)";
    $stmt_status = $pdo->prepare($sql_status);
    $stmt_status->execute([$server_id, time()]);

    $pdo->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Don't expose detailed DB errors to the client script
    error_log("report.php Error for server '{$server_id}': " . $e->getMessage());
    if(!headers_sent()){
        http_response_code(500);
    }
    echo json_encode(['error' => 'An internal server error occurred.']);
}
