<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/src/bootstrap.php';
$service=new NeonLib\MobileTransferService(NeonLib\Database::connection());
echo 'Expired transfers cleaned: ' . $service->cleanup() . PHP_EOL;
