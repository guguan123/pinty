<?php
// src/Repositories/UserRepository.php

namespace GuGuan123\Pinty\Repositories;

use GuGuan123\Pinty\Database;

class UserRepository {
    private $db;

    public function __construct($dbConfig) {
        $this->db = Database::getInstance($dbConfig);
    }

    /**
     * 验证用户名和密码，返回true如果有效。
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function verifyLogin($username, $password) {
        if (empty($username) || empty($password)) {
            return false;
        }

        $sql = "SELECT password FROM users WHERE username = :username LIMIT 1";
        $params = [':username' => $username];
        $user = $this->db->fetchAll($sql, $params)[0] ?? null;

        return $user && password_verify($password, $user['password']);
    }

    // 可扩展：getUserById, updatePassword 等...
}
