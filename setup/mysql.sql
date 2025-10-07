-- MySQL.sql

-- 1. 服务器静态资料
CREATE TABLE IF NOT EXISTS servers (
    id                VARCHAR(255) PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    ip                VARCHAR(255),
    latitude          DOUBLE,
    longitude         DOUBLE,
    intro             TEXT,
    tags              TEXT,
    secret            VARCHAR(255),
    cpu_cores         INT,
    cpu_model         VARCHAR(255),
    mem_total         BIGINT,
    disk_total        BIGINT,
    expiry_date       BIGINT,
    price_usd_monthly DOUBLE,
    price_usd_yearly  DOUBLE
);

-- 2. 性能快照
CREATE TABLE IF NOT EXISTS server_stats (
    id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id           VARCHAR(255) NOT NULL,
    timestamp           BIGINT NOT NULL,
    cpu_usage           DOUBLE,
    mem_usage_percent   DOUBLE,
    disk_usage_percent  DOUBLE,
    net_up_speed        BIGINT,
    net_down_speed      BIGINT,
    total_up            BIGINT,
    total_down          BIGINT,
    uptime              VARCHAR(255),
    load_avg            DOUBLE,
    INDEX idx_stats_server_time (server_id, timestamp)
);

-- 3. 在线状态
CREATE TABLE IF NOT EXISTS server_status (
    id            VARCHAR(255) PRIMARY KEY,
    is_online     TINYINT(1) NOT NULL DEFAULT 0,
    last_checked  BIGINT
);

-- 4. 故障记录
CREATE TABLE IF NOT EXISTS outages (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id   VARCHAR(255) NOT NULL,
    start_time  BIGINT,
    end_time    BIGINT,
    title       VARCHAR(255),
    content     TEXT,
    INDEX idx_outages_server_start (server_id, start_time)
);

-- 5. 系统配置
CREATE TABLE IF NOT EXISTS options (
    option_name   VARCHAR(255) PRIMARY KEY,
    option_value TEXT
);

-- 6. 用户表（管理员登录）
CREATE TABLE IF NOT EXISTS users (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL
);
