<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NeonLib\AccountLinkService;
use NeonLib\ApiException;
use NeonLib\AdminPortal;
use NeonLib\AdminApiAuthenticator;
use NeonLib\AdminApiRepository;
use NeonLib\Database;
use NeonLib\DiagnosticPortal;
use NeonLib\JsonBody;
use NeonLib\JsonResponse;
use NeonLib\OwnedSubscriptionRepository;
use NeonLib\OwnedSubscriptionVersionRepository;
use NeonLib\RequestAuthenticator;
use NeonLib\RequestContext;
use NeonLib\SubscriptionRepository;

try {
    RequestContext::initialize($_SERVER['HTTP_X_REQUEST_ID'] ?? null);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($scriptDirectory !== '/' && str_starts_with($path, $scriptDirectory)) {
        $path = substr($path, strlen($scriptDirectory)) ?: '/';
    }
    $path = '/' . trim($path, '/');

    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        $adminPortalMode = strtolower(trim((string) ($_ENV['ADMIN_PORTAL_MODE'] ?? 'disabled')));
        if (!in_array($adminPortalMode, ['readonly', 'development'], true)) {
            throw new ApiException(404, 'not_found', 'API endpoint not found.');
        }

        $portal = $adminPortalMode === 'readonly'
            ? new DiagnosticPortal(Database::connection())
            : new AdminPortal(Database::connection());
        $portal->handle($method, $path);
    }

    if ($path === '/api/v1/accounts/link') {
        if (!in_array($method, ['POST', 'GET', 'PUT', 'DELETE'], true)) {
            throw new ApiException(405, 'method_not_allowed', 'Supported methods are POST, GET, PUT and DELETE.');
        }
        $body = in_array($method, ['POST', 'PUT'], true) ? file_get_contents('php://input') : '';
        if ($body === false) {
            throw new ApiException(400, 'invalid_request', 'Unable to read request body.');
        }
        $database = Database::connection();
        (new RequestAuthenticator($database))->authenticate($method, $path, $body, $_SERVER);
        $links = new AccountLinkService($database);
        if ($method === 'GET') {
            JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $links->get((string) ($_SERVER['HTTP_X_NEONLIB_SUBJECT'] ?? ''))]);
        }
        if ($method === 'PUT') {
            JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $links->refresh((string) ($_SERVER['HTTP_X_NEONLIB_SUBJECT'] ?? ''), JsonBody::decode($body))]);
        }
        if ($method === 'DELETE') {
            $links->unlink((string) ($_SERVER['HTTP_X_NEONLIB_SUBJECT'] ?? ''));
            JsonResponse::noContent();
        }
        $result = $links->link(JsonBody::decode($body));
        JsonResponse::send([
            'requestId' => RequestContext::id(),
            'data' => $result['account'],
        ], $result['created'] ? 201 : 200);
    }

    if ($path === '/api/v1/account/subscriptions'
        || preg_match('#^/api/v1/account/subscriptions/([a-z0-9][a-z0-9._-]*)$#', $path, $ownedMatches)) {
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
            throw new ApiException(405, 'method_not_allowed', 'Method is not supported for this resource.');
        }
        $isCollection = $path === '/api/v1/account/subscriptions';
        if (($isCollection && in_array($method, ['PATCH', 'DELETE'], true)) || (!$isCollection && $method === 'POST')) {
            throw new ApiException(405, 'method_not_allowed', 'Method is not supported for this resource.');
        }
        $body = in_array($method, ['POST', 'PATCH'], true) ? file_get_contents('php://input') : '';
        if ($body === false) throw new ApiException(400, 'invalid_request', 'Unable to read request body.');
        $database = Database::connection();
        (new RequestAuthenticator($database))->authenticate($method, $path, $body, $_SERVER);
        $accountId = (new AccountLinkService($database))->resolveActive((string) ($_SERVER['HTTP_X_NEONLIB_SUBJECT'] ?? ''));
        $owned = new OwnedSubscriptionRepository($database);
        if ($isCollection && $method === 'GET') JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $owned->list($accountId)]);
        if ($isCollection && $method === 'POST') JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $owned->create($accountId, JsonBody::decode($body))], 201);
        $packageId = $ownedMatches[1];
        if ($method === 'GET') JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $owned->get($accountId, $packageId)]);
        if ($method === 'PATCH') JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $owned->update($accountId, $packageId, JsonBody::decode($body))]);
        $owned->delete($accountId, $packageId);
        JsonResponse::noContent();
    }

    if ($path === '/api/v1/account/publisher') {
        if (!in_array($method, ['GET', 'PUT'], true)) {
            throw new ApiException(405, 'method_not_allowed', 'Supported methods are GET and PUT.');
        }
        $body = $method === 'PUT' ? file_get_contents('php://input') : '';
        if ($body === false) throw new ApiException(400, 'invalid_request', 'Unable to read request body.');
        $database = Database::connection();
        (new RequestAuthenticator($database))->authenticate($method, $path, $body, $_SERVER);
        $accountId = (new AccountLinkService($database))->resolveActive((string) ($_SERVER['HTTP_X_NEONLIB_SUBJECT'] ?? ''));
        $owned = new OwnedSubscriptionRepository($database);
        $publisher = $method === 'PUT'
            ? $owned->updatePublisher($accountId, JsonBody::decode($body))
            : $owned->publisher($accountId);
        JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $publisher]);
    }

    if (preg_match('#^/api/v1/account/subscriptions/([a-z0-9][a-z0-9._-]*)/versions$#', $path, $versionCollection)
        || preg_match('#^/api/v1/account/subscriptions/([a-z0-9][a-z0-9._-]*)/versions/([1-9]\d*)$#', $path, $versionItem)) {
        $isVersionCollection = isset($versionCollection[1]);
        if (($isVersionCollection && !in_array($method, ['GET', 'POST'], true)) || (!$isVersionCollection && $method !== 'GET')) {
            throw new ApiException(405, 'method_not_allowed', 'Published versions are immutable.');
        }
        $body = $method === 'POST' ? file_get_contents('php://input') : '';
        if ($body === false) throw new ApiException(400, 'invalid_request', 'Unable to read request body.');
        if (strlen($body) > 10_485_760) throw new ApiException(413, 'request_too_large', 'Request body exceeds 10 MiB.');
        $database = Database::connection();
        (new RequestAuthenticator($database))->authenticate($method, $path, $body, $_SERVER);
        $accountId = (new AccountLinkService($database))->resolveActive((string) ($_SERVER['HTTP_X_NEONLIB_SUBJECT'] ?? ''));
        $versions = new OwnedSubscriptionVersionRepository($database);
        $packageId = $isVersionCollection ? $versionCollection[1] : $versionItem[1];
        if ($isVersionCollection && $method === 'GET') JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $versions->list($accountId, $packageId)]);
        if ($isVersionCollection) JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $versions->publish($accountId, $packageId, JsonBody::decode($body))], 201);
        JsonResponse::send(['requestId' => RequestContext::id(), 'data' => $versions->get($accountId, $packageId, (int) $versionItem[2])]);
    }

    if ($path === '/api/v1/admin/accounts'
        || preg_match('#^/api/v1/admin/accounts/(acc_[0-9a-hjkmnp-tv-z]{26})$#', $path, $adminAccount)
        || $path === '/api/v1/admin/subscriptions'
        || preg_match('#^/api/v1/admin/subscriptions/([a-z0-9][a-z0-9._-]*)$#', $path, $adminSubscription)) {
        if (!in_array($method, ['GET', 'PATCH'], true)) throw new ApiException(405, 'method_not_allowed', 'Method is not supported for this admin resource.');
        $isCollection = in_array($path, ['/api/v1/admin/accounts', '/api/v1/admin/subscriptions'], true);
        if ($isCollection && $method !== 'GET') throw new ApiException(405, 'method_not_allowed', 'Admin collections are read-only.');
        $body = $method === 'PATCH' ? file_get_contents('php://input') : '';
        if ($body === false || strlen($body) > 65_536) throw new ApiException(413, 'request_too_large', 'Admin request body exceeds 64 KiB.');
        $database = Database::connection();
        $adminUserId = (new AdminApiAuthenticator($database))->authenticate($_SERVER);
        $admin = new AdminApiRepository($database, $adminUserId);
        if ($path === '/api/v1/admin/accounts') JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$admin->accounts($_GET)]);
        if (isset($adminAccount[1])) {
            $data = $method === 'GET' ? $admin->account($adminAccount[1]) : $admin->updateAccount($adminAccount[1], JsonBody::decode($body));
            JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$data]);
        }
        if ($path === '/api/v1/admin/subscriptions') JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$admin->subscriptions($_GET)]);
        $data = $method === 'GET' ? $admin->subscription($adminSubscription[1]) : $admin->updateSubscription($adminSubscription[1], JsonBody::decode($body));
        JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$data]);
    }

    if ($method !== 'GET') {
        throw new ApiException(405, 'method_not_allowed', 'Only GET is supported by the public API.');
    }

    $repository = new SubscriptionRepository(Database::connection());

    if ($path === '/api/v1/health') {
        JsonResponse::send([
            'status' => 'ok',
            'service' => 'neonlib-api',
            'apiVersion' => 'v1',
            'timestamp' => gmdate(DATE_ATOM),
        ]);
    }

    if ($path === '/api/v1/subscriptions' || $path === '/api/v1/subscriptions/featured') {
        JsonResponse::send($repository->featured());
    }

    if ($path === '/api/v1/subscriptions/search') {
        JsonResponse::send($repository->search((string) ($_GET['q'] ?? '')));
    }

    if (preg_match('#^/api/v1/subscriptions/([a-z0-9][a-z0-9._-]*)/manifest$#', $path, $matches)) {
        JsonResponse::send($repository->manifest($matches[1]));
    }

    if (preg_match('#^/api/v1/subscriptions/([a-z0-9][a-z0-9._-]*)/content$#', $path, $matches)) {
        JsonResponse::send($repository->content($matches[1]));
    }

    throw new ApiException(404, 'not_found', 'API endpoint not found.');
} catch (ApiException $exception) {
    JsonResponse::error($exception->statusCode, $exception->errorCode, $exception->getMessage(), $exception->details);
} catch (Throwable $exception) {
    error_log((string) $exception);
    $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    JsonResponse::error(
        500,
        'server_error',
        $debug ? $exception->getMessage() : 'Unexpected server error.'
    );
}
