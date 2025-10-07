-- 服务器静态资料
CREATE TABLE IF NOT EXISTS servers (
    id                TEXT PRIMARY KEY,
    name              TEXT NOT NULL,
    ip                TEXT,
    latitude          REAL,
    longitude         REAL,
    intro             TEXT,
    tags              TEXT,
    secret            TEXT,
    cpu_cores         INTEGER,
    cpu_model         TEXT,
    mem_total         INTEGER,
    disk_total        INTEGER,
    expiry_date       INTEGER,
    price_usd_monthly REAL,
    price_usd_yearly  REAL
);

-- 性能快照
CREATE TABLE IF NOT EXISTS server_stats (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id           TEXT NOT NULL,
    timestamp           INTEGER NOT NULL,
    cpu_usage           REAL,
    mem_usage_percent   REAL,
    disk_usage_percent  REAL,
    net_up_speed        INTEGER,
    net_down_speed      INTEGER,
    total_up            INTEGER,
    total_down          INTEGER,
    uptime              TEXT,
    load_avg            REAL
);
CREATE INDEX IF NOT EXISTS idx_stats_server_time ON server_stats(server_id, timestamp);

-- 在线状态
CREATE TABLE IF NOT EXISTS server_status (
    id            TEXT PRIMARY KEY,
    is_online     INTEGER DEFAULT 0,
    last_checked  INTEGER
);

-- 故障记录
CREATE TABLE IF NOT EXISTS outages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id   TEXT NOT NULL,
    start_time  INTEGER,
    end_time    INTEGER,
    title       TEXT,
    content     TEXT
);
CREATE INDEX IF NOT EXISTS idx_outages_server_start ON outages(server_id, start_time);

-- 系统配置
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT
);
