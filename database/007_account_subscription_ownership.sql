USE mobileai;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='publishers' AND column_name='owner_account_id')=0, 'ALTER TABLE publishers ADD COLUMN owner_account_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER owner_user_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='publishers' AND index_name='uq_publishers_owner_account')=0, 'ALTER TABLE publishers ADD UNIQUE KEY uq_publishers_owner_account (owner_account_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='publishers' AND constraint_name='fk_publishers_owner_account')=0, 'ALTER TABLE publishers ADD CONSTRAINT fk_publishers_owner_account FOREIGN KEY (owner_account_id) REFERENCES accounts(account_id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='subscriptions' AND column_name='owner_account_id')=0, 'ALTER TABLE subscriptions ADD COLUMN owner_account_id CHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER publisher_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='subscriptions' AND index_name='ix_subscriptions_owner_updated')=0, 'ALTER TABLE subscriptions ADD INDEX ix_subscriptions_owner_updated (owner_account_id, updated_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='subscriptions' AND constraint_name='fk_subscriptions_owner_account')=0, 'ALTER TABLE subscriptions ADD CONSTRAINT fk_subscriptions_owner_account FOREIGN KEY (owner_account_id) REFERENCES accounts(account_id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
