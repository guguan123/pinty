<?php
// check_status.php - 定时任务脚本：用于检测服务器是否离线，并通过 Telegram 发送告警通知。

namespace GuGuan123\Pinty;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use GuGuan123\Pinty\Repositories\ServerRepository;
use GuGuan123\Pinty\Repositories\OutagesRepository;
use GuGuan123\Pinty\Repositories\SettingsRepository;

/**
 * Class CheckStatus
 * 核心类：负责检测服务器状态并发送通知
 */
class CheckStatus {
    /**
     * 离线阈值（秒）
     * 如果服务器超过这个时间没有上报数据，则视为离线
     */
    private const OFFLINE_THRESHOLD = 35;

    /** @var ServerRepository 服务器数据仓库 */
    private ServerRepository $serverRepo;

    /** @var OutagesRepository 故障记录仓库 */
    private OutagesRepository $outagesRepo;

	/** @var int $enabledPush */

    /** @var string Telegram Bot Token */
    private string $botToken;

    /** @var string Telegram 聊天 ID */
    private string $chatId;

    /**
     * 构造函数
     * @param array $dbConfig 数据库配置数组
     */
    public function __construct(array $dbConfig, array $telegram_config) {
        // 初始化仓库实例
        $this->serverRepo = new ServerRepository($dbConfig);
        $this->outagesRepo = new OutagesRepository($dbConfig);

		$enabledPush = $telegram_config['enabled'];

		if ($enabledPush == 1) {
			// 获取 Telegram 配置
			$this->botToken = $telegram_config['botToken'];
			$this->chatId = $telegram_config['chatId'];

			// 如果未配置 Telegram 参数，记录日志警告
			if (empty($this->botToken) || empty($this->chatId)) {
				error_log('Telegram bot token or chat ID is not configured.');
			}
		}
    }

    /**
     * 主运行方法
     * 执行状态检测与通知逻辑
     */
    public function run(): void {
        try {
            $this->updateOfflineStatuses();         // 更新离线状态
            $this->processOutagesAndNotifications(); // 处理故障与通知
        } catch (\Exception $e) {
            error_log("check_status.php CRON Error: " . $e->getMessage());
            exit(1); // 异常退出，确保 cron 能感知失败
        }
    }

    /**
     * 检查服务器是否超时未上报，若超时则标记为离线
     */
    private function updateOfflineStatuses(): void {
        $currentTime = time(); // 当前时间戳
        $statuses = $this->serverRepo->getOnlineStatuses(); // 获取所有服务器在线状态 [id => is_online]

        foreach ($statuses as $serverId => $isOnline) {
            if ($isOnline) {
                // 获取该服务器最新一条统计数据
                $latestStat = $this->serverRepo->getLatestStats()[$serverId] ?? null;
                if ($latestStat && ($currentTime - (int)$latestStat['timestamp'] > self::OFFLINE_THRESHOLD)) {
                    // 超过阈值未上报，标记为离线
                    $this->serverRepo->updateStatus($serverId, false, $currentTime);
                    error_log("Server '{$serverId}' marked as offline due to timeout.");
                }
            }
        }
    }

    /**
     * 处理故障事件并发送通知
     * - 如果服务器离线且无活跃故障记录，则创建新故障并发送离线警告
     * - 如果服务器恢复在线且有活跃故障记录，则关闭故障并发送恢复通知
     */
    private function processOutagesAndNotifications(): void {
        $servers = $this->serverRepo->getAllServers(); // 获取所有服务器信息
        $onlineStatuses = $this->serverRepo->getOnlineStatuses(); // 获取当前在线状态

        foreach ($servers as $server) {
            $serverId = $server['id'];
            $serverName = $server['name'];
            $isCurrentlyOnline = $onlineStatuses[$serverId] ?? false;

            // 查询该服务器是否有未结束的故障记录
            $activeOutage = $this->outagesRepo->getActiveOutageForServer($serverId);

            if (!$isCurrentlyOnline) {
                // 当前离线
                if (!$activeOutage) {
                    // 无活跃故障，说明是新的离线事件
                    $this->outagesRepo->createOutage($serverId, '服务器掉线', '服务器停止报告数据。');

					if ($this->enabledPush == 1) {
						// 发送 Telegram 离线警告
						$message = "🔴 *服务离线警告*\n\n服务器 `{$serverName}` (`{$serverId}`) 已停止响应。";
						$this->sendTelegramMessage($message);
					}
                }
            } else {
                // 当前在线
                if ($activeOutage) {
                    // 有活跃故障，说明服务器已恢复
                    $endTime = time();
                    $duration = $endTime - $activeOutage['start_time']; // 离线时长（秒）

                    $durationStr = $this->formatDuration($duration); // 格式化时长

                    // 更新故障记录结束时间
                    $this->outagesRepo->updateOutageEndTime($activeOutage['id'], $endTime);

					if ($this->enabledPush == 1) {
						// 发送 Telegram 恢复通知
						$message = "✅ *服务恢复通知*\n\n服务器 `{$serverName}` (`{$serverId}`) 已恢复在线。\n持续离线时间：约 {$durationStr}。";
						$this->sendTelegramMessage($message);
					}
                }
            }
        }
    }

    /**
     * 发送 Telegram 消息
     * @param string $message 要发送的消息内容（支持 Markdown）
     * @return bool 是否发送成功
     */
    private function sendTelegramMessage(string $message): bool {
        if (empty($this->botToken) || empty($this->chatId)) {
            return false; // 未配置 Telegram 参数，直接返回失败
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];

        // 创建 HTTP 请求上下文
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'ignore_errors' => true // 不抛出错误，手动处理
            ],
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context); // 发送请求

        if ($result === false) {
            error_log("Telegram API request failed.");
            return false;
        }

        // 解析返回结果
        $responseData = json_decode($result, true);
        if (!isset($responseData['ok']) || !$responseData['ok']) {
            error_log("Telegram API Error: " . ($responseData['description'] ?? 'Unknown error'));
            return false;
        }

        return true; // 发送成功
    }

    /**
     * 格式化时长（秒 → 人类可读）
     * @param int $duration 时长（秒）
     * @return string 格式化后的字符串，如“5 分钟”或“1.5 小时”
     */
    private function formatDuration(int $duration): string {
        if ($duration < 60) {
            return "{$duration} 秒";
        } elseif ($duration < 3600) {
            return round($duration / 60) . " 分钟";
        } else {
            return round($duration / 3600, 1) . " 小时";
        }
    }
}

/**
 * 脚本入口
 * 捕获初始化异常并记录日志
 */
try {
	$settingsRepo = new SettingsRepository($dbConfig)
	// 从数据库获取 Telegram 配置
	$telegram_config = array(
		'enabled' => $settingsRepo->getSetting('telegram_enabled'),
		'botToken' => $settingsRepo->getSetting('telegram_bot_token'),
		'chatId' => $settingsRepo->getSetting('telegram_chat_id')
	);
	$checkStatus = new CheckStatus($db_config, $telegram_config);
	$checkStatus->run();
} catch (\Exception $e) {
    error_log("check_status.php Initialization Error: " . $e->getMessage());
    exit(1); // 异常退出，确保 cron 能感知失败
}
