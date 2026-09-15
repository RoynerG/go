<?php

declare(strict_types=1);

use App\Core\Env;
use App\Models\InmuebleRepository;
use App\Services\ProppitSyncService;

require dirname(__DIR__) . '/app/bootstrap.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Bogota') ?: 'America/Bogota');

$startedAt = date('Y-m-d H:i:s');
$limit = max(1, min(50, (int) ($_SERVER['argv'][1] ?? getenv('PROPPIT_CRON_LIMIT') ?: 10)));

try {
    $repository = new InmuebleRepository();
    $audit = $repository->refreshProppitQueueFromInmuebles();
    $service = new ProppitSyncService();
    if (method_exists($service, 'run')) {
        $result = $service->run($limit);
    } elseif (method_exists($service, 'runPending')) {
        $result = $service->runPending($limit);
    } elseif (method_exists($service, 'syncPending')) {
        $result = $service->syncPending($limit);
    } else {
        throw new RuntimeException('No se encontro un metodo para procesar pendientes en ProppitSyncService.');
    }

    echo json_encode([
        'ok' => true,
        'started_at' => $startedAt,
        'finished_at' => date('Y-m-d H:i:s'),
        'limit' => $limit,
        'audit' => $audit,
        'sync' => $result,
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
