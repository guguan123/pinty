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
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	}

	public static function getInstance($config) {
		if (self::$instance === null) {
			self::$instance = new self($config);
		}
		return self::$instance;
	}

	private function createConnection() {
		$type = $this->config['type'];

		// 获取 PHP 的当前时区
		$phpTimezone = date_default_timezone_get();
		// 尝试获取 UTC 偏移量，例如 '+08:00' 或 '-05:00'
		$datetime = new \DateTime('now', new \DateTimeZone($phpTimezone));
		$timezoneOffset = $datetime->format('P'); 

		if ($type === 'pgsql') {
			$cfg = $this->config['pgsql'];
			$dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
			
			$pdo = new \PDO($dsn, $cfg['user'], $cfg['password']);

			// PostgreSQL 时区设置
			$pdo->exec("SET TIMEZONE TO '{$phpTimezone}';"); 
			
			return $pdo;
			
		} elseif ($type === 'mysql') {
			$cfg = $this->config['mysql'];
			$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4";
			
			// MySQL 时区设置
			$pdo = new \PDO($dsn, $cfg['user'], $cfg['password'], [
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '{$timezoneOffset}'"
			]);
			return $pdo;
			
		} else { // sqlite
			$dsn = 'sqlite:' . $this->config['sqlite']['path'];
			$pdo = new \PDO($dsn);
			$pdo->exec('PRAGMA journal_mode = WAL;');
			$pdo->exec('PRAGMA foreign_keys = ON;');
			return $pdo;
		}
	}

	public function getPdo() {
		return $this->pdo;
	}

	public function getDriverName() {
		return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
	}

	// 通用查询方法示例：执行带参数的查询
	public function query($sql, $params = []) {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt;
	}

	// 通用fetch方法
	public function fetchAll($sql, $params = []) {
		return $this->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
	}

	// 插入/更新等通用方法，可以根据需要扩展
	public function execute($sql, $params = []) {
		return $this->query($sql, $params)->rowCount();
	}
}
