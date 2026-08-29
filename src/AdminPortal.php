<?php

declare(strict_types=1);

namespace NeonLib;

use JsonException;
use PDO;
use Throwable;

final class AdminPortal
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function handle(string $method, string $path): never
    {
        $this->startSession();

        if ($path === '/admin/logout' && $method === 'POST') {
            $this->verifyCsrf();
            $_SESSION = [];
            session_destroy();
            $this->redirect('/admin');
        }

        if (!isset($_SESSION['user_id'])) {
            if ($path === '/admin/login' && $method === 'POST') {
                $this->login();
            }
            $this->renderLogin();
        }

        if ($path === '/admin/publish' && $method === 'POST') {
            $this->publish();
        }

        if ($path !== '/admin' || $method !== 'GET') {
            http_response_code(404);
            $this->renderPage('Not found', '<p>The requested admin page does not exist.</p>');
        }

        $this->renderDashboard();
    }

    private function login(): never
    {
        $this->verifyCsrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $this->database->prepare(
            "SELECT id, password_hash, display_name FROM users WHERE email = :email AND status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->renderLogin('Invalid email or password.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['display_name'] = $user['display_name'];
        $this->redirect('/admin');
    }

    private function publish(): never
    {
        $this->verifyCsrf();
        $publisherSlug = strtolower(trim((string) ($_POST['publisher_slug'] ?? '')));
        $publisherName = trim((string) ($_POST['publisher_name'] ?? ''));
        $packageId = strtolower(trim((string) ($_POST['package_id'] ?? '')));
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $language = strtolower(trim((string) ($_POST['language'] ?? 'en')));
        $documentsJson = trim((string) ($_POST['documents_json'] ?? ''));

        $values = compact(
            'publisherSlug', 'publisherName', 'packageId', 'title', 'description', 'language', 'documentsJson'
        );

        try {
            $this->validateMetadata($values);
            $this->validateExistingIdentity($values);
            $documents = json_decode($documentsJson, true, 64, JSON_THROW_ON_ERROR);
            $documents = $this->validateDocuments($documents);
            $version = $this->storePublishedVersion($values, $documents);
        } catch (JsonException) {
            $this->auditPublish('publish_failed', $packageId, 'Documents must be valid JSON.');
            $this->renderDashboard('Documents must be valid JSON.', $values);
        } catch (ApiException $exception) {
            $this->auditPublish('publish_failed', $packageId, $exception->getMessage());
            $this->renderDashboard($exception->getMessage(), $values);
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->auditPublish('publish_failed', $packageId, $exception->getMessage());
            $this->renderDashboard('Publishing failed. No changes were published.', $values);
        }

        $this->auditPublish('publish_succeeded', $packageId, "Published version {$version}.");
        $_SESSION['flash'] = "Published {$packageId} version {$version}.";
        $this->redirect('/admin');
    }

    private function storePublishedVersion(array $values, array $documents): int
    {
        $userId = (int) $_SESSION['user_id'];
        $this->database->beginTransaction();

        try {
            $publisher = $this->database->prepare(
                'SELECT id, owner_user_id FROM publishers WHERE slug = :slug FOR UPDATE'
            );
            $publisher->execute(['slug' => $values['publisherSlug']]);
            $publisherRow = $publisher->fetch();

            if (!$publisherRow) {
                $insertPublisher = $this->database->prepare(
                    'INSERT INTO publishers (owner_user_id, slug, name) VALUES (:owner, :slug, :name)'
                );
                $insertPublisher->execute([
                    'owner' => $userId,
                    'slug' => $values['publisherSlug'],
                    'name' => $values['publisherName'],
                ]);
                $publisherId = (int) $this->database->lastInsertId();
            } else {
                $ownerId = $publisherRow['owner_user_id'] === null ? null : (int) $publisherRow['owner_user_id'];
                if ($ownerId !== null && $ownerId !== $userId) {
                    throw new ApiException(403, 'publisher_forbidden', 'This publisher belongs to another user.');
                }
                $publisherId = (int) $publisherRow['id'];
                $updatePublisher = $this->database->prepare(
                    'UPDATE publishers SET owner_user_id = :owner, name = :name WHERE id = :id'
                );
                $updatePublisher->execute(['owner' => $userId, 'name' => $values['publisherName'], 'id' => $publisherId]);
            }

            $subscriptionQuery = $this->database->prepare(
                'SELECT id, publisher_id FROM subscriptions WHERE package_id = :package_id FOR UPDATE'
            );
            $subscriptionQuery->execute(['package_id' => $values['packageId']]);
            $subscription = $subscriptionQuery->fetch();

            if ($subscription && (int) $subscription['publisher_id'] !== $publisherId) {
                throw new ApiException(403, 'package_forbidden', 'This package ID belongs to another publisher.');
            }

            if (!$subscription) {
                $insertSubscription = $this->database->prepare(
                    "INSERT INTO subscriptions
                     (publisher_id, package_id, title, description, language, visibility, status)
                     VALUES (:publisher, :package_id, :title, :description, :language, 'PUBLIC', 'DRAFT')"
                );
                $insertSubscription->execute([
                    'publisher' => $publisherId,
                    'package_id' => $values['packageId'],
                    'title' => $values['title'],
                    'description' => $values['description'],
                    'language' => $values['language'],
                ]);
                $subscriptionId = (int) $this->database->lastInsertId();
            } else {
                $subscriptionId = (int) $subscription['id'];
            }

            $versionQuery = $this->database->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1 FROM subscription_versions WHERE subscription_id = :id'
            );
            $versionQuery->execute(['id' => $subscriptionId]);
            $versionNumber = (int) $versionQuery->fetchColumn();

            $contentPayload = [
                'packageId' => $values['packageId'],
                'version' => $versionNumber,
                'documents' => $documents,
            ];
            $contentJson = json_encode($contentPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $insertVersion = $this->database->prepare(
                "INSERT INTO subscription_versions
                 (subscription_id, version_number, document_count, content_bytes, content_sha256, status, published_at)
                 VALUES (:subscription, :version, :count, :bytes, :hash, 'PUBLISHED', CURRENT_TIMESTAMP)"
            );
            $insertVersion->execute([
                'subscription' => $subscriptionId,
                'version' => $versionNumber,
                'count' => count($documents),
                'bytes' => strlen($contentJson),
                'hash' => hash('sha256', $contentJson),
            ]);
            $versionId = (int) $this->database->lastInsertId();

            $insertDocument = $this->database->prepare(
                'INSERT INTO subscription_documents (version_id, document_key, title, content, sort_order)
                 VALUES (:version, :document_key, :title, :content, :sort_order)'
            );
            foreach ($documents as $index => $document) {
                $insertDocument->execute([
                    'version' => $versionId,
                    'document_key' => $document['id'],
                    'title' => $document['title'],
                    'content' => $document['content'],
                    'sort_order' => ($index + 1) * 10,
                ]);
            }

            $publish = $this->database->prepare(
                "UPDATE subscriptions
                 SET title = :title, description = :description, language = :language,
                     status = 'PUBLISHED', published_version_id = :version
                 WHERE id = :id"
            );
            $publish->execute([
                'title' => $values['title'],
                'description' => $values['description'],
                'language' => $values['language'],
                'version' => $versionId,
                'id' => $subscriptionId,
            ]);

            $this->database->commit();
            return $versionNumber;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function validateMetadata(array $values): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $values['publisherSlug'])) {
            throw new ApiException(422, 'invalid_publisher', 'Publisher slug must contain 2–100 lowercase letters, numbers or hyphens.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $values['packageId'])) {
            throw new ApiException(422, 'invalid_package', 'Package ID must contain 3–190 lowercase letters, numbers, dots, underscores or hyphens.');
        }
        if ($values['publisherName'] === '' || mb_strlen($values['publisherName']) > 160) {
            throw new ApiException(422, 'invalid_publisher_name', 'Publisher name is required and may contain up to 160 characters.');
        }
        if ($values['title'] === '' || mb_strlen($values['title']) > 190) {
            throw new ApiException(422, 'invalid_title', 'Title is required and may contain up to 190 characters.');
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $values['language'])) {
            throw new ApiException(422, 'invalid_language', 'Use a language code such as en, hr or de.');
        }
    }

    private function validateExistingIdentity(array $values): void
    {
        $statement = $this->database->prepare(
            'SELECT p.slug, s.package_id
             FROM publishers p JOIN subscriptions s ON s.publisher_id = p.id
             WHERE p.owner_user_id = :user ORDER BY s.updated_at DESC LIMIT 1'
        );
        $statement->execute(['user' => (int) $_SESSION['user_id']]);
        $existing = $statement->fetch();
        if ($existing && ($existing['slug'] !== $values['publisherSlug'] || $existing['package_id'] !== $values['packageId'])) {
            throw new ApiException(422, 'immutable_identity', 'Publisher slug and package ID cannot be changed after the first publication.');
        }
    }

    private function validateDocuments(mixed $documents): array
    {
        if (!is_array($documents) || $documents === [] || !array_is_list($documents) || count($documents) > 1000) {
            throw new ApiException(422, 'invalid_documents', 'Documents must be a JSON array containing 1–1000 items.');
        }
        $ids = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                throw new ApiException(422, 'invalid_document', 'Every document must be a JSON object.');
            }
            $id = trim((string) ($document['id'] ?? ''));
            $title = trim((string) ($document['title'] ?? ''));
            $content = trim((string) ($document['content'] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,189}$/', $id) || isset($ids[$id])) {
                throw new ApiException(422, 'invalid_document_id', 'Document IDs must be unique lowercase identifiers.');
            }
            if ($title === '' || mb_strlen($title) > 255 || $content === '') {
                throw new ApiException(422, 'invalid_document_content', "Document {$id} requires a title and non-empty content.");
            }
            $ids[$id] = true;
        }
        return array_values(array_map(static fn (array $document): array => [
            'id' => trim((string) $document['id']),
            'title' => trim((string) $document['title']),
            'content' => trim((string) $document['content']),
        ], $documents));
    }

    private function renderLogin(?string $error = null): never
    {
        $errorHtml = $error ? '<div class="message error">' . $this->escape($error) . '</div>' : '';
        $body = $errorHtml . '<div class="card narrow"><h1>NeonLib Publisher</h1><p>Sign in to publish subscription packages.</p>
            <form method="post" action="' . $this->basePath('/admin/login') . '">
            <input type="hidden" name="csrf" value="' . $this->escape($this->csrf()) . '">
            <label>Email<input type="email" name="email" required autocomplete="username"></label>
            <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
            <button type="submit">Sign in</button></form></div>';
        $this->renderPage('Publisher sign in', $body);
    }

    private function renderDashboard(?string $error = null, array $values = []): never
    {
        $defaults = $this->dashboardDefaults();
        $values += $defaults;
        $identityLocked = (bool) $defaults['identityLocked'];
        $flash = (string) ($_SESSION['flash'] ?? '');
        unset($_SESSION['flash']);
        $message = $error
            ? '<div class="message error">' . $this->escape($error) . '</div>'
            : ($flash !== '' ? '<div class="message success">' . $this->escape($flash) . '</div>' : '');

        $body = '<header><div><strong>NeonLib Publisher</strong><span>' . $this->escape((string) $_SESSION['display_name']) . '</span></div>
            <form method="post" action="' . $this->basePath('/admin/logout') . '"><input type="hidden" name="csrf" value="' . $this->escape($this->csrf()) . '"><button class="secondary">Sign out</button></form></header>
            <main><h1>Publish a subscription</h1><p>Publishing creates a new immutable version that Android clients can download.</p>' . $message . '
            <form class="card grid" method="post" action="' . $this->basePath('/admin/publish') . '">
            <input type="hidden" name="csrf" value="' . $this->escape($this->csrf()) . '">
            ' . $this->field('Publisher slug', 'publisher_slug', $values['publisherSlug'], $identityLocked) . '
            ' . $this->field('Publisher name', 'publisher_name', $values['publisherName']) . '
            ' . $this->field('Package ID', 'package_id', $values['packageId'], $identityLocked) . '
            ' . $this->field('Title', 'title', $values['title']) . '
            ' . $this->field('Language', 'language', $values['language']) . '
            <label class="wide">Description<textarea name="description" rows="3" required>' . $this->escape($values['description']) . '</textarea></label>
            <section class="wide documents"><div class="section-title"><div><h2>Documents</h2><small>Add text manually or import TXT, Markdown, HTML and CSV files.</small></div><div class="toolbar"><label class="file-button">Import files<input id="document-files" type="file" multiple accept=".txt,.md,.markdown,.html,.htm,.csv,text/plain,text/markdown,text/html,text/csv"></label><button id="add-document" type="button" class="secondary">Add document</button></div></div><div id="document-builder"></div></section>
            <details class="wide raw-json"><summary>Advanced: raw JSON</summary><label>Documents JSON<textarea id="documents-json" class="code" name="documents_json" rows="18" required spellcheck="false">' . $this->escape($values['documentsJson']) . '</textarea></label><small id="json-status">Builder and JSON are synchronized automatically.</small></details>
            <div class="wide actions"><small>Required fields per document: id, title, content.</small><button type="submit">Publish new version</button></div>
            </form></main>';
        $this->renderPage('Publish subscription', $body);
    }

    private function dashboardDefaults(): array
    {
        $statement = $this->database->prepare(
            'SELECT p.slug, p.name, s.package_id, s.title, s.description, s.language, s.published_version_id
             FROM publishers p LEFT JOIN subscriptions s ON s.publisher_id = p.id
             WHERE p.owner_user_id = :user ORDER BY s.updated_at DESC LIMIT 1'
        );
        $statement->execute(['user' => (int) $_SESSION['user_id']]);
        $row = $statement->fetch();
        $documents = [];
        if ($row && $row['published_version_id'] !== null) {
            $documentStatement = $this->database->prepare(
                'SELECT document_key, title, content FROM subscription_documents
                 WHERE version_id = :version ORDER BY sort_order, id'
            );
            $documentStatement->execute(['version' => (int) $row['published_version_id']]);
            $documents = array_map(static fn (array $document): array => [
                'id' => $document['document_key'],
                'title' => $document['title'],
                'content' => $document['content'],
            ], $documentStatement->fetchAll());
        }
        $documentsJson = $documents === []
            ? "[\n  {\n    \"id\": \"getting-started\",\n    \"title\": \"Getting started\",\n    \"content\": \"Document text goes here.\"\n  }\n]"
            : json_encode($documents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'publisherSlug' => $row['slug'] ?? 'mobileai',
            'publisherName' => $row['name'] ?? 'NeonLib',
            'packageId' => $row['package_id'] ?? 'mobileai.help',
            'title' => $row['title'] ?? 'NeonLib Help',
            'description' => $row['description'] ?? '',
            'language' => $row['language'] ?? 'en',
            'documentsJson' => $documentsJson,
            'identityLocked' => $row && $row['package_id'] !== null,
        ];
    }

    private function field(string $label, string $name, string $value, bool $readonly = false): string
    {
        $readonlyHtml = $readonly ? ' readonly title="Locked after first publication"' : '';
        return '<label>' . $this->escape($label) . '<input name="' . $this->escape($name) . '" value="' . $this->escape($value) . '" required' . $readonlyHtml . '></label>';
    }

    private function renderPage(string $title, string $body): never
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->escape($title) . '</title><style>
        :root{font-family:Inter,system-ui,sans-serif;color:#18201f;background:#f3f6f5}*{box-sizing:border-box}body{margin:0}header{display:flex;justify-content:space-between;align-items:center;padding:16px max(24px,calc((100% - 1000px)/2));background:#fff;border-bottom:1px solid #dce4e1}header div{display:flex;gap:16px;align-items:center}main{max-width:1000px;margin:38px auto;padding:0 24px}.card{background:#fff;border:1px solid #dce4e1;border-radius:16px;padding:24px;box-shadow:0 8px 24px #18332b0d}.narrow{max-width:430px;margin:10vh auto}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.wide{grid-column:1/-1}label{display:grid;gap:7px;font-size:14px;font-weight:650}input,textarea{width:100%;border:1px solid #afbfba;border-radius:9px;padding:11px 12px;font:inherit;background:#fff}input[readonly]{background:#eef2f1;color:#60706b}input:focus,textarea:focus{outline:3px solid #b9e7d7;border-color:#27785e}.code{font:13px/1.5 ui-monospace,monospace}button,.file-button{border:0;border-radius:9px;padding:11px 17px;background:#176b50;color:#fff;font-weight:700;cursor:pointer}.secondary{background:#e5eeeb;color:#174d3c}.actions,.section-title,.toolbar,.document-controls{display:flex;justify-content:space-between;align-items:center;gap:10px}.message{padding:12px 15px;border-radius:9px;margin:18px 0}.error{background:#fde8e8;color:#8c2020}.success{background:#dcf6e9;color:#155e43}form{display:grid;gap:18px}h1{margin-bottom:8px}h2{margin:0 0 5px}p{color:#56645f}.documents{border-top:1px solid #dce4e1;padding-top:22px}.file-button{display:block;font-size:13px}.file-button input{display:none}.document-card{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;padding:17px;border:1px solid #dce4e1;border-radius:12px;background:#f9fbfa}.document-card .content,.document-controls{grid-column:1/-1}.document-controls{justify-content:flex-end}.icon-button{padding:7px 11px;background:#e5eeeb;color:#174d3c}.danger{background:#fde8e8;color:#8c2020}.raw-json{border:1px solid #dce4e1;border-radius:10px;padding:13px}.raw-json summary{cursor:pointer;font-weight:700}.raw-json label{margin-top:14px}#json-status.invalid{color:#9b2525}@media(max-width:650px){.grid{grid-template-columns:1fr}.wide{grid-column:1}.actions,.section-title{align-items:stretch;flex-direction:column}.toolbar{justify-content:flex-start;flex-wrap:wrap}.document-card{grid-template-columns:1fr}header span{display:none}}
        </style></head><body>' . $body . '<script>
        (()=>{const textarea=document.getElementById("documents-json"),mount=document.getElementById("document-builder"),status=document.getElementById("json-status");if(!textarea||!mount)return;let documents=[];const escapeHtml=value=>String(value).replace(/[&<>"\x27]/g,char=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;"}[char]));const slug=value=>value.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/[^a-z0-9._-]+/g,"-").replace(/^-+|-+$/g,"").slice(0,190)||"document";const uniqueId=(base,index)=>{let result=slug(base),suffix=2;while(documents.some((item,i)=>i!==index&&item.id===result))result=slug(base)+"-"+suffix++;return result};const syncJson=()=>{textarea.value=JSON.stringify(documents,null,2);status.textContent="Builder and JSON are synchronized automatically.";status.classList.remove("invalid")};const render=()=>{mount.innerHTML=documents.map((doc,index)=>`<article class="document-card" data-index="${index}"><label>Document ID<input data-key="id" value="${escapeHtml(doc.id)}" required></label><label>Title<input data-key="title" value="${escapeHtml(doc.title)}" required></label><label class="content">Content<textarea data-key="content" rows="8" required>${escapeHtml(doc.content)}</textarea></label><div class="document-controls"><button type="button" class="icon-button" data-action="up" ${index===0?"disabled":""}>Up</button><button type="button" class="icon-button" data-action="down" ${index===documents.length-1?"disabled":""}>Down</button><button type="button" class="icon-button danger" data-action="remove">Remove</button></div></article>`).join("")};const loadJson=()=>{try{const parsed=JSON.parse(textarea.value);if(!Array.isArray(parsed))throw new Error();documents=parsed.map((item,index)=>({id:String(item.id||uniqueId(item.title||"document",index)),title:String(item.title||""),content:String(item.content||"")}));render();status.textContent="JSON loaded into builder.";status.classList.remove("invalid")}catch{status.textContent="Invalid JSON. Fix it before publishing.";status.classList.add("invalid")}};mount.addEventListener("input",event=>{const card=event.target.closest("[data-index]");if(!card||!event.target.dataset.key)return;const index=Number(card.dataset.index),key=event.target.dataset.key;documents[index][key]=event.target.value;if(key==="title"&&!documents[index].id)documents[index].id=uniqueId(event.target.value,index);syncJson()});mount.addEventListener("click",event=>{const button=event.target.closest("button[data-action]");if(!button)return;const index=Number(button.closest("[data-index]").dataset.index),action=button.dataset.action;if(action==="remove")documents.splice(index,1);if(action==="up"&&index>0)[documents[index-1],documents[index]]=[documents[index],documents[index-1]];if(action==="down"&&index<documents.length-1)[documents[index+1],documents[index]]=[documents[index],documents[index+1]];render();syncJson()});document.getElementById("add-document").addEventListener("click",()=>{documents.push({id:uniqueId("document",documents.length),title:"",content:""});render();syncJson();mount.lastElementChild?.querySelector("input[data-key=title]")?.focus()});document.getElementById("document-files").addEventListener("change",async event=>{for(const file of event.target.files){let content=await file.text();if(/\.html?$/i.test(file.name)){const parsed=new DOMParser().parseFromString(content,"text/html");parsed.querySelectorAll("script,style,noscript,template").forEach(node=>node.remove());content=(parsed.body?.innerText||parsed.body?.textContent||"").replace(/\n{3,}/g,"\n\n").trim()}const title=file.name.replace(/\.[^.]+$/,"");documents.push({id:uniqueId(title,documents.length),title,content})}event.target.value="";render();syncJson()});textarea.addEventListener("change",loadJson);textarea.closest("form").addEventListener("submit",event=>{syncJson();if(documents.length===0){event.preventDefault();status.textContent="Add at least one document before publishing.";status.classList.add("invalid")}});loadJson()})();
        </script></body></html>';
        exit;
    }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('mobileai_publisher');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
            session_start();
        }
    }

    private function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function verifyCsrf(): void
    {
        if (!hash_equals($this->csrf(), (string) ($_POST['csrf'] ?? ''))) {
            throw new ApiException(403, 'invalid_csrf', 'The form expired. Reload the page and try again.');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $this->basePath($path), true, 303);
        exit;
    }

    private function basePath(string $path): string
    {
        $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return ($directory === '/' ? '' : rtrim($directory, '/')) . $path;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function auditPublish(string $event, string $packageId, string $details): void
    {
        try {
            $statement = $this->database->prepare(
                'INSERT INTO admin_audit_log (user_id, event_type, package_id, ip_address, details)
                 VALUES (:user, :event, :package, :ip, :details)'
            );
            $statement->execute([
                'user' => $_SESSION['user_id'] ?? null,
                'event' => $event,
                'package' => $packageId === '' ? null : $packageId,
                'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45),
                'details' => mb_substr($details, 0, 1000),
            ]);
        } catch (Throwable $auditError) {
            error_log('Unable to write admin audit event: ' . $auditError->getMessage());
        }
    }
}
