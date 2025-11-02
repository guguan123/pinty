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
	private bool $enabledTelegramPush;
	private string $telegramBotToken;
	private string $telegramChatId;

	public function __construct(array $dbConfig) {
		$this->serverRepo = new ServerRepository($dbConfig);
		$this->outagesRepo = new OutagesRepository($dbConfig);
		$this->settingsRepo = new SettingsRepository($dbConfig);

		$this->enabledTelegramPush = (bool) $this->settingsRepo->getSetting('telegram_enabled');
		if ($this->enabledTelegramPush) {
			$this->telegramBotToken = $this->settingsRepo->getSetting('telegram_bot_token') ?? '';
			$this->telegramChatId = $this->settingsRepo->getSetting('telegram_chat_id') ?? '';
			if (empty($this->telegramBotToken) || empty($this->telegramChatId)) {
				//error_log('Telegram bot token or chat ID is not configured.');
				$this->enabledTelegramPush = false; // 禁用推送
			}
		}
	}

	/**
	 * 核心检查方法：更新离线状态、处理故障并发送通知
	 * 可在任何脚本中调用，比如服务器上报后
	 */
	public function checkAndNotify(): void {
		$this->processOutagesAndNotifications();
		$this->updateOfflineStatuses();
	}

	/**
	 * 更新离线状态
	 */
	public function updateOfflineStatuses(): void {
		$currentTime = time();
		$statuses = $this->serverRepo->getOnlineStatuses();

		foreach ($statuses as $serverId => $isOnline) {
			if ($isOnline) {
				$latestStat = $this->serverRepo->getLatestStats()[$serverId] ?? null;
				$latestTimestamp = strtotime($latestStat['timestamp']);
				if ($latestStat && ($currentTime - $latestTimestamp > self::OFFLINE_THRESHOLD)) {
					$this->serverRepo->updateStatus($serverId, false, date('Y-m-d H:i:s'));
					//error_log("Server '{$serverId}' marked as offline due to timeout.");
				}
			}
		}
	}

	/**
	 * 处理故障记录和通知（假设状态已更新）
	 *
	 * 设计思路：
	 * 1. 一次性把「所有服务器」和「最新在线状态」全部拉回来，避免在循环里反复查库。
	 * 2. 以服务器为单位，判断「当前是否在线」与「是否已存在未结束的故障记录」。
	 * 3. 状态发生「离线→在线」或「在线→离线」切换时，才写库 + 发通知，避免重复打扰。
	 * 4. 离线→在线：计算本次离线持续时长，更新故障记录的 end_time，发恢复通知。
	 * 5. 在线→离线：新建一条故障记录，发离线警告。
	 * 6. 通知渠道可插拔，目前只实现 Telegram；$this->enabledTelegramPush 为总开关。
	 */
	public function processOutagesAndNotifications(): void {
		/* 1. 批量获取服务器列表与实时在线状态（内存中操作，减少 I/O） */
		$servers        = $this->serverRepo->getAllServers();      // 所有服务器基础信息
		$onlineStatuses = $this->serverRepo->getOnlineStatuses();  // 键为 server_id，值为 bool

		/* 2. 遍历每台服务器，判断是否需要创建或关闭故障记录 */
		foreach ($servers as $server) {
			$isCurrentlyOnline = $onlineStatuses[$server['id']] ?? FALSE;          // 当前是否在线
			$activeOutage      = $this->outagesRepo->getActiveOutageForServer($server['id']); // 未结束的故障

			/* 2.1 当前离线 */
			if ($isCurrentlyOnline != 1) {
				/* 如果还没有未结束的故障，则新建一条 */
				if (!$activeOutage) {
					$this->outagesRepo->createOutage(
						$server['id'],
						'服务器掉线',
						'服务器停止报告数据。'
					);

					/* 推送开关打开时，发离线警告 */
					if ($this->enabledTelegramPush) {
						$message = "🔴 *服务离线警告*\n\n"
								. "服务器 `{$server['name']}` (`{$server['id']}`) 已停止响应。";
						$this->sendTelegramMessage($message);
					}
				}
				/* 如果已存在未结束故障，说明早已记录，无需重复操作 */
			}
			/* 2.2 当前在线 */
			else {
				/* 若存在未结束的故障，说明刚刚恢复，需要“收尾” */
				if ($activeOutage) {
					$endDT = new DateTime('now');         // 当前时间
					$startDT = new DateTime($activeOutage['start_time']);

					$duration    = abs($endDT->getTimestamp() - $startDT->getTimestamp());
					$durationStr = $this->formatDuration($duration);

					/* 更新故障记录的结束时间 */
					$this->outagesRepo->updateOutageEndTime($activeOutage['id'], $endDT->format('Y-m-d H:i:s'));

					/* 推送开关打开时，发恢复通知 */
					if ($this->enabledTelegramPush) {
						$message = "✅ *服务恢复通知*\n\n"
								. "服务器 `{$server['name']}` (`{$server['id']}`) 已恢复在线。\n"
								. "持续离线时间：约 {$durationStr}。";
						$this->sendTelegramMessage($message);
					}
				}
				/* 若不存在未结束故障，说明一直正常，无需任何操作 */
			}
		}
	}

	/**
	 * 发送 Telegram 消息
	 * @param string $message 要发送的消息内容（支持 Markdown）
	 * @return bool 是否发送成功
	 */
	private function sendTelegramMessage(string $message): bool {
		$url = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";
		$data = [
			'chat_id' => $this->telegramChatId,
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
