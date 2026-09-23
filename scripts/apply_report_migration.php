<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
// Uses DB_DATABASE from this installation; never replays earlier migrations/seeds.
$sql=file_get_contents(dirname(__DIR__).'/database/010_content_reports.sql');
if ($sql===false) throw new RuntimeException('Migration file missing.');
NeonLib\Database::connection()->exec($sql);
echo "Migration 010_content_reports.sql applied. Existing application rows were not changed.\n";
