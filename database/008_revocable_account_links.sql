USE mobileai;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='external_account_links' AND column_name='revoked_at')=0, 'ALTER TABLE external_account_links ADD COLUMN revoked_at TIMESTAMP NULL AFTER verified_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='external_account_links' AND index_name='ix_external_links_account_active')=0, 'ALTER TABLE external_account_links ADD INDEX ix_external_links_account_active (account_id, revoked_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
