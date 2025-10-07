<?php
// src/Repositories/ServerRepository.php - 封装服务器相关操作

namespace GuGuan123\Pinty\Repositories;

use GuGuan123\Pinty\Database;

class ServerRepository {
    private $db;

    public function __construct($dbConfig) {
        $this->db = Database::getInstance($dbConfig);
    }

    // 获取所有服务器
    public function getAllServers() {
        $sql = "SELECT id, name, intro, tags, price_usd_yearly, latitude, longitude FROM servers ORDER BY id ASC";
        return $this->db->fetchAll($sql);
    }

    // 更新服务器
    public function updateServer($id, $data) {
        $sql = "UPDATE servers SET 
                    name = :name, 
                    ip = :ip, 
                    port = :port, 
                    longitude = :longitude, 
                    latitude = :latitude, 
                    intro = :intro, 
                    price_usd_yearly = :price_usd_yearly, 
                    expiry_date = :expiry_date 
                WHERE id = :id";
        $params = [
            ':id' => $id,
            ':name' => $data['name'],
            ':ip' => $data['ip'],
            ':port' => $data['port'],
            ':longitude' => $data['longitude'],
            ':latitude' => $data['latitude'],
            ':intro' => $data['intro'],
            ':price_usd_yearly' => $data['price_usd_yearly'],
            ':expiry_date' => $data['expiry_date']
        ];
        return $this->db->execute($sql, $params) > 0; // 返回是否更新成功
    }

    // 获取服务器历史统计（带参数）
    public function getServerHistory($serverId, $limit = 20) {
        $sql = "SELECT cpu_usage, mem_usage_percent, disk_usage_percent, load_avg, net_up_speed, net_down_speed, total_up, total_down, timestamp 
                FROM server_stats WHERE server_id = :id ORDER BY timestamp DESC LIMIT :limit";
        $params = [':id' => $serverId, ':limit' => $limit];
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
                'timestamp' => (int)$record['timestamp']
            ];
        }, $history);

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
    public function createServer($id, $data) {
        $secret = bin2hex(random_bytes(16));  // 生成16字节随机secret
        $sql = "INSERT INTO servers (id, name, ip, latitude, longitude, intro, expiry_date, price_usd_monthly, price_usd_yearly, tags, secret) VALUES (:id, :name, :ip, :latitude, :longitude, :intro, :expiry_date, :price_usd_monthly, :price_usd_yearly, :tags, :secret)";
        $params = array_merge($data, [':id' => $id, ':secret' => $secret]);
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
            // 删除outages（假设有）
            $this->db->execute("DELETE FROM outages WHERE server_id = :id", [':id' => $id]);
            // 删除servers
            $this->db->execute("DELETE FROM servers WHERE id = :id", [':id' => $id]);
            $this->db->getPdo()->commit();
        } catch (Exception $e) {
            $this->db->getPdo()->rollBack();
            throw $e;
        }
    }
}
