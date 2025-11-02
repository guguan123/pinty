<?php
// src/Repositories/OutagesRepository.php

namespace GuGuan123\Pinty\Repositories;

use GuGuan123\Pinty\Database;

class OutagesRepository {
	private $db;

	public function __construct($dbConfig) {
		$this->db = Database::getInstance($dbConfig);
	}

	/**
	 * 获取最近的故障记录，按开始时间降序排序，附带服务器名称和国家码。
	 * @param int $limit 限制记录数，默认50
	 * @return array 故障记录数组，每个记录包含服务器名称和国家码
	 */
	public function getRecentOutages(int $limit = 50) {
		$sql = "SELECT o.*, s.name AS server_name, s.country_code 
				FROM outages o 
				JOIN servers s ON o.server_id = s.id 
				ORDER BY o.start_time DESC LIMIT {$limit}";
		return $this->db->fetchAll($sql);
	}

	/**
	 * 创建一个新的故障记录（服务器掉线时调用）。
	 * @param int $serverId 服务器ID
	 * @param string $title 标题，如'服务器掉线'
	 * @param string $content 内容描述
	 * @return int|false 创建成功返回故障记录ID，失败返回false
	 */
	public function createOutage($serverId, $title, $content) {
		$sql = "INSERT INTO outages (server_id, start_time, title, content) VALUES (:server_id, :start_time, :title, :content)";
		$params = [
			':server_id' => $serverId,
			':start_time' => date('Y-m-d H:i:s'),
			':title' => $title,
			':content' => $content
		];
		if ($this->db->execute($sql, $params) > 0) {
			return $this->db->getPdo()->lastInsertId();
		}
		return false;
	}

	/**
	 * 更新故障记录的结束时间（服务器恢复时调用）。
	 * @param int $outageId 故障记录ID
	 * @param int $endTime 结束时间
	 * @return bool 更新成功返回true
	 */
	public function updateOutageEndTime($outageId, $endTime) {
		$sql = "UPDATE outages SET end_time = :end_time WHERE id = :id AND end_time IS NULL";
		$params = [':end_time' => $endTime ?? date('Y-m-d H:i:s'), ':id' => $outageId];
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
		return $outage;
	}
}
