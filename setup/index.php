<?php
// setup/index.php - Pinty 探针系统安装脚本

session_start();
$step = $_SESSION['setup_step'] ?? 1;
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // 第一步：收集配置
        $db_type = $_POST['db_type'] ?? '';
        $db_host = $_POST['db_host'] ?? '';
        $db_port = $_POST['db_port'] ?? '';
        $db_name = $_POST['db_name'] ?? '';
        $db_user = $_POST['db_user'] ?? '';
        $db_pass = $_POST['db_pass'] ?? '';
        $admin_username = $_POST['admin_username'] ?? '';
        $admin_password = $_POST['admin_password'] ?? '';

        // 验证
        if (empty($db_type) || empty($db_host) || empty($db_name) || empty($db_user) || empty($admin_username) || empty($admin_password)) {
            $error = '所有字段均为必填。';
        } elseif (!in_array($db_type, ['mysql', 'pgsql', 'sqlite'])) {
            $error = '无效的数据库类型。';
        } else {
            // 生成config.php
            $config_content = generateConfig($db_type, $db_host, $db_port, $db_name, $db_user, $db_pass);
            if (file_put_contents(__DIR__ . '/../config.php', $config_content) === false) {
                $error = '无法写入 config.php，请检查权限。';
            } else {
                // 存储到session
                $_SESSION['setup_config'] = [
                    'db_type' => $db_type,
                    'db_host' => $db_host,
                    'db_port' => $db_port,
                    'db_name' => $db_name,
                    'db_user' => $db_user,
                    'db_pass' => $db_pass,
                    'admin_username' => $admin_username,
                    'admin_password' => password_hash($admin_password, PASSWORD_DEFAULT)
                ];
                $step = 2;
                $_SESSION['setup_step'] = 2;
            }
        }
    } elseif ($step === 2) {
        // 第二步：执行安装
        $config = $_SESSION['setup_config'] ?? [];
        if (empty($config)) {
            $error = '配置丢失，请重新开始。';
        } else {
            try {
                // 连接DB
                $pdo = createPdoConnection($config);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // 读取并执行SQL文件
                $sql_file = __DIR__ . '/' . $config['db_type'] . '.sql';
                if (!file_exists($sql_file)) {
                    throw new Exception('SQL文件不存在: ' . $sql_file);
                }
                $sql_content = file_get_contents($sql_file);
                if ($sql_content === false) {
                    throw new Exception('无法读取SQL文件。');
                }

                // 执行SQL（多语句支持）
                $statements = splitSqlStatements($sql_content);
                foreach ($statements as $statement) {
                    if (!empty(trim($statement))) {
                        $pdo->exec($statement);
                    }
                }

                // 插入默认管理员用户
                $hashed_pass = $config['admin_password'];
                $sql_user = "INSERT INTO users (username, password) VALUES (?, ?)";
                $stmt = $pdo->prepare($sql_user);
                $stmt->execute([$config['admin_username'], $hashed_pass]);

                $success = true;
                unset($_SESSION['setup_config']);
                unset($_SESSION['setup_step']);
            } catch (Exception $e) {
                $error = '安装失败: ' . $e->getMessage();
            }
        }
    }
}

if ($success) {
    // 安装成功，重定向到admin
    header('Location: ../admin/index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>Pinty 安装</title>
    <style>body { font-family: Arial; max-width: 600px; margin: 50px auto; } form { margin-top: 20px; } input, select { width: 100%; padding: 8px; margin: 5px 0; } button { padding: 10px 20px; background: #007cba; color: white; border: none; cursor: pointer; }</style>
</head>
<body>
    <h1>Pinty 探针系统安装</h1>
    <?php if ($error): ?><p style="color: red;"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>

    <?php if ($step === 1): ?>
        <form method="POST">
            <h2>数据库配置</h2>
            <label>数据库类型:</label>
            <select name="db_type" required>
                <option value="">选择类型</option>
                <option value="mysql">MySQL</option>
                <option value="pgsql">PostgreSQL</option>
                <option value="sqlite">SQLite</option>
            </select>
            <label>主机 (Host):</label>
            <input type="text" name="db_host" value="localhost" required>
            <label>端口 (Port):</label>
            <input type="number" name="db_port" value="3306" required>
            <label>数据库名 (DB Name):</label>
            <input type="text" name="db_name" value="pinty_db" required>
            <label>用户名 (User):</label>
            <input type="text" name="db_user" value="root" required>
            <label>密码 (Password):</label>
            <input type="password" name="db_pass" required>

            <h2>管理员账户</h2>
            <label>用户名:</label>
            <input type="text" name="admin_username" required>
            <label>密码:</label>
            <input type="password" name="admin_password" required>

            <button type="submit">下一步：生成配置</button>
        </form>
    <?php elseif ($step === 2): ?>
        <p>正在安装... 请稍候。</p>
        <form method="POST">
            <button type="submit">执行安装</button>
        </form>
    <?php endif; ?>
</body>
</html>

<?php
// 辅助函数（放在文件末尾）
function generateConfig($type, $host, $port, $name, $user, $pass) {
    $config = [
        'type' => $type,
        $type => [
            'host' => $host,
            'port' => (int)$port,
            'dbname' => $name,
            'user' => $user,
            'password' => $pass
        ]
    ];
    if ($type === 'sqlite') {
        $config[$type]['path'] = __DIR__ . '/../pinty.db';
    }
    return '<?php' . PHP_EOL . '$db_config = ' . var_export($config, true) . ';' . PHP_EOL;
}

function createPdoConnection($config) {
    $type = $config['db_type'];
    if ($type === 'pgsql') {
        $dsn = "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}";
        return new \PDO($dsn, $config['db_user'], $config['db_pass']);
    } elseif ($type === 'mysql') {
        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";
        return new \PDO($dsn, $config['db_user'], $config['db_pass']);
    } else { // sqlite
        $dsn = 'sqlite:' . $config['sqlite']['path'];
        $pdo = new \PDO($dsn);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        return $pdo;
    }
}

function splitSqlStatements($sql) {
    // 简单SQL分割（假设;分隔语句，无嵌套）
    return array_map('trim', explode(';', $sql));
}
