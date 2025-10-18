<?php
// admin/dashboard.php

session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
	header('Location: index.php');
	exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use GuGuan123\Pinty\Repositories\ServerRepository;
use GuGuan123\Pinty\Repositories\SettingsRepository;

$message = false;
$error_message = false;

try {
	$serverRepo = new ServerRepository($db_config);
	$settingsRepo = new SettingsRepository($db_config);

	// 删除服务器
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_server'])) {
		$serverId = $_POST['id'] ?? '';
		if (empty($serverId)) {
			throw new Exception('服务器ID不能为空。');
		}
		$serverName = $serverRepo->getServerById($serverId)['name'] ?? $serverId;  // 获取名称用于消息
		$serverRepo->deleteServer($serverId);  // 级联删除
		$message = "服务器 '{$serverName}' 及其所有数据已成功删除！";
	}

	// Handle New Secret Generation
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_secret'])) {
		$new_secret = bin2hex(random_bytes($length = 32 / 2));
		$stmt = $pdo->prepare("UPDATE servers SET secret = ? WHERE id = ?");
		$stmt->execute([$new_secret, $_POST['generate_secret_id']]);
		$message = "为服务器 '{$_POST['generate_secret_id']}' 生成了新的密钥！";
	}

	// 保存服务器
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_server'])) {
		if (empty($_POST['id'])) {
			throw new Exception('服务器ID不能为空。');
		}
		$country_code = strtoupper(trim($_POST['country_code']));
		if (!empty($country_code) && !preg_match('/^[A-Z]{2}$/', $country_code)) {
			throw new Exception("国家代码必须是两位英文字母。");
		}

		// 准备数据
		$data = [
			'id' => $_POST['id'],
			'name' => $_POST['name'] ?? '',
			'ip' => $_POST['ip'] ?? null,
			'latitude' => !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null,
			'longitude' => !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null,
			'intro' => $_POST['intro'] ?? '',
			'expiry_date' => empty($_POST['expiry_date']) ? null : strtotime($_POST['expiry_date']),
			'tags' => !empty($_POST['tags']) ? implode(',', array_map('trim', explode(',', $_POST['tags']))) : null,
			'country_code' => $country_code ?? NULL
		];

		if (empty($_POST['is_editing'])) {
			// 检查ID是否存在
			if ($serverRepo->existsById($data['id'])) {
				throw new Exception("服务器 ID '{$data['id']}' 已存在，请使用不同的ID。");
			}
			$serverRepo->createServer($data);  // 生成secret并插入
			$message = "服务器 '{$data['name']}' 已成功添加！";
		} else {
			$serverRepo->updateServer($data);
			$message = "服务器 '{$data['name']}' 已成功更新！";
		}
	}
	
	// 保存通用设置
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
		$settingsRepo->saveSetting('site_name', $_POST['site_name'] ?? NULL);
		$message = "通用设置已保存！";
	}

	// 保存 Telegram 设置
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_telegram'])) {
		$settingsRepo->saveSetting('telegram_bot_token', $_POST['telegram_bot_token'] ?? NULL);
		$settingsRepo->saveSetting('telegram_chat_id', $_POST['telegram_chat_id'] ?? NULL);
		$settingsRepo->saveSetting('telegram_enabled', isset($_POST['telegram_enabled']) ? 1 : 0);
		$message = "Telegram 设置已保存！";
	}

	// 获取所有服务器列表
	$servers = $serverRepo->getAllServers();

	// 获取设置信息
	$site_name = $settingsRepo->getSetting('site_name') ?: '';
	$telegram_bot_token = $settingsRepo->getSetting('telegram_bot_token') ?: '';
	$telegram_chat_id = $settingsRepo->getSetting('telegram_chat_id') ?: '';
	$telegram_enabled = (bool)($settingsRepo->getSetting('telegram_enabled') ?? false);

} catch (Exception $e) {
	$error_message = "操作失败: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>管理员面板 - Pinty Monitor</title>
	<link rel="stylesheet" href="../assets/css/admin-dash.css">
</head>
<body>
	<div class="container">
		<div class="header">
			<h1>管理员面板</h1>
			<a href="logout.php" class="logout">登出</a>
		</div>
		
		<?php if ($message): ?><p class="message"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
		<?php if ($error_message): ?><p class="error-message"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>

		<div class="section">
			<details>
				<summary>通用设置</summary>
				<form action="dashboard.php" method="post" style="margin-top: 1.5rem;">
					<div class="form-grid">
						<div><label for="site_name">站点名称</label><input id="site_name" type="text" name="site_name" value="<?php echo htmlspecialchars($site_name); ?>"></div>
					</div>
					<button type="submit" name="save_settings">保存设置</button>
				</form>
			</details>
		</div>

		<div class="section">
			<details>
				<summary>Telegram 通知设置</summary>
				<form action="dashboard.php" method="post" style="margin-top: 1.5rem;">
					<p>当服务器掉线时，系统会自动发送通知。请按照部署指南获取Token和Chat ID。</p>
					<div class="form-grid">
						<div><label for="tg-enabled">启用 Telegram 通知</label><input id="tg-enabled" type="checkbox" name="telegram_enabled" <?php echo $telegram_enabled ? 'checked' : ''; ?>></div>
						<div><label for="tg-token">Telegram Bot Token</label><input id="tg-token" type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($telegram_bot_token); ?>" placeholder="例如: 123456:ABC-DEF..."></div>
						<div><label for="tg-chat">Telegram Channel/User ID</label><input id="tg-chat" type="text" name="telegram_chat_id" value="<?php echo htmlspecialchars($telegram_chat_id); ?>" placeholder="例如: -100123456789 or 12345678"></div>
					</div>
					<button type="submit" name="save_telegram">保存设置</button>
				</form>
			</details>
		</div>

		<div class="section">
			<details id="add-edit-details">
				<summary>添加新服务器</summary>
				<form id="add-edit-form" action="dashboard.php" method="post" style="margin-top: 1.5rem;">
					<input type="hidden" name="is_editing" value="">
					<div class="form-grid">
						<div><label>服务器 ID (唯一, 英文)</label><input type="text" name="id" required></div>
						<div><label>服务器名称</label><input type="text" name="name" required></div>
						<div><label>国家/地区代码 (两位字母)</label><input type="text" name="country_code" placeholder="例如: CN, JP, US" maxlength="2" style="text-transform:uppercase"></div>
						<div><label>服务器 IP 地址</label><input type="text" name="ip" placeholder="留空则不验证IP"></div>
					</div>
					<div class="form-grid">
						<div><label>经度 (地图X坐标)</label><input type="number" step="any" name="longitude" placeholder="例如: 1083"></div>
						<div><label>纬度 (地图Y坐标)</label><input type="number" step="any" name="latitude" placeholder="例如: 228"></div>
						<div><label>标签 (逗号分隔)</label><input type="text" name="tags" placeholder="例如: 亚洲,主力,高防"></div>
					</div>
					<div><label>简介</label><textarea name="intro" rows="3"></textarea></div>
					<div style="margin-top: 1.2rem;">
						<button type="submit" name="save_server">保存服务器</button>
						<button type="button" class="secondary" id="cancel-edit-btn" style="display: none;">取消编辑</button>
					</div>
				</form>
			</details>
		</div>

		<div class="section">
			<h2>已管理的服务器</h2>
			<div style="overflow-x: auto;">
				<table>
					<thead><tr><th>ID</th><th>名称</th><th>标签</th><th>密钥</th><th>操作</th></tr></thead>
					<tbody>
						<?php foreach ($servers as $server): ?>
						<tr>
							<td><strong><?php echo htmlspecialchars($server['id']); ?></strong></td>
							<td><?php echo htmlspecialchars($server['name']); ?></td>
							<td><?php echo htmlspecialchars($server['tags'] ?? ''); ?></td>
							<td>
								<div class="secret-wrapper">
									<input type="text" id="secret-<?php echo htmlspecialchars($server['id']); ?>" value="<?php echo htmlspecialchars($server['secret']); ?>" readonly>
									<button class="secondary copy-btn" data-clipboard-target="#secret-<?php echo htmlspecialchars($server['id']); ?>">复制</button>
								</div>
							</td>
							<td class="actions-cell">
								<button class="edit-btn" data-id="<?php echo htmlspecialchars($server['id']); ?>">修改</button>
								<form action="dashboard.php" method="post" onsubmit="return confirm('确定为 \'<?php echo htmlspecialchars($server['id']); ?>\' 生成一个新的密钥吗？旧密钥将立即失效！');" style="margin:0;">
									 <input type="hidden" name="generate_secret_id" value="<?php echo htmlspecialchars($server['id']); ?>">
									 <button type="submit" name="generate_secret" class="secondary">新密钥</button>
								 </form>
								<form action="dashboard.php" method="post" onsubmit="return confirm('确定删除这台服务器及其所有监控数据吗？');" style="margin: 0;">
									<input type="hidden" name="id" value="<?php echo htmlspecialchars($server['id']); ?>">
									<button type="submit" name="delete_server" class="delete">删除</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	
	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const addEditDetails = document.getElementById('add-edit-details');
			const addEditForm = document.getElementById('add-edit-form');
			const formSummary = addEditDetails.querySelector('summary');
			const idInput = addEditForm.querySelector('input[name="id"]');
			const isEditingInput = addEditForm.querySelector('input[name="is_editing"]');
			const cancelBtn = document.getElementById('cancel-edit-btn');
			const serversData = <?php echo json_encode($servers); ?>;

			document.querySelectorAll('.edit-btn').forEach(button => {
				button.addEventListener('click', function() {
					const serverId = this.dataset.id;
					const server = serversData.find(s => s.id === serverId);
					if (!server) {
						alert('找不到服务器数据！');
						return;
					}

					formSummary.textContent = `正在编辑: ${server.name}`;
					idInput.value = server.id;
					idInput.readOnly = true;
					isEditingInput.value = '1';
					
					addEditForm.querySelector('input[name="name"]').value = server.name;
					addEditForm.querySelector('input[name="country_code"]').value = server.country_code || '';
					addEditForm.querySelector('input[name="longitude"]').value = server.longitude;
					addEditForm.querySelector('input[name="latitude"]').value = server.latitude;
					addEditForm.querySelector('input[name="ip"]').value = server.ip; 
					addEditForm.querySelector('input[name="tags"]').value = server.tags;
					addEditForm.querySelector('textarea[name="intro"]').value = server.intro;
					
					addEditDetails.open = true;
					cancelBtn.style.display = 'inline-block';
					window.scrollTo({ top: addEditDetails.offsetTop, behavior: 'smooth' });
				});
			});

			function resetForm() {
				formSummary.textContent = '添加新服务器';
				addEditForm.reset();
				idInput.readOnly = false;
				isEditingInput.value = '';
				cancelBtn.style.display = 'none';
			}
			cancelBtn.addEventListener('click', resetForm);
			addEditDetails.addEventListener('toggle', function() {
				if (!this.open) {
					resetForm();
				}
			});

			document.querySelectorAll('.copy-btn').forEach(button => {
				button.addEventListener('click', function() {
					const targetInput = document.querySelector(this.dataset.clipboardTarget);
					if (targetInput) {
						targetInput.select();
						targetInput.setSelectionRange(0, 99999);
						try {
							document.execCommand('copy');
							const originalText = this.textContent;
							this.textContent = '已复制!';
							setTimeout(() => { this.textContent = originalText; }, 2000);
						} catch (err) {
							alert('复制失败，请手动复制。');
						}
					}
				});
			});
		});
	</script>
</body>
</html>
