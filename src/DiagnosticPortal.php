<?php

declare(strict_types=1);

namespace NeonLib;

use PDO;
use Throwable;

final class DiagnosticPortal
{
    private const SESSION_TTL_SECONDS = 900;
    private const MAX_LOGIN_FAILURES = 5;

    public function __construct(private readonly PDO $database)
    {
    }

    public function handle(string $method, string $path): never
    {
        $this->requireHttpsInProduction();
        $this->startSession();

        if ($path === '/admin/logout' && $method === 'POST') {
            $this->verifyCsrf();
            $this->audit('logout');
            $_SESSION = [];
            session_destroy();
            $this->redirect('/admin');
        }

        if (!$this->hasActiveSuperadminSession()) {
            unset(
                $_SESSION['user_id'],
                $_SESSION['user_role'],
                $_SESSION['display_name'],
                $_SESSION['last_activity']
            );
            if ($path === '/admin/login' && $method === 'POST') {
                $this->login();
            }
            if ($path !== '/admin' || $method !== 'GET') {
                $this->notFound();
            }
            $this->renderLogin();
        }

        $_SESSION['last_activity'] = time();
        if ($path !== '/admin' || $method !== 'GET') {
            $this->notFound();
        }

        $packageId = strtolower(trim((string) ($_GET['package_id'] ?? '')));
        if ($packageId === '') {
            $this->renderDashboard();
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/', $packageId)) {
            $this->renderDashboard($packageId, null, 'Enter an exact, valid Package ID.');
        }

        $diagnostics = $this->findPackage($packageId);
        $this->audit('package_viewed', $packageId, $diagnostics === null ? 'not_found' : 'found');
        $this->renderDashboard(
            $packageId,
            $diagnostics,
            $diagnostics === null ? 'No subscription exists with that exact Package ID.' : null
        );
    }

    private function login(): never
    {
        $this->verifyCsrf();
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $ip = $this->clientIp();

        $rate = $this->database->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE ip_address = :ip AND succeeded = FALSE AND attempted_at >= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE'
        );
        $rate->execute(['ip' => $ip]);
        if ((int) $rate->fetchColumn() >= self::MAX_LOGIN_FAILURES) {
            http_response_code(429);
            $this->renderLogin('Too many sign-in attempts. Try again in 15 minutes.');
        }

