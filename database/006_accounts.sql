USE mobileai;

CREATE TABLE IF NOT EXISTS accounts (
    account_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    status ENUM('ACTIVE', 'SUSPENDED', 'DELETED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_account_id CHECK (account_id REGEXP '^acc_[0-9a-hjkmnp-tv-z]{26}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS external_account_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    site_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_user_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verified_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_external_identity (provider, site_id, external_user_id),
    UNIQUE KEY uq_account_provider_site (account_id, provider, site_id),
    CONSTRAINT fk_external_link_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_request_nonces (
    client_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nonce VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, nonce),
    INDEX ix_api_nonces_expiry (expires_at)
) ENGINE=InnoDB;
