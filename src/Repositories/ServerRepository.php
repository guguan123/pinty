<?php
// src/Repositories/ServerRepository.php

namespace GuGuan123\Pinty\Repositories;

use GuGuan123\Pinty\Database;
use Exception;  // 显式use全局Exception

class ServerRepository {
    private $db;

    public function __construct($dbConfig) {
        $this->db = Database::getInstance($dbConfig);
    }

    // 获取所有服务器
    public function getAllServers() {
        $sql = "SELECT * FROM servers ORDER BY id ASC";
        return $this->db->fetchAll($sql);
    }

    // 更新服务器
    public function updateServer(array $data) {
        $sql = "UPDATE servers SET 
                    name = :name, 
                    ip = :ip,
                    longitude = :longitude, 
                    latitude = :latitude, 
                    intro = :intro,
                    expiry_date = :expiry_date,
                    country_code = :country_code
                WHERE id = :id";
        $params = [
            ':id' => $data['id'],
            ':name' => $data['name'],
            ':ip' => $data['ip'],
            ':longitude' => $data['longitude'],
            ':latitude' => $data['latitude'],
            ':intro' => $data['intro'],
            ':expiry_date' => $data['expiry_date'],
            ':country_code' => $data['country_code']
        ];
        return $this->db->execute($sql, $params) > 0; // 返回是否更新成功
    }

    // 获取服务器历史统计（带参数）
    public function getServerHistory($serverId, int $limit = 20) {
        $sql = "SELECT * FROM server_stats WHERE server_id = ? ORDER BY timestamp DESC LIMIT {$limit}";
        $params = [$serverId];
        $history = $this->db->fetchAll($sql, $params);

        // 类型转换
        return array_map(function($record) {
            return [
                'cpu_usage' => (float)$record['cpu_usage'],
                'mem_usage_percent' => (float)$record['mem_usage_percent'],
                'disk_usage_percent' => (float)$record['disk_usage_percent'],
                'load_avg' => (float)$record['load_avg'],
                'net_up_speed' => (int)$record['net_up_speed'],
                'net_down_speed' => (int)$record['net_down_speed'],
                'total_up' => (int)$record['total_up'],
                'total_down' => (int)$record['total_down'],
                'timestamp' => (int)$record['timestamp'],
                'processes' => (int)$record['processes'],
                'connections' => (int)$record['connections']
            ];
        }, $history);
    }

    /**
     * 获取所有服务器的在线状态 [id => is_online]
     */
    public function getOnlineStatuses() {
        $sql = "SELECT id, is_online FROM server_status";
        $statuses = $this->db->fetchAll($sql);
        $result = [];
        foreach ($statuses as $status) {
            $result[$status['id']] = (bool)$status['is_online'];
        }
        return $result;
    }

    /**
     * 获取所有服务器的最新统计，按server_id索引
     */
    public function getLatestStats() {
        $driver = $this->db->getDriverName();
        if ($driver === 'pgsql') {
            $sql = "SELECT DISTINCT ON (server_id) * FROM server_stats ORDER BY server_id, timestamp DESC";
        } else {  // MySQL 和 SQLite 通用子查询
            $sql = "SELECT s.* FROM server_stats s JOIN (SELECT server_id, MAX(timestamp) AS max_ts FROM server_stats GROUP BY server_id) AS m ON s.server_id = m.server_id AND s.timestamp = m.max_ts";
        }
        $stats = $this->db->fetchAll($sql);
        $result = [];
        foreach ($stats as $stat) {
            $result[$stat['server_id']] = $stat;
        }
        return $result;
    }

    /**
     * 检查服务器ID是否存在。
     */
    public function existsById($id) {
        $sql = "SELECT id FROM servers WHERE id = :id LIMIT 1";
        $params = [':id' => $id];
        return !empty($this->db->fetchAll($sql, $params));
    }

    /**
     * 获取单个服务器详情。
     */
    public function getServerById($id) {
        $sql = "SELECT * FROM servers WHERE id = :id LIMIT 1";
        $params = [':id' => $id];
        return $this->db->fetchAll($sql, $params)[0] ?? null;
    }

