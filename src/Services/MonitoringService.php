<?php
// src/Services/MonitoringService.php

namespace GuGuan123\Pinty\Services;

use GuGuan123\Pinty\Repositories\ServerRepository;
use GuGuan123\Pinty\Repositories\OutagesRepository;
use GuGuan123\Pinty\Repositories\SettingsRepository;

class MonitoringService {
	private const OFFLINE_THRESHOLD = 35; // 离线阈值（秒）

	private ServerRepository $serverRepo;
	private OutagesRepository $outagesRepo;
	private SettingsRepository $settingsRepo;
	private bool $enabledPush;
	private string $botToken;
	private string $chatId;
	private string $executionMode;
	private bool $isCronExecution; // 新增：标记是否为cron.php执行

	public function __construct(array $dbConfig) {
		$this->serverRepo = new ServerRepository($dbConfig);
		$this->outagesRepo = new OutagesRepository($dbConfig);
		$this->settingsRepo = new SettingsRepository($dbConfig);

		$this->enabledPush = (bool) $this->settingsRepo->getSetting('telegram_enabled');
		if ($this->enabledPush) {
			$this->botToken = $this->settingsRepo->getSetting('telegram_bot_token') ?? '';
			$this->chatId = $this->settingsRepo->getSetting('telegram_chat_id') ?? '';
			if (empty($this->botToken) || empty($this->chatId)) {
				error_log('Telegram bot token or chat ID is not configured.');
				$this->enabledPush = false; // 禁用推送
			}
		}
	}

	/**
	 * 核心检查方法：更新离线状态、处理故障并发送通知
	 * 可在任何脚本中调用，比如服务器上报后
	 */
	public function checkAndNotify(): void {
		try {
			$this->updateOfflineStatuses();
			$this->processOutagesAndNotifications();
		} catch (\Exception $e) {
			error_log("MonitoringService Error: " . $e->getMessage());
		}
	}

	/**
	 * 更新离线状态（不处理通知）
	 */
	public function updateOfflineStatuses(): void {
		$currentTime = time();
		$statuses = $this->serverRepo->getOnlineStatuses();

		foreach ($statuses as $serverId => $isOnline) {
			if ($isOnline) {
				$latestStat = $this->serverRepo->getLatestStats()[$serverId] ?? null;
				if ($latestStat && ($currentTime - (int)$latestStat['timestamp'] > self::OFFLINE_THRESHOLD)) {
					$this->serverRepo->updateStatus($serverId, false, $currentTime);
					error_log("Server '{$serverId}' marked as offline due to timeout.");
				}
			}
		}
	}

	/**
	 * 处理故障记录和通知（假设状态已更新）
	 */
	public function processOutagesAndNotifications(): void {
		$servers = $this->serverRepo->getAllServers();
		$onlineStatuses = $this->serverRepo->getOnlineStatuses();

		foreach ($servers as $server) {
			$isCurrentlyOnline = $onlineStatuses[$server['id']] ?? false;
			$activeOutage = $this->outagesRepo->getActiveOutageForServer($server['id']);

			if (!$isCurrentlyOnline) {
				if (!$activeOutage) {
					$this->outagesRepo->createOutage($server['id'], '服务器掉线', '服务器停止报告数据。');
					if ($this->enabledPush) {
						$message = "🔴 *服务离线警告*\n\n服务器 `{$server['name']}` (`{$server['id']}`) 已停止响应。";
						$this->sendTelegramMessage($message);
					}
				}
			} else {
				if ($activeOutage) {
					$endTime = time();
					$duration = $endTime - $activeOutage['start_time'];
					$durationStr = $this->formatDuration($duration);
					$this->outagesRepo->updateOutageEndTime($activeOutage['id'], $endTime);

					if ($this->enabledPush) {
						$message = "✅ *服务恢复通知*\n\n服务器 `{$server['name']}` (`{$server['id']}`) 已恢复在线。\n持续离线时间：约 {$durationStr}。";
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
		$url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
		$data = [
			'chat_id' => $this->chatId,
			'text' => $message,
			'parse_mode' => 'Markdown'
		];

		$options = [
			'http' => [
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'POST',
				'content' => http_build_query($data),
				'ignore_errors' => true
			],
		];
		$context = stream_context_create($options);
		$result = @file_get_contents($url, false, $context);

		if ($result === false) {
			error_log("Telegram API request failed.");
			return false;
		}

		$responseData = json_decode($result, true);
		if (!isset($responseData['ok']) || !$responseData['ok']) {
			error_log("Telegram API Error: " . ($responseData['description'] ?? 'Unknown error'));
			return false;
		}

		return true;
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
