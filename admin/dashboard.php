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

$message = '';
$error_message = '';

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

    // 保存服务器
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_server'])) {
        if (empty($_POST['id'])) {
            throw new Exception('服务器ID不能为空。');
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
            'price_usd_monthly' => !empty($_POST['price_usd_monthly']) ? (float)$_POST['price_usd_monthly'] : null,
            'price_usd_yearly' => !empty($_POST['price_usd_yearly']) ? (float)$_POST['price_usd_yearly'] : null,
            'tags' => !empty($_POST['tags']) ? implode(',', array_map('trim', explode(',', $_POST['tags']))) : null,
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

    // 保存 Telegram 设置
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_telegram'])) {
        $settingsRepo->saveSetting('telegram_bot_token', $_POST['telegram_bot_token'] ?? '');
        $settingsRepo->saveSetting('telegram_chat_id', $_POST['telegram_chat_id'] ?? '');
        $message = "Telegram 设置已保存！";
    }

    // Fetch data for display
    $servers = $serverRepo->getAllServers();
    $telegram_bot_token = $settingsRepo->getSetting('telegram_bot_token') ?: '';
    $telegram_chat_id = $settingsRepo->getSetting('telegram_chat_id') ?: '';

} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());  // 日志化
    $error_message = "操作失败: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Plexure Monitor</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 2rem; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dee2e6; padding-bottom: 1rem; margin-bottom: 2rem; }
        h1, h2 { color: #111; margin-top: 0; }
        h2 { border-bottom: 1px solid #eee; padding-bottom: 0.5rem; margin-top: 2rem; }
        a.logout { text-decoration: none; background: #343a40; color: #fff; padding: 0.5rem 1rem; border-radius: 5px; transition: background 0.2s; }
        a.logout:hover { background: #495057; }
        .message { background: #28a745; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        .error-message { background: #dc3545; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; font-size: 0.9em; }
        th, td { text-align: left; padding: 0.9rem 0.7rem; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        th { background-color: #f8f9fa; font-weight: 600; }
        tr:hover { background-color: #f8f9fa; }
        form { margin: 0; }
        label { font-weight: 600; display: block; margin-bottom: 0.4rem; font-size: 0.9em; }
        input[type="text"], input[type="number"], input[type="date"], textarea { width: 100%; padding: 0.6rem; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s; }
        input:focus, textarea:focus { border-color: #80bdff; outline: 0; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        input[readonly] { background: #e9ecef; cursor: not-allowed; }
        button { background-color: #007bff; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 5px; cursor: pointer; transition: background-color 0.2s; font-weight: 600; }
        button:hover { background-color: #0056b3; }
        button.delete { background-color: #dc3545; }
        button.delete:hover { background-color: #c82333; }
        button.secondary { background-color: #6c757d; }
        button.secondary:hover { background-color: #5a6268; }
        .section { margin-bottom: 3rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.2rem; align-items: flex-start; margin-bottom: 1.2rem; }
        details { border: 1px solid #dee2e6; padding: 1.5rem; border-radius: 5px; margin-bottom: 1rem; background: #fdfdfd; }
        summary { font-weight: 600; cursor: pointer; font-size: 1.1em; }
        .actions-cell { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .secret-wrapper { display: flex; align-items: center; gap: 0.5rem; }
        .secret-wrapper input { flex-grow: 1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Admin Dashboard</h1>
            <a href="logout.php" class="logout">Logout</a>
        </div>
        
        <?php if ($message): ?><p class="message"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
        <?php if ($error_message): ?><p class="error-message"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>

        <div class="section">
            <details>
                <summary>Telegram 通知设置</summary>
                <form action="dashboard.php" method="post" style="margin-top: 1.5rem;">
                    <p>当服务器掉线时，系统会自动发送通知。请按照部署指南获取Token和Chat ID。</p>
                    <div class="form-grid">
                        <div><label for="tg-token">Bot Token</label><input id="tg-token" type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($telegram_bot_token); ?>" placeholder="例如: 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"></div>
                        <div><label for="tg-chat">Channel/User ID</label><input id="tg-chat" type="text" name="telegram_chat_id" value="<?php echo htmlspecialchars($telegram_chat_id); ?>" placeholder="例如: -100123456789 or 12345678"></div>
                    </div>
                    <button type="submit" name="save_telegram">保存Telegram设置</button>
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
                        <div><label>服务器 IP 地址</label><input type="text" name="ip" placeholder="留空则不验证IP"></div>
                        <div><label>月付价格 (USD)</label><input type="number" step="0.01" name="price_usd_monthly"></div>
                    </div>
                    <div class="form-grid">
                        <div><label>年付价格 (USD)</label><input type="number" step="0.01" name="price_usd_yearly"></div>
                        <div><label>经度 (地图X坐标)</label><input type="number" step="any" name="longitude" placeholder="例如: 1083"></div>
                        <div><label>纬度 (地图Y坐标)</label><input type="number" step="any" name="latitude" placeholder="例如: 228"></div>
                        <div><label>标签 (逗号分隔)</label><input type="text" name="tags" placeholder="例如: 亚洲,主力,高防"></div>
                    </div>
                    <div><label>简介</label><textarea name="intro" rows="3"></textarea></div>
                    <button type="submit" name="save_server">保存服务器</button>
                    <button type="button" class="secondary" id="cancel-edit-btn" style="display: none;">取消编辑</button>
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
                            <td><?php echo htmlspecialchars($server['tags']); ?></td>
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
                                     <button type="submit" name="generate_secret" class="secondary">生成新密钥</button>
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
                    addEditForm.querySelector('input[name="longitude"]').value = server.longitude;
                    addEditForm.querySelector('input[name="latitude"]').value = server.latitude;
                    addEditForm.querySelector('input[name="ip"]').value = server.ip; 
                    addEditForm.querySelector('input[name="tags"]').value = server.tags;
                    addEditForm.querySelector('textarea[name="intro"]').value = server.intro;
                    addEditForm.querySelector('input[name="price_usd_yearly"]').value = server.price_usd_yearly;
                    
                    if (server.expiry_date) {
                        const date = new Date(server.expiry_date * 1000);
                        addEditForm.querySelector('input[name="expiry_date"]').value = date.toISOString().split('T')[0];
                    } else {
                        addEditForm.querySelector('input[name="expiry_date"]').value = '';
                    }

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
