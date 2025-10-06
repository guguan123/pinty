<?php
// check_status.php - Cron job script to check for offline servers and send alerts.
// v1.2 - Added logic to mark timed-out servers as offline.

// Set a default timezone to prevent potential date/time warnings in cron environment.
date_default_timezone_set('UTC'); 

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
        error_log("check_status.php - 数据库连接失败: " . $e->getMessage());
        throw new Exception("数据库连接失败。");
    }
}

/**
 * 通过 Telegram 机器人发送消息。
 * @param string $token 机器人 API Token
 * @param string $chat_id 目标聊天 ID
 * @param string $message 消息文本
 * @return bool 成功返回 true, 失败返回 false
 */
function send_telegram_message($token, $chat_id, $message) {
    if (empty($token) || empty($chat_id)) {
        error_log("Telegram bot token or chat ID is not configured.");
        return false;
    }
    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown' // 使用 Markdown 格式化消息
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true // 即使HTTP状态码是4xx/5xx，也获取响应内容
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        error_log("Telegram API request failed completely (file_get_contents returned false).");
        return false;
    }
    
    $response_data = json_decode($result, true);
    if (!isset($response_data['ok']) || !$response_data['ok']) {
        error_log("Telegram API Error: " . ($response_data['description'] ?? 'Unknown error'));
        return false;
    }
    
    return true;
}

// --- 主要脚本逻辑 ---

const OFFLINE_THRESHOLD = 35; // 服务器超过35秒未报告，则标记为离线

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- 1. 更新服务器在线状态 ---
    $current_time = time();
    $status_stmt = $pdo->query("SELECT id, last_checked, is_online FROM server_status");
    $all_statuses = $status_stmt->fetchAll(PDO::FETCH_ASSOC);

    $update_status_stmt = $pdo->prepare("UPDATE server_status SET is_online = false WHERE id = ?");

    foreach ($all_statuses as $status) {
        if ($status['is_online'] && ($current_time - $status['last_checked'] > OFFLINE_THRESHOLD)) {
            // 如果服务器当前是在线状态，但最后报告时间已超时，则将其标记为离线
            $update_status_stmt->execute([$status['id']]);
            error_log("Server '{$status['id']}' marked as offline due to timeout.");
        }
    }

    // --- 2. 处理掉线和恢复通知 ---
    // 获取 Telegram 设置
    $settings_stmt = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'telegram_%'");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $bot_token = $settings['telegram_bot_token'] ?? '';
    $chat_id = $settings['telegram_chat_id'] ?? '';

    // 查询所有服务器及其最后的状态
    $servers_stmt = $pdo->query("SELECT s.id, s.name, st.is_online FROM servers s LEFT JOIN server_status st ON s.id = st.id");
    $servers = $servers_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($servers as $server) {
        $server_id = $server['id'];
        $server_name = $server['name'];
        $is_currently_online = (bool)$server['is_online'];

        // 检查此服务器是否存在一个未结束的掉线记录
        $outage_stmt = $pdo->prepare("SELECT * FROM outages WHERE server_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $outage_stmt->execute([$server_id]);
        $active_outage = $outage_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$is_currently_online) {
            // 情况1：服务器当前是离线状态
            if (!$active_outage) {
                // 并且没有进行中的掉线记录，说明这是新的掉线事件
                $start_time = time();
                $insert_stmt = $pdo->prepare("INSERT INTO outages (server_id, start_time, title, content) VALUES (?, ?, ?, ?)");
                $insert_stmt->execute([$server_id, $start_time, '服务器掉线', '服务器停止报告数据。']);
                
                $message = "🔴 *服务离线警告*\n\n服务器 `{$server_name}` (`{$server_id}`) 已停止响应。";
                send_telegram_message($bot_token, $chat_id, $message);
            }
        } else {
            // 情况2：服务器当前是在线状态
            if ($active_outage) {
                // 但数据库中有一条未结束的掉线记录，说明它刚刚恢复
                $end_time = time();
                $duration = round(($end_time - $active_outage['start_time']) / 60);
                $update_stmt = $pdo->prepare("UPDATE outages SET end_time = ? WHERE id = ?");
                $update_stmt->execute([$end_time, $active_outage['id']]);

                $message = "✅ *服务恢复通知*\n\n服务器 `{$server_name}` (`{$server_id}`) 已恢复在线。\n持续离线时间：约 {$duration} 分钟。";
                send_telegram_message($bot_token, $chat_id, $message);
            }
        }
    }
} catch (Exception $e) {
    // 将任何错误记录到PHP错误日志中
    error_log("check_status.php CRON Error: " . $e->getMessage());
    exit(1); // 以非零状态码退出，向 cron 守护进程表明任务失败
}

exit(0); // 成功完成
?>


