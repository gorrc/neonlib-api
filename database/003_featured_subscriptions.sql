USE mobileai;

SET @is_featured_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'subscriptions'
      AND column_name = 'is_featured'
);
SET @add_is_featured = IF(
    @is_featured_exists = 0,
    'ALTER TABLE subscriptions ADD COLUMN is_featured BOOLEAN NOT NULL DEFAULT FALSE AFTER visibility',
    'SELECT 1'
);
PREPARE add_is_featured_statement FROM @add_is_featured;
EXECUTE add_is_featured_statement;
DEALLOCATE PREPARE add_is_featured_statement;

UPDATE subscriptions
SET is_featured = TRUE
WHERE package_id = 'mobileai.help';
