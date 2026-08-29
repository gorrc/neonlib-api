USE mobileai;

SET @role_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'
);
SET @add_role = IF(
    @role_exists = 0,
    "ALTER TABLE users ADD COLUMN role ENUM('PUBLISHER', 'SUPERADMIN') NOT NULL DEFAULT 'PUBLISHER' AFTER display_name",
    'SELECT 1'
);
PREPARE add_role_statement FROM @add_role;
EXECUTE add_role_statement;
DEALLOCATE PREPARE add_role_statement;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(254) NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(50) NOT NULL,
    package_id VARCHAR(190) NULL,
    ip_address VARCHAR(45) NOT NULL,
    details VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX ix_admin_audit_package_time (package_id, created_at),
    INDEX ix_admin_audit_event_time (event_type, created_at),
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
