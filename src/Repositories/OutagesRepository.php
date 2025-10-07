<?php
// src/Repositories/OutagesRepository.php - 修复版：修正SQL和变量错误

namespace GuGuan123\Pinty\Repositories;

use GuGuan123\Pinty\Database;

class OutagesRepository {
    private $db;

    public function __construct($dbConfig) {
        $this->db = Database::getInstance($dbConfig);
    }

    /**
     * 获取最近的故障记录，按开始时间降序排序。
     * @param int $limit 限制记录数，默认50
     * @return array 故障记录数组
     */
    public function getRecentOutages(int $limit = 50) {
        $sql = "SELECT * FROM outages ORDER BY start_time DESC LIMIT {$limit}";  // 修正SQL：outages表，无server_id过滤
        $outages = $this->db->fetchAll($sql);

        // 类型转换：时间戳转为int
        return array_map(function(int $outage) {
            $outage['start_time'] = $outage['start_time'];
            $outage['end_time'] = $outage['end_time'] ? $outage['end_time'] : null;
            return $outage;
        }, $outages);
    }

    /**
     * 创建一个新的故障记录（服务器掉线时调用）。
     * @param int $serverId 服务器ID
     * @param string $title 标题，如'服务器掉线'
     * @param string $content 内容描述
     * @return bool 创建成功返回true
     */
    public function createOutage($serverId, $title, $content) {
        $sql = "INSERT INTO outages (server_id, start_time, title, content) VALUES (:server_id, :start_time, :title, :content)";
        $params = [
            ':server_id' => $serverId,
            ':start_time' => time(),
            ':title' => $title,
            ':content' => $content
        ];
        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * 更新故障记录的结束时间（服务器恢复时调用）。
     * @param int $outageId 故障记录ID
     * @param int $endTime 结束时间戳
     * @return bool 更新成功返回true
     */
    public function updateOutageEndTime($outageId, $endTime) {
        $sql = "UPDATE outages SET end_time = :end_time WHERE id = :id AND end_time IS NULL";
        $params = [':end_time' => $endTime, ':id' => $outageId];
        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * 检查指定服务器是否有进行中的故障记录（未结束的）。
     * @param int $serverId 服务器ID
     * @return array|null 进行中故障记录，或null
     */
    public function getActiveOutageForServer($serverId) {
        $sql = "SELECT * FROM outages WHERE server_id = :server_id AND end_time IS NULL ORDER BY start_time DESC LIMIT 1";
        $params = [':server_id' => $serverId];
        $outage = $this->db->fetchAll($sql, $params)[0] ?? null;
        
        if ($outage) {
            $outage['start_time'] = (int)$outage['start_time'];
        }
        
        return $outage;
    }
}
