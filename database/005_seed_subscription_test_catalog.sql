USE mobileai;

-- Idempotent catalog fixtures for mobile and API acceptance testing.
-- The PRIVATE, DRAFT and ARCHIVED packages are intentional negative cases.

INSERT INTO publishers (slug, name, is_verified)
VALUES ('mobileai-labs', 'NeonLib Labs', TRUE)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_verified = VALUES(is_verified);

SET @publisher_id = (SELECT id FROM publishers WHERE slug = 'mobileai-labs');

-- Public, published, non-featured: must appear in matching search results only.
INSERT INTO subscriptions
    (publisher_id, package_id, title, description, language, visibility, is_featured, status)
VALUES
    (@publisher_id, 'mobileai.android-guide', 'Android Testing Guide',
     'Practical Android device testing, installation and offline verification.',
     'en', 'PUBLIC', FALSE, 'DRAFT')
ON DUPLICATE KEY UPDATE
    publisher_id = VALUES(publisher_id), title = VALUES(title), description = VALUES(description),
    language = VALUES(language), visibility = VALUES(visibility), is_featured = VALUES(is_featured);

SET @subscription_id = (SELECT id FROM subscriptions WHERE package_id = 'mobileai.android-guide');
INSERT INTO subscription_versions
    (subscription_id, version_number, document_count, content_bytes, content_sha256, status, published_at)
VALUES (@subscription_id, 1, 0, 0, REPEAT('0', 64), 'DRAFT', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE status = 'DRAFT';
SET @version_id = (SELECT id FROM subscription_versions WHERE subscription_id = @subscription_id AND version_number = 1);
DELETE FROM subscription_documents WHERE version_id = @version_id;
INSERT INTO subscription_documents (version_id, document_key, title, content, sort_order) VALUES
(@version_id, 'physical-device', 'Testing on a physical Android device',
 'Install the debug build on a physical Android device, open Subscriptions and verify featured packages, search and package downloads.', 10),
(@version_id, 'offline-check', 'Offline verification',
 'After downloading the package, disable network access and confirm that imported documents remain searchable on the device.', 20);
UPDATE subscription_versions sv SET
    sv.document_count = (SELECT COUNT(*) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(d.content)), 0) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_sha256 = SHA2((SELECT GROUP_CONCAT(CONCAT(d.document_key, ':', d.content) ORDER BY d.sort_order SEPARATOR '\n') FROM subscription_documents d WHERE d.version_id = sv.id), 256),
    sv.status = 'PUBLISHED', sv.published_at = CURRENT_TIMESTAMP
WHERE sv.id = @version_id;
UPDATE subscriptions SET visibility = 'PUBLIC', is_featured = FALSE, status = 'PUBLISHED', published_version_id = @version_id
WHERE id = @subscription_id;

-- Public, published, featured: must appear in featured and matching search results.
INSERT INTO subscriptions
    (publisher_id, package_id, title, description, language, visibility, is_featured, status)
VALUES
    (@publisher_id, 'mobileai.security-guide', 'Mobile Security Guide',
     'Featured guidance for secure mobile AI usage, privacy and data handling.',
     'en', 'PUBLIC', TRUE, 'DRAFT')
ON DUPLICATE KEY UPDATE
    publisher_id = VALUES(publisher_id), title = VALUES(title), description = VALUES(description),
    language = VALUES(language), visibility = VALUES(visibility), is_featured = VALUES(is_featured);

SET @subscription_id = (SELECT id FROM subscriptions WHERE package_id = 'mobileai.security-guide');
INSERT INTO subscription_versions
    (subscription_id, version_number, document_count, content_bytes, content_sha256, status, published_at)
VALUES (@subscription_id, 1, 0, 0, REPEAT('0', 64), 'DRAFT', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE status = 'DRAFT';
SET @version_id = (SELECT id FROM subscription_versions WHERE subscription_id = @subscription_id AND version_number = 1);
DELETE FROM subscription_documents WHERE version_id = @version_id;
INSERT INTO subscription_documents (version_id, document_key, title, content, sort_order) VALUES
(@version_id, 'privacy-basics', 'Privacy basics',
 'Keep private documents on trusted devices and review what information is shared before selecting an online AI provider.', 10),
(@version_id, 'account-security', 'Account security',
 'Use unique passwords, multi-factor authentication where available and revoke service credentials that are no longer needed.', 20);
UPDATE subscription_versions sv SET
    sv.document_count = (SELECT COUNT(*) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(d.content)), 0) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_sha256 = SHA2((SELECT GROUP_CONCAT(CONCAT(d.document_key, ':', d.content) ORDER BY d.sort_order SEPARATOR '\n') FROM subscription_documents d WHERE d.version_id = sv.id), 256),
    sv.status = 'PUBLISHED', sv.published_at = CURRENT_TIMESTAMP
