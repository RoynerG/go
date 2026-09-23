<?php

declare(strict_types=1);

use App\Core\Env;
use App\Services\MercadolibreSyncService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Bogota'));
try {
    $dryRun = in_array('--dry-run', $argv, true);
    $limit = max(1, min(50, (int) Env::get('MERCADOLIBRE_CRON_LIMIT', '10')));
    $result = (new MercadolibreSyncService())->run($limit, null, $dryRun);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
