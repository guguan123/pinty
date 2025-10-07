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
     * 保存设置（跨库 UPSERT）
     * @throws RuntimeException 数据库类型不支持
     */
    public function saveSetting(string $option_name, mixed $option_value): void {
        switch (strtolower($this->db->getDriverName())) {
            case 'pgsql':
                $sql = "INSERT INTO options(option_name, option_value)
                        VALUES (:option_name, :option_value)
                        ON CONFLICT (option_name)
                        DO UPDATE SET option_value = EXCLUDED.option_value";
                break;

            case 'mysql':
                $sql = "INSERT INTO options(option_name, option_value)
                        VALUES (:option_name, :option_value)
                        ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)";
                break;

            case 'sqlite':
                // 3.24+ 支持 UPSERT；老版本可退化为 INSERT OR REPLACE
                $sql = "INSERT INTO options(option_name, option_value)
                        VALUES (:option_name, :option_value)
                        ON CONFLICT(option_name) DO UPDATE
                        SET option_value = excluded.option_value";
                break;

            default:
                throw new \RuntimeException("Unsupported DB type: {$driver}");
        }

        $this->db->execute($sql, [
            ':option_name'  => $option_name,
            ':option_value' => $option_value,
        ]);
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
