<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Models\InmuebleRepository;
use Throwable;

final class ApiInmuebleController
{
    public function store(): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $payload = Request::json();
        $this->save($payload, 201);
    }

    public function update(string $referenceId): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $payload = Request::json();
        $payload['reference_id'] = $referenceId;
        $this->save($payload);
    }

    public function unpublish(string $referenceId): void
    {
        if (!$this->authorized()) {
            Response::json(['ok' => false, 'error' => 'No autorizado'], 401);
            return;
        }

        $payload = Request::json();
        $ok = (new InmuebleRepository())->markForDelete($referenceId, $payload['razon_despublicacion'] ?? null);
        Response::json(['ok' => $ok, 'reference_id' => $referenceId], $ok ? 200 : 404);
    }

    private function save(array $payload, int $status = 200): void
    {
        try {
            $this->validate($payload);
            $id = (new InmuebleRepository())->upsertFromPayload($payload);
            Response::json([
                'ok' => true,
                'id' => $id,
                'reference_id' => $payload['reference_id'] ?? (string) ($payload['wp_post_id'] ?? $payload['id_inmueble'] ?? $id),
                'sync_status' => 'pending',
            ], $status);
        } catch (Throwable $exception) {
            Response::json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    private function validate(array $payload): void
    {
        foreach (['titulo', 'descripcion', 'tipo_inmueble', 'direccion'] as $field) {
            if (empty($payload[$field])) {
                throw new \InvalidArgumentException("Falta el campo {$field}.");
            }
        }

        if (empty($payload['precio_venta']) && empty($payload['precio_arriendo'])) {
            throw new \InvalidArgumentException('Debes enviar precio_venta o precio_arriendo.');
        }

        if ((empty($payload['latitud']) || empty($payload['longitud'])) && empty($payload['barrio'])) {
            throw new \InvalidArgumentException('Debes enviar latitud/longitud o un barrio para buscar coordenadas.');
        }
    }

    private function authorized(): bool
    {
        $secret = Env::get('API_SHARED_SECRET', '');
        return $secret !== '' && hash_equals($secret, Request::header('X-Api-Key') ?? '');
    }
}
