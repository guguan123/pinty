<?php
// src/Repositories/SettingsRepository.php - 更新版：支持MySQL

namespace GuGuan123\Pinty\Repositories;

use GuGuan123\Pinty\Database;

class SettingsRepository {
    private $db;

    public function __construct($db_config) {
        $this->db = Database::getInstance($db_config);
    }

    /**
     * 保存设置（插入或更新，DB-specific UPSERT）。
     */
    public function saveSetting($option_name, $option_value) {
        if ($db_config['type'] === 'pgsql') {
            $sql = "INSERT INTO options (option_name, option_value) VALUES (:option_name, :option_value) ON CONFLICT (option_name) DO UPDATE SET option_value = EXCLUDED.option_value";
        } elseif ($db_config['type'] === 'mysql') {
            $sql = "INSERT INTO options (option_name, option_value) VALUES (:option_name, :option_value) ON DUPLICATE option_name UPDATE option_value = VALUES(option_value)";
        } else { // sqlite
            $sql = "INSERT OR REPLACE INTO options (option_name, option_value) VALUES (:option_name, :option_value)";
        }
        $this->db->execute($sql, [':option_name' => $option_name, ':option_value' => $option_value]);
    }

    /**
     * 获取设置值。
     */
    public function getSetting($option_name) {
        $sql = "SELECT option_value FROM options WHERE option_name = :option_name LIMIT 1";
        $params = [':option_name' => $option_name];
        $result = $this->db->fetchAll($sql, $params);
        return $result[0]['option_value'] ?? null;
    }
}
