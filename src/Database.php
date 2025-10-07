<?php
// src/Database.php

namespace GuGuan123\Pinty;

class Database {
    private static $instance = null;
    private $pdo;
    private $config;

    private function __construct($config) {
        $this->config = $config;
        $this->pdo = $this->createConnection();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function getInstance($config) {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    private function createConnection() {
        if ($this->config['type'] === 'pgsql') {
            $cfg = $this->config['pgsql'];
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
            return new PDO($dsn, $cfg['user'], $cfg['password']);
        } else { // sqlite
            $dsn = 'sqlite:' . $this->config['sqlite']['path'];
            $pdo = new PDO($dsn);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            return $pdo;
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    // 通用查询方法示例：执行带参数的查询
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // 通用fetch方法
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    // 插入/更新等通用方法，可以根据需要扩展
    public function execute($sql, $params = []) {
        return $this->query($sql, $params)->rowCount();
    }
}
