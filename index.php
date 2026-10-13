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

    if (preg_match('#^/api/v1/admin/(?:vector-jobs(?:/(claim|[a-f0-9]{32}))?|subscriptions/([a-z0-9][a-z0-9._-]{1,189})/versions/([1-9][0-9]*)/vector-job)$#', $path, $jobRoute)) {
        $database=Database::connection();
        $adminId=(new AdminApiAuthenticator($database))->authenticate($_SERVER);
        $jobs=new NeonLib\VectorJobs($database,new NeonLib\PreparedVectorStore());
        $package=$jobRoute[2]??'';
        if($package!==''){
            if($method==='GET')$result=$jobs->status($package,(int)$jobRoute[3]);
            elseif($method==='POST')$result=$jobs->enqueue($package,(int)$jobRoute[3],$adminId);
            else throw new ApiException(405,'method_not_allowed','Use GET or POST.');
        }else{
            if($method!=='POST')throw new ApiException(405,'method_not_allowed','Use POST.');
            $action=$jobRoute[1]??'';
            if($action==='claim')$result=$jobs->claim();
            elseif(preg_match('/^[a-f0-9]{32}$/',$action)){
                $body=file_get_contents('php://input',false,null,0,NeonLib\PreparedVectorStore::MAX_BYTES+1);
                if($body===false || strlen($body)>NeonLib\PreparedVectorStore::MAX_BYTES)throw new ApiException(413,'request_too_large','Job update exceeds 25 MiB.');
                $result=$jobs->update($action,JsonBody::decode($body),$adminId);
            }else throw new ApiException(404,'not_found','Job route not found.');
        }
        JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$result]);
    }

    if (preg_match('#^/api/v1/admin/subscriptions/([a-z0-9][a-z0-9._-]{1,189})/versions/([1-9][0-9]*)/vectors$#', $path, $vectorRoute)) {
        if ($method !== 'POST') throw new ApiException(405, 'method_not_allowed', 'Only POST is supported.');
        $database = Database::connection();
        $adminId = (new AdminApiAuthenticator($database))->authenticate($_SERVER);
        $body = file_get_contents('php://input', false, null, 0, NeonLib\PreparedVectorStore::MAX_BYTES + 1);
        if ($body === false || strlen($body)>NeonLib\PreparedVectorStore::MAX_BYTES) throw new ApiException(413,'request_too_large','Prepared vectors exceed 25 MiB.');
        $result = (new NeonLib\PreparedVectorRepository($database, new NeonLib\PreparedVectorStore()))
            ->upload($vectorRoute[1], (int)$vectorRoute[2], JsonBody::decode($body), $adminId);
        JsonResponse::send(['requestId'=>RequestContext::id(), 'data'=>$result], 201);
    }
    if (preg_match('#^/api/v1/account/ai/devices/([a-f0-9]{32})$#', $path, $aiDevice)) {
        if (!in_array($method, ['GET','POST'], true)) throw new ApiException(405,'method_not_allowed','Use GET or POST.');
        $body=file_get_contents('php://input', false, null, 0, 65537);
        if ($body===false || strlen($body)>65536) throw new ApiException(413,'request_too_large','Request exceeds 64 KiB.');
        $database=Database::connection();
        (new RequestAuthenticator($database))->authenticate($method,$path,$body,$_SERVER);
        $account=(new AccountLinkService($database))->resolveActive((string)($_SERVER['HTTP_X_NEONLIB_SUBJECT']??''));
        $ai=new NeonLib\ManagedAiService($database);
        if ($method==='GET') $result=$ai->approvalInfo($aiDevice[1]);
        else {
            $input=JsonBody::decode($body);
            if (!is_string($input['code']??null)) throw new ApiException(422,'invalid_device','Missing device code.');
            $result=$ai->approve($aiDevice[1],$input['code'],$account);
        }
        header('Cache-Control: no-store');
        JsonResponse::send(['data'=>$result]);
    }
    if (str_starts_with($path, '/api/v1/ai/')) {
        $allowed=['/api/v1/ai/devices'=>'POST','/api/v1/ai/devices/poll'=>'POST','/api/v1/ai/usage'=>'GET','/api/v1/ai/device'=>'DELETE','/api/v1/ai/chat/completions'=>'POST'];
        if (!isset($allowed[$path])) throw new ApiException(404,'not_found','API endpoint not found.');
        if ($method!==$allowed[$path]) throw new ApiException(405,'method_not_allowed','Unsupported method.');
        header('Cache-Control: no-store');
        $body=file_get_contents('php://input', false, null, 0, 65537);
        if ($body===false || strlen($body)>65536) throw new ApiException(413,'request_too_large','Request exceeds 64 KiB.');
        $ai=new NeonLib\ManagedAiService(Database::connection());
        $token='';
        if (!in_array($path,['/api/v1/ai/devices','/api/v1/ai/devices/poll'],true)) {
            $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
            if (!preg_match('/^Bearer (nla_[a-f0-9]{32}_[a-f0-9]{64})$/',$authorization,$bearer)) throw new ApiException(401,'ai_sign_in_required','Connect your NeonLib account again.');
            $token=$bearer[1];
        }
        $result=match($path) {
            '/api/v1/ai/devices'=>$ai->start(JsonBody::decode($body),(string)($_SERVER['REMOTE_ADDR']??'unknown')),
            '/api/v1/ai/devices/poll'=>$ai->poll(JsonBody::decode($body)),
            '/api/v1/ai/usage'=>$ai->usage($token),
            '/api/v1/ai/device'=>$ai->revoke($token),
            '/api/v1/ai/chat/completions'=>$ai->complete($token,JsonBody::decode($body))
        };
        JsonResponse::send($path==='/api/v1/ai/chat/completions'?$result:['data'=>$result]);
    }
    if ($path === '/api/v1/reports') {
        if ($method !== 'POST') throw new ApiException(405, 'method_not_allowed', 'Only POST is supported.');
        $body = file_get_contents('php://input', false, null, 0, 65537);
        if ($body === false || strlen($body) > 65536) throw new ApiException(413, 'request_too_large', 'Report exceeds 64 KiB.');
        $secret = (string)($_ENV['REPORT_RATE_LIMIT_SECRET'] ?? $_ENV['WORDPRESS_CLIENT_SECRET'] ?? '');
        $result = (new NeonLib\ReportRepository(Database::connection()))->submit(JsonBody::decode($body), (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $secret);
        JsonResponse::send(['requestId'=>RequestContext::id(), 'data'=>$result], 201);
    }
    if ($path === '/api/v1/admin/reports' || preg_match('#^/api/v1/admin/reports/([a-f0-9-]{36})$#', $path, $reportMatch)) {
        $collection = $path === '/api/v1/admin/reports';
        if (($collection && $method !== 'GET') || (!$collection && $method !== 'PATCH')) throw new ApiException(405, 'method_not_allowed', 'Unsupported report method.');
        $database = Database::connection();
        $adminId = (new AdminApiAuthenticator($database))->authenticate($_SERVER);
        $reports = new NeonLib\ReportRepository($database);
        if ($collection) JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$reports->list($_GET)]);
        $body=file_get_contents('php://input', false, null, 0, 16385);
        if ($body===false || strlen($body)>16384) throw new ApiException(413,'request_too_large','Report update too large.');
        JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$reports->update($reportMatch[1],JsonBody::decode($body),$adminId)]);
    }

    if ($path === '/api/v1/mobile-transfers/claim'
        || preg_match('#^/api/v1/mobile-transfers/([a-f0-9]{32})/upload$#', $path, $mobileTransfer)) {
        if ($method !== 'POST') throw new ApiException(405,'method_not_allowed','Use POST.');
        $claim = $path === '/api/v1/mobile-transfers/claim';
        $limit = $claim ? 2048 : NeonLib\MobileTransferService::MAX_BYTES;
        $body=file_get_contents('php://input',false,null,0,$limit+1);
        if ($body===false || strlen($body)>$limit) throw new ApiException(413,'request_too_large','Transfer request is too large.');
        $service=new NeonLib\MobileTransferService(Database::connection());
        if ($claim) $result=$service->claim(JsonBody::decode($body),(string)($_SERVER['REMOTE_ADDR']??'unknown'));
        else {
            if (!preg_match('/^Bearer (nlu_[a-f0-9]{64})$/', (string)($_SERVER['HTTP_AUTHORIZATION']??''),$uploadAuthorization)) throw new ApiException(401,'invalid_upload_token','Upload authorization failed.');
            $result=$service->upload($mobileTransfer[1],$uploadAuthorization[1],JsonBody::decode($body));
        }
        header('Cache-Control: no-store');
        JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$result]);
    }

    if ($path === '/api/v1/account/mobile-transfers'
        || preg_match('#^/api/v1/account/mobile-transfers/([a-f0-9]{32})(/draft)?$#',$path,$ownedTransfer)) {
        $collection=$path==='/api/v1/account/mobile-transfers';
        $draft=isset($ownedTransfer[2]) && $ownedTransfer[2]==='/draft';
        if (($collection && !in_array($method,['GET','POST'],true)) || (!$collection && !in_array($method,['GET','DELETE'],true)) || ($draft && $method!=='GET')) throw new ApiException(405,'method_not_allowed','Unsupported transfer method.');
        $body=file_get_contents('php://input',false,null,0,2049);
        if ($body===false || strlen($body)>2048) throw new ApiException(413,'request_too_large','Transfer request is too large.');
        $database=Database::connection();
        (new RequestAuthenticator($database))->authenticate($method,$path,$body,$_SERVER);
        $account=(new AccountLinkService($database))->resolveActive((string)($_SERVER['HTTP_X_NEONLIB_SUBJECT']??''));
        $service=new NeonLib\MobileTransferService($database);
        if ($collection) {
            if ($body!=='' && trim($body)!=='{}') throw new ApiException(422,'invalid_transfer','Create transfer accepts no fields.');
            $result=$method==='GET' ? $service->drafts($account) : $service->create($account);
        } else $result=$service->owned($account,$ownedTransfer[1],$method==='DELETE'?'cancel':($draft?'draft':'status'));
        header('Cache-Control: no-store');
        JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$result],$collection && $method==='POST'?201:200);
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

    if ($path === '/api/v1/admin/subscriptions/bulk-delete') {
        if ($method !== 'POST') throw new ApiException(405, 'method_not_allowed', 'Only POST is supported.');
        $database = Database::connection();
        $adminUserId = (new AdminApiAuthenticator($database))->authenticate($_SERVER);
        $body = file_get_contents('php://input', false, null, 0, 32769);
        if ($body === false || strlen($body) > 32768) throw new ApiException(413, 'request_too_large', 'Request too large.');
        $data = (new AdminApiRepository($database, $adminUserId))->deleteSubscriptions(JsonBody::decode($body));
        JsonResponse::send(['requestId'=>RequestContext::id(),'data'=>$data]);
    }

    if ($path === '/api/v1/admin/accounts'
        || preg_match('#^/api/v1/admin/accounts/(acc_[0-9a-hjkmnp-tv-z]{26})$#', $path, $adminAccount)
        || $path === '/api/v1/admin/subscriptions'
        || preg_match('#^/api/v1/admin/subscriptions/([a-z0-9][a-z0-9._-]*)$#', $path, $adminSubscription)) {
        if (!in_array($method, ['GET', 'PATCH'], true)) throw new ApiException(405, 'method_not_allowed', 'Method is not supported for this admin resource.');
        $isCollection = in_array($path, ['/api/v1/admin/accounts', '/api/v1/admin/subscriptions'], true);
        if ($isCollection && $method !== 'GET') throw new ApiException(405, 'method_not_allowed', 'Admin collections are read-only.');
        $body = $method === 'PATCH' ? file_get_contents('php://input') : '';
        $adminBodyLimit = isset($adminSubscription[1]) ? 16_777_216 : 65_536;
        if ($body === false || strlen($body) > $adminBodyLimit) throw new ApiException(413, 'request_too_large', 'Admin request body exceeds the allowed size.');
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

    if ($path === '/api/v1/subscriptions/availability') {
        header('Cache-Control: no-store');
        JsonResponse::send($repository->availability((string) ($_GET['ids'] ?? '')));
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