WHERE sv.id = @version_id;
UPDATE subscriptions SET visibility = 'PUBLIC', is_featured = TRUE, status = 'PUBLISHED', published_version_id = @version_id
WHERE id = @subscription_id;

-- Private but published: manifest, content, featured and search must hide it.
INSERT INTO subscriptions
    (publisher_id, package_id, title, description, language, visibility, is_featured, status)
VALUES
    (@publisher_id, 'mobileai.private-diagnostics', 'Private Diagnostics',
     'Private package used to verify that published private content is never exposed.',
     'en', 'PRIVATE', FALSE, 'DRAFT')
ON DUPLICATE KEY UPDATE
    publisher_id = VALUES(publisher_id), title = VALUES(title), description = VALUES(description),
    language = VALUES(language), visibility = VALUES(visibility), is_featured = VALUES(is_featured);

SET @subscription_id = (SELECT id FROM subscriptions WHERE package_id = 'mobileai.private-diagnostics');
INSERT INTO subscription_versions
    (subscription_id, version_number, document_count, content_bytes, content_sha256, status, published_at)
VALUES (@subscription_id, 1, 0, 0, REPEAT('0', 64), 'DRAFT', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE status = 'DRAFT';
SET @version_id = (SELECT id FROM subscription_versions WHERE subscription_id = @subscription_id AND version_number = 1);
DELETE FROM subscription_documents WHERE version_id = @version_id;
INSERT INTO subscription_documents (version_id, document_key, title, content, sort_order) VALUES
(@version_id, 'private-check', 'Private visibility check',
 'This document must never be returned by the public subscription API.', 10);
UPDATE subscription_versions sv SET
    sv.document_count = (SELECT COUNT(*) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(d.content)), 0) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_sha256 = SHA2((SELECT GROUP_CONCAT(CONCAT(d.document_key, ':', d.content) ORDER BY d.sort_order SEPARATOR '\n') FROM subscription_documents d WHERE d.version_id = sv.id), 256),
    sv.status = 'PUBLISHED', sv.published_at = CURRENT_TIMESTAMP
WHERE sv.id = @version_id;
UPDATE subscriptions SET visibility = 'PRIVATE', is_featured = FALSE, status = 'PUBLISHED', published_version_id = @version_id
WHERE id = @subscription_id;

-- Public draft: must not appear publicly and has no published version pointer.
INSERT INTO subscriptions
    (publisher_id, package_id, title, description, language, visibility, is_featured, status, published_version_id)
VALUES
    (@publisher_id, 'mobileai.draft-guide', 'Draft Guide',
     'Unpublished draft used to verify public API filtering.',
     'en', 'PUBLIC', FALSE, 'DRAFT', NULL)
ON DUPLICATE KEY UPDATE
    publisher_id = VALUES(publisher_id), title = VALUES(title), description = VALUES(description),
    language = VALUES(language), visibility = 'PUBLIC', is_featured = FALSE,
    status = 'DRAFT', published_version_id = NULL;

-- Archived: must not appear publicly even though the package record remains.
INSERT INTO subscriptions
    (publisher_id, package_id, title, description, language, visibility, is_featured, status, published_version_id)
VALUES
    (@publisher_id, 'mobileai.archived-guide', 'Archived Guide',
     'Archived package used to verify public API filtering.',
     'en', 'PUBLIC', FALSE, 'ARCHIVED', NULL)
ON DUPLICATE KEY UPDATE
    publisher_id = VALUES(publisher_id), title = VALUES(title), description = VALUES(description),
    language = VALUES(language), visibility = 'PUBLIC', is_featured = FALSE,
    status = 'ARCHIVED', published_version_id = NULL;