        $statement = $this->database->prepare(
            "SELECT id, password_hash, display_name, role FROM users
             WHERE email = :email AND status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        $valid = $user && $user['role'] === 'SUPERADMIN' && password_verify($password, $user['password_hash']);
        $this->recordLoginAttempt($ip, $email, (bool) $valid);

        if (!$valid) {
            $this->audit('login_failed', null, $email, null);
            $this->renderLogin('Invalid email or password.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = 'SUPERADMIN';
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['last_activity'] = time();
        $this->audit('login_succeeded');
        $this->redirect('/admin');
    }

    private function findPackage(string $packageId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT s.id, s.package_id, s.title, s.status, s.visibility, s.is_featured,
                    s.published_version_id, p.slug AS publisher_slug, p.name AS publisher_name
             FROM subscriptions s JOIN publishers p ON p.id = s.publisher_id
             WHERE s.package_id = :package_id LIMIT 1'
        );
        $statement->execute(['package_id' => $packageId]);
        $subscription = $statement->fetch();
        if (!$subscription) {
            return null;
        }

        $versions = $this->database->prepare(
            'SELECT id, version_number, status, document_count, content_bytes, content_sha256,
                    created_at, published_at FROM subscription_versions
             WHERE subscription_id = :subscription ORDER BY version_number DESC'
        );
        $versions->execute(['subscription' => (int) $subscription['id']]);

        $documents = [];
        if ($subscription['published_version_id'] !== null) {
            $documentQuery = $this->database->prepare(
                'SELECT document_key, title, content, CHAR_LENGTH(content) AS character_count
                 FROM subscription_documents WHERE version_id = :version ORDER BY sort_order, id'
            );
            $documentQuery->execute(['version' => (int) $subscription['published_version_id']]);
            $documents = $documentQuery->fetchAll();
        }

        $errors = $this->database->prepare(
            "SELECT details, created_at FROM admin_audit_log
             WHERE package_id = :package_id AND event_type = 'publish_failed'
             ORDER BY created_at DESC LIMIT 10"
        );
        $errors->execute(['package_id' => $packageId]);

        return ['subscription' => $subscription, 'versions' => $versions->fetchAll(),
            'documents' => $documents, 'publishErrors' => $errors->fetchAll()];
    }

    private function renderDashboard(string $packageId = '', ?array $data = null, ?string $error = null): never
    {
        $message = $error === null ? '' : '<div class="message error">' . $this->escape($error) . '</div>';
        $result = $data === null ? '' : $this->renderResult($data);
        $body = $this->header() . '<main><h1>Subscription diagnostics</h1>
            <p>Read-only lookup by exact Package ID. No data can be changed from this portal.</p>' . $message . '
            <form class="card search" method="get" action="' . $this->basePath('/admin') . '">
            <label>Package ID<input name="package_id" value="' . $this->escape($packageId) . '" required
            pattern="[a-z0-9][a-z0-9._-]{2,189}" autocomplete="off" spellcheck="false"></label>
            <button type="submit">Find</button></form>' . $result . '</main>';
        $this->renderPage('Subscription diagnostics', $body);
    }

    private function renderResult(array $data): string
    {
        $s = $data['subscription'];
        $published = null;
        foreach ($data['versions'] as $version) {
            if ((int) $version['id'] === (int) $s['published_version_id']) {
                $published = $version;
                break;
            }
        }
        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $manifestUrl = $baseUrl . '/api/v1/subscriptions/' . rawurlencode($s['package_id']) . '/manifest';
        $contentUrl = $baseUrl . '/api/v1/subscriptions/' . rawurlencode($s['package_id']) . '/content';
        $value = fn (mixed $v): string => $this->escape($v === null || $v === '' ? '—' : (string) $v);
        $rows = [
            'Package ID' => $s['package_id'], 'Publisher' => $s['publisher_name'] . ' (' . $s['publisher_slug'] . ')',
            'Status / visibility' => $s['status'] . ' / ' . $s['visibility'],
            'Featured' => (bool) $s['is_featured'] ? 'Yes' : 'No',
            'Published version' => $published['version_number'] ?? null, 'Published at' => $published['published_at'] ?? null,
            'Documents' => $published['document_count'] ?? 0, 'Content size' => isset($published['content_bytes']) ? $published['content_bytes'] . ' bytes' : null,
            'Content SHA-256' => $published['content_sha256'] ?? null,
        ];
        $summary = '';
        foreach ($rows as $label => $item) {
            $summary .= '<dt>' . $this->escape($label) . '</dt><dd>' . $value($item) . '</dd>';
        }
        $versions = '';
        foreach ($data['versions'] as $version) {
            $versions .= '<tr><td>' . $value($version['version_number']) . '</td><td>' . $value($version['status']) . '</td><td>' .
                $value($version['document_count']) . '</td><td>' . $value($version['content_bytes']) . '</td><td class="code">' .
                $value($version['content_sha256']) . '</td><td>' . $value($version['published_at']) . '</td></tr>';
        }
        $documents = '';
        foreach ($data['documents'] as $document) {
            $documents .= '<details class="card document"><summary><strong>' . $value($document['title']) . '</strong> <span class="code">' .
                $value($document['document_key']) . '</span> · ' . $value($document['character_count']) . ' characters</summary><pre>' .
                $value($document['content']) . '</pre></details>';
        }
        $errors = '';
        foreach ($data['publishErrors'] as $publishError) {
            $errors .= '<li><time>' . $value($publishError['created_at']) . '</time> — ' . $value($publishError['details']) . '</li>';
        }
        return '<section class="result"><h2>' . $value($s['title']) . '</h2><div class="card"><dl>' . $summary . '</dl>
            <p><strong>Manifest:</strong> <a href="' . $this->escape($manifestUrl) . '">' . $this->escape($manifestUrl) . '</a><br>
            <strong>Content:</strong> <a href="' . $this->escape($contentUrl) . '">' . $this->escape($contentUrl) . '</a></p></div>
            <h2>Versions</h2><div class="table-wrap"><table><thead><tr><th>Version</th><th>Status</th><th>Docs</th><th>Bytes</th><th>SHA-256</th><th>Published</th></tr></thead><tbody>' .
            ($versions ?: '<tr><td colspan="6">No versions.</td></tr>') . '</tbody></table></div><h2>Published documents</h2>' .
            ($documents ?: '<p>No published documents.</p>') . '<h2>Recent publish errors</h2><ul>' .
            ($errors ?: '<li>No recorded publish errors.</li>') . '</ul></section>';
    }

    private function renderLogin(?string $error = null): never
    {
        $message = $error ? '<div class="message error">' . $this->escape($error) . '</div>' : '';
        $this->renderPage('Administrator sign in', $message . '<div class="card narrow"><h1>NeonLib diagnostics</h1>
            <p>Super administrator access only.</p><form method="post" action="' . $this->basePath('/admin/login') . '">
            <input type="hidden" name="csrf" value="' . $this->escape($this->csrf()) . '"><label>Email<input type="email" name="email" required autocomplete="username"></label>
            <label>Password<input type="password" name="password" required autocomplete="current-password"></label><button>Sign in</button></form></div>');
    }

    private function renderPage(string $title, string $body): never
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->escape($title) . '</title><style>
        :root{font-family:Inter,system-ui,sans-serif;color:#18201f;background:#f3f6f5}*{box-sizing:border-box}body{margin:0}header{display:flex;justify-content:space-between;align-items:center;padding:16px max(24px,calc((100% - 1100px)/2));background:#fff;border-bottom:1px solid #dce4e1}main{max-width:1100px;margin:38px auto;padding:0 24px}.card,.table-wrap{background:#fff;border:1px solid #dce4e1;border-radius:14px;padding:22px}.narrow{max-width:430px;margin:10vh auto}.search{display:grid;grid-template-columns:1fr auto;align-items:end;gap:14px}form,label{display:grid;gap:8px}input{border:1px solid #afbfba;border-radius:9px;padding:11px 12px;font:inherit}button{border:0;border-radius:9px;padding:11px 17px;background:#176b50;color:#fff;font-weight:700;cursor:pointer}.secondary{background:#e5eeeb;color:#174d3c}.message{padding:12px 15px;border-radius:9px;margin:18px 0}.error{background:#fde8e8;color:#8c2020}.result{margin-top:32px}dl{display:grid;grid-template-columns:180px 1fr;gap:9px 18px}dt{font-weight:700}dd{margin:0;overflow-wrap:anywhere}.table-wrap{overflow:auto;padding:0}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:12px;border-bottom:1px solid #dce4e1}.code,pre{font:12px/1.5 ui-monospace,monospace}pre{white-space:pre-wrap;overflow-wrap:anywhere}.document{margin:10px 0}.document summary{cursor:pointer}a{color:#176b50;overflow-wrap:anywhere}@media(max-width:650px){.search{grid-template-columns:1fr}dl{grid-template-columns:1fr}dt{margin-top:8px}header span{display:none}}</style></head><body>' . $body . '</body></html>';
        exit;
    }

    private function header(): string
    {
        return '<header><div><strong>NeonLib diagnostics</strong> <span>' . $this->escape((string) $_SESSION['display_name']) . '</span></div>
            <form method="post" action="' . $this->basePath('/admin/logout') . '"><input type="hidden" name="csrf" value="' .
            $this->escape($this->csrf()) . '"><button class="secondary">Sign out</button></form></header>';
    }

    private function hasActiveSuperadminSession(): bool
    {
        return isset($_SESSION['user_id'], $_SESSION['last_activity'])
            && ($_SESSION['user_role'] ?? null) === 'SUPERADMIN'
            && time() - (int) $_SESSION['last_activity'] <= self::SESSION_TTL_SECONDS;
    }

    private function recordLoginAttempt(string $ip, string $email, bool $succeeded): void
    {
        $statement = $this->database->prepare('INSERT INTO admin_login_attempts (ip_address, email, succeeded) VALUES (:ip, :email, :succeeded)');
        $statement->execute(['ip' => $ip, 'email' => mb_substr($email, 0, 254), 'succeeded' => $succeeded ? 1 : 0]);
    }

    private function audit(string $event, ?string $packageId = null, ?string $details = null, ?int $userId = -1): void
    {
        $resolvedUser = $userId === -1 ? ($_SESSION['user_id'] ?? null) : $userId;
        $statement = $this->database->prepare(
            'INSERT INTO admin_audit_log (user_id, event_type, package_id, ip_address, details)
             VALUES (:user, :event, :package, :ip, :details)'
        );
        $statement->execute(['user' => $resolvedUser, 'event' => $event, 'package' => $packageId,
            'ip' => $this->clientIp(), 'details' => $details === null ? null : mb_substr($details, 0, 1000)]);
    }

    private function requireHttpsInProduction(): void
    {
        if (strtolower((string) ($_ENV['APP_ENV'] ?? 'production')) === 'production'
            && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
            throw new ApiException(404, 'not_found', 'API endpoint not found.');
        }
    }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('mobileai_admin');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
            session_start();
        }
    }

    private function verifyCsrf(): void
    {
        if (!hash_equals($this->csrf(), (string) ($_POST['csrf'] ?? ''))) {
            throw new ApiException(403, 'invalid_csrf', 'The form expired. Reload the page and try again.');
        }
    }

    private function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    private function clientIp(): string { return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45); }
    private function redirect(string $path): never { header('Location: ' . $this->basePath($path), true, 303); exit; }
    private function notFound(): never { http_response_code(404); $this->renderPage('Not found', '<p>Not found.</p>'); }
    private function basePath(string $path): string
    {
        $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return ($directory === '/' ? '' : rtrim($directory, '/')) . $path;
    }
    private function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
