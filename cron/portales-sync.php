<?php

declare(strict_types=1);

use App\Core\Env;
use App\Models\InmuebleRepository;
use App\Services\FincaraizSyncService;
use App\Services\ProppitSyncService;

require dirname(__DIR__) . '/app/bootstrap.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Bogota') ?: 'America/Bogota');

$startedAt = date('Y-m-d H:i:s');
$limit = max(1, min(50, (int) ($_SERVER['argv'][1] ?? getenv('PORTALES_CRON_LIMIT') ?: 20)));

try {
    $repository = new InmuebleRepository();

    $proppitAudit = $repository->refreshProppitQueueFromInmuebles();
    $fincaraizAudit = $repository->refreshFincaraizQueueFromInmuebles();

    $proppitSync = (new ProppitSyncService())->run($limit);
    $fincaraizSync = (new FincaraizSyncService())->run($limit);

    echo json_encode([
        'ok' => true,
        'started_at' => $startedAt,
        'finished_at' => date('Y-m-d H:i:s'),
        'limit' => $limit,
        'proppit' => [
            'audit' => $proppitAudit,
            'sync' => $proppitSync,
        ],
        'fincaraiz' => [
            'audit' => $fincaraizAudit,
            'sync' => $fincaraizSync,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'started_at' => $startedAt,
        'finished_at' => date('Y-m-d H:i:s'),
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
