-- Additive MySQL migration. Select the intended database before running this file.
-- No USE statement, seed data, changes to existing rows, or destructive rollback.
CREATE TABLE IF NOT EXISTS content_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(20) NOT NULL,
    target_id VARCHAR(190) NOT NULL,
    target_label VARCHAR(240) NOT NULL,
    content_version BIGINT UNSIGNED NULL,
    reason VARCHAR(30) NOT NULL,
    excerpt TEXT NOT NULL,
    details TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'NEW',
    admin_note TEXT NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX reports_queue (status, id),
    INDEX reports_target (kind, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_rate_limits (
    bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start BIGINT NOT NULL,
    request_count INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_key, window_start)
) ENGINE=InnoDB;
