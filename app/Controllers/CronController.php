<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Models\InmuebleRepository;
use App\Services\FincaraizSyncService;
use App\Services\ProppitSyncService;

final class CronController
{
    public function sync(): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $repository = new InmuebleRepository();
        $audit = $repository->refreshProppitQueueFromInmuebles();
        $result = (new ProppitSyncService())->run(20);
        Response::json(['ok' => true, 'audit' => $audit] + $result);
    }

    public function syncFincaraiz(): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $repository = new InmuebleRepository();
        $audit = $repository->refreshFincaraizQueueFromInmuebles();
        $result = (new FincaraizSyncService())->run(20);
        Response::json(['ok' => true, 'audit' => $audit] + $result);
    }

    public function syncAll(): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $repository = new InmuebleRepository();
        $proppitAudit = $repository->refreshProppitQueueFromInmuebles();
        $fincaraizAudit = $repository->refreshFincaraizQueueFromInmuebles();

        Response::json([
            'ok' => true,
            'proppit' => [
                'audit' => $proppitAudit,
                'sync' => (new ProppitSyncService())->run(20),
            ],
            'fincaraiz' => [
                'audit' => $fincaraizAudit,
                'sync' => (new FincaraizSyncService())->run(20),
            ],
        ]);
    }

    private function authorized(): bool
    {
        $secret = Env::get('API_SHARED_SECRET', '');
        return $secret !== '' && hash_equals($secret, Request::header('X-Api-Key') ?? '');
    }
}
