-- 服务器静态资料
CREATE TABLE IF NOT EXISTS servers (
    id               VARCHAR(255) PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    ip               VARCHAR(255),
    latitude         REAL,
    longitude        REAL,
    intro            TEXT,
    tags             TEXT,
    secret           VARCHAR(255),
    cpu_cores        INT,
    cpu_model        VARCHAR(255),
    mem_total        BIGINT,
    disk_total       BIGINT,
    expiry_date      BIGINT,
    price_usd_monthly REAL,
    price_usd_yearly REAL
);

-- 性能快照
CREATE TABLE IF NOT EXISTS server_stats (
    id                  SERIAL PRIMARY KEY,
    server_id           VARCHAR(255) NOT NULL,
    timestamp           BIGINT NOT NULL,
    cpu_usage           REAL,
    mem_usage_percent   REAL,
    disk_usage_percent  REAL,
    net_up_speed        BIGINT,
    net_down_speed      BIGINT,
    total_up            BIGINT,
    total_down          BIGINT,
    uptime              VARCHAR(255),
    load_avg            REAL
);
CREATE INDEX IF NOT EXISTS idx_stats_server_time ON server_stats(server_id, timestamp);

-- 在线状态
CREATE TABLE IF NOT EXISTS server_status (
    id            VARCHAR(255) PRIMARY KEY,
    is_online     BOOLEAN NOT NULL DEFAULT false,
    last_checked  BIGINT
);

-- 故障记录
CREATE TABLE IF NOT EXISTS outages (
    id          SERIAL PRIMARY KEY,
    server_id   VARCHAR(255) NOT NULL,
    start_time  BIGINT,
    end_time    BIGINT,
    title       VARCHAR(255),
    content     TEXT
);
CREATE INDEX IF NOT EXISTS idx_outages_server_start ON outages(server_id, start_time);

-- 系统配置
CREATE TABLE IF NOT EXISTS settings (
    key   VARCHAR(255) PRIMARY KEY,
    value TEXT
);
