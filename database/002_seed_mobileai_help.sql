USE mobileai;

INSERT INTO publishers (slug, name, is_verified)
VALUES ('mobileai', 'NeonLib', TRUE)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_verified = VALUES(is_verified);

INSERT INTO subscriptions (publisher_id, package_id, title, description, language, visibility, status)
SELECT p.id, 'mobileai.help', 'NeonLib Help',
       'Offline help, usage guides and troubleshooting for NeonLib.',
       'en', 'PUBLIC', 'DRAFT'
FROM publishers p WHERE p.slug = 'mobileai'
ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description);

INSERT INTO subscription_versions (subscription_id, version_number, content_sha256, status, published_at)
SELECT s.id, 1, REPEAT('0', 64), 'DRAFT', CURRENT_TIMESTAMP
FROM subscriptions s WHERE s.package_id = 'mobileai.help'
ON DUPLICATE KEY UPDATE status = 'DRAFT';

SET @version_id = (
    SELECT sv.id FROM subscription_versions sv
    JOIN subscriptions s ON s.id = sv.subscription_id
    WHERE s.package_id = 'mobileai.help' AND sv.version_number = 1
);

DELETE FROM subscription_documents WHERE version_id = @version_id;

INSERT INTO subscription_documents (version_id, document_key, title, content, sort_order) VALUES
(@version_id, 'getting-started', 'Getting started', 'NeonLib keeps your knowledge library on your Android device. Import documents or add a manual note, choose a collection, and wait until indexing is complete before searching. Embeddings are generated locally on the device.', 10),
(@version_id, 'search', 'Using AI Search', 'AI Search finds relevant passages in your library. Use Search in to select all collections, one collection, or several collections. Raw results stay on the device. If online answering is selected, only the relevant retrieved passages are sent to the configured online AI provider.', 20),
(@version_id, 'chat', 'Using AI Chat', 'AI Chat answers using relevant passages from the selected library collections. Conversations are saved automatically. Start a new chat when changing topic or knowledge scope. Saved chats are kept separate from the library and are not included in AI Search.', 30),
(@version_id, 'imports', 'Importing content', 'The import screen supports plain text, Markdown, HTML, CSV and web pages over HTTPS. Select or create a collection before importing. Large web pages can take longer because text must be extracted, split into chunks and indexed locally.', 40);

UPDATE subscription_versions sv
SET sv.document_count = (SELECT COUNT(*) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_bytes = (SELECT COALESCE(SUM(OCTET_LENGTH(d.content)), 0) FROM subscription_documents d WHERE d.version_id = sv.id),
    sv.content_sha256 = SHA2((SELECT GROUP_CONCAT(CONCAT(d.document_key, ':', d.content) ORDER BY d.sort_order SEPARATOR '\n') FROM subscription_documents d WHERE d.version_id = sv.id), 256),
    sv.status = 'PUBLISHED',
    sv.published_at = CURRENT_TIMESTAMP
WHERE sv.id = @version_id;

UPDATE subscriptions
SET published_version_id = @version_id, status = 'PUBLISHED'
WHERE package_id = 'mobileai.help';