    /**
     * 创建新服务器（生成secret）。
     */
    public function createServer($data) {
        $secret = bin2hex(random_bytes(16));  // 生成16字节随机secret
        $sql = "INSERT INTO servers (id, name, ip, latitude, longitude, intro, expiry_date, tags, secret, country_code) VALUES (:id, :name, :ip, :latitude, :longitude, :intro, :expiry_date, :tags, :secret, :country_code)";
        // 修复：动态添加:前缀到data键，确保PDO绑定正确（兼容所有DB）
        $params = [':id' => $data['id'], ':secret' => $secret];
        foreach ($data as $key => $value) {
            $params[':' . $key] = $value;
        }
        $this->db->execute($sql, $params);
    }

    /**
     * 删除服务器及相关数据（级联）。
     */
    public function deleteServer($id) {
        $this->db->getPdo()->beginTransaction();  // 事务
        try {
            // 删除stats
            $this->db->execute("DELETE FROM server_stats WHERE server_id = :id", [':id' => $id]);
            // 删除status
            $this->db->execute("DELETE FROM server_status WHERE id = :id", [':id' => $id]);
            // 删除outages
            $this->db->execute("DELETE FROM outages WHERE server_id = :id", [':id' => $id]);
            // 删除servers
            $this->db->execute("DELETE FROM servers WHERE id = :id", [':id' => $id]);
            $this->db->getPdo()->commit();
        } catch (Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }
    }

    /**
     * 验证服务器secret和IP（抛异常如果无效）。
     * @param string $serverId
     * @param string $secret
     * @param string|null $requestIp
     * @throws return
     */
    public function validateServer($serverId, $secret, $requestIp = null) {
        $server = $this->getServerById($serverId);
        if (!$server) {
            return array('code' => 404, 'msg' => 'Invalid server_id.');
        }

        // Secret validation
        if (empty($server['secret']) || !hash_equals($server['secret'], $secret)) {
            return array('code' => 403, 'msg' => 'Invalid secret.');
        }

        // IP validation
        $stored_ip = $server['ip'] ?? null;
        if (!empty($stored_ip) && $stored_ip !== $requestIp) {
            return array('code' => 403, 'msg' => 'IP address mismatch.');
        }

        return array('code' => 200, 'msg' => 'OK');
    }

    /**
     * 更新服务器硬件信息。
     * @param string $serverId
     * @param int|null $cpuCores
     * @param string|null $cpuModel
     * @param int|null $memTotal
     * @param int|null $diskTotal
     * @param string|null $system
     * @param string|null $arch
     */
    public function updateHardware($serverId, $cpuCores = null, $cpuModel = null, $memTotal = null, $diskTotal = null, $system = null, $arch = null) {
        $sql = "UPDATE servers SET 
                    cpu_cores = COALESCE(?, cpu_cores),
                    cpu_model = COALESCE(?, cpu_model),
                    mem_total = COALESCE(?, mem_total),
                    disk_total = COALESCE(?, disk_total),
                    system = COALESCE(?, system),
                    arch = COALESCE(?, arch)
                WHERE id = ?";
        $params = [$cpuCores, $cpuModel, $memTotal, $diskTotal, $system, $arch, $serverId];
        $this->db->execute($sql, $params);
    }

    /**
     * 更新服务器在线状态（DB-specific UPSERT）。
     * @param string $serverId
     * @param bool $isOnline
     * @param int|null $lastChecked
     */
    public function updateStatus($serverId, $isOnline = true, $lastChecked = null) {
        $driver = $this->db->getDriverName();
        $isOnlineVal = $isOnline ? 1 : 0;
        $lastCheckedVal = $lastChecked ?? time();

        if ($driver === 'pgsql') {
            $sql = "INSERT INTO server_status (id, is_online, last_checked) VALUES (?, ?, ?) ON CONFLICT (id) DO UPDATE SET is_online = ?, last_checked = ?";
            $params = [$serverId, $isOnlineVal, $lastCheckedVal, $isOnlineVal, $lastCheckedVal];
        } elseif ($driver === 'mysql') {
            $sql = "INSERT INTO server_status (id, is_online, last_checked) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE is_online = VALUES(is_online), last_checked = VALUES(last_checked)";
            $params = [$serverId, $isOnlineVal, $lastCheckedVal];
        } else { // sqlite
            $sql = "INSERT OR REPLACE INTO server_status (id, is_online, last_checked) VALUES (?, ?, ?)";
            $params = [$serverId, $isOnlineVal, $lastCheckedVal];
        }
        $this->db->execute($sql, $params);
    }
}
