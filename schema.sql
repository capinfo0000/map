-- みんなの駐車場マップ — MySQL スキーマ
-- コアサーバー等で phpMyAdmin から取り込む場合に使用（アプリ初回起動時にも自動作成されます）。
-- 文字コードは日本語のため utf8mb4 を使用。

CREATE TABLE IF NOT EXISTS lots (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(120) NOT NULL,
    lat               DOUBLE NOT NULL,
    lng               DOUBLE NOT NULL,
    address           VARCHAR(200),
    hourly_rate       INT,
    max_rate          INT,
    fee_note          VARCHAR(500),
    capacity          INT,
    photo             VARCHAR(255),
    nickname          VARCHAR(40),
    source            VARCHAR(20),
    created_by_token  VARCHAR(80),
    created_at        VARCHAR(30) NOT NULL,
    updated_at        VARCHAR(30) NOT NULL,
    confirm_count     INT NOT NULL DEFAULT 0,
    last_confirmed_at VARCHAR(30),
    report_count      INT NOT NULL DEFAULT 0,
    hidden            INT NOT NULL DEFAULT 0,
    hidden_at         VARCHAR(30),
    INDEX idx_lots_latlng (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reports (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    lot_id       INT NOT NULL,
    client_token VARCHAR(80) NOT NULL,
    kind         VARCHAR(10) NOT NULL,
    comment      VARCHAR(500),
    was_stale    INT NOT NULL DEFAULT 0,
    created_at   VARCHAR(30) NOT NULL,
    UNIQUE KEY uniq_vote (lot_id, client_token, kind),
    INDEX idx_reports_lot (lot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    token      VARCHAR(80) PRIMARY KEY,
    nickname   VARCHAR(40),
    created_at VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
