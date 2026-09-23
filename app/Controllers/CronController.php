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
    public function syncMercadolibre(): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }
        try {
            Response::json((new \App\Services\MercadolibreSyncService())->run(10));
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

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
        $service = new FincaraizSyncService();
        $remote = $service->reconcileRemoteListings();
        $audit = $repository->refreshFincaraizQueueFromInmuebles();
        $result = $service->run(20);
        Response::json(['ok' => true, 'remote' => $remote, 'audit' => $audit] + $result);
    }

    public function syncAll(): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $repository = new InmuebleRepository();
        $proppitAudit = $repository->refreshProppitQueueFromInmuebles();
        $fincaraizService = new FincaraizSyncService();
        $fincaraizRemote = $fincaraizService->reconcileRemoteListings();
        $fincaraizAudit = $repository->refreshFincaraizQueueFromInmuebles();

        $mercadolibre = ['status' => 'disabled'];
        if (Env::bool('MERCADOLIBRE_ENABLED')) {
            try {
                $mercadolibre = (new \App\Services\MercadolibreSyncService())->run(10);
            } catch (\Throwable $e) {
                $mercadolibre = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        Response::json([
            'ok' => true,
            'proppit' => [
                'audit' => $proppitAudit,
                'sync' => (new ProppitSyncService())->run(20),
            ],
            'fincaraiz' => [
                'remote' => $fincaraizRemote,
                'audit' => $fincaraizAudit,
                'sync' => $fincaraizService->run(20),
            ],
            'mercadolibre' => $mercadolibre,
        ]);
    }

    private function authorized(): bool
    {
        $secret = Env::get('API_SHARED_SECRET', '');
        return $secret !== '' && hash_equals($secret, Request::header('X-Api-Key') ?? '');
    }
}
