USE mobileai;

CREATE TABLE IF NOT EXISTS admin_api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    token_prefix VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(120) NOT NULL,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_admin_tokens_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_admin_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
