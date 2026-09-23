<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Models\InmuebleRepository;
use App\Models\MercadolibreRepository;
use RuntimeException;
use Throwable;

final class MercadolibreSyncService
{
    private MercadolibreRepository $repository;
    private MercadolibreClient $client;
    private MercadolibrePayloadBuilder $builder;
    private ?array $remaining = null;

    public function __construct(?MercadolibreRepository $repository = null, ?MercadolibreClient $client = null)
    {
        $this->repository = $repository ?? new MercadolibreRepository();
        $this->repository->migrate();
        $this->client = $client ?? new MercadolibreClient($this->repository);
        $this->builder = new MercadolibrePayloadBuilder($this->client);
    }

    public function run(int $limit = 10, ?int $onlyId = null, bool $dryRun = false): array
    {
        if (!$dryRun && (!Env::bool('MERCADOLIBRE_ENABLED') || !MercadolibreClient::configured() || !$this->repository->account())) {
            return ['ok' => false, 'status' => 'not_connected', 'message' => 'Mercado Libre pendiente de configuracion y autorizacion.'];
        }
        if (!$this->repository->lock('worker')) {
            return ['ok' => true, 'status' => 'busy', 'message' => 'Ya hay un proceso de Mercado Libre en curso.'];
        }
        try {
            $source = new InmuebleRepository();
            if (!$dryRun) {
                $this->repository->recoverInterrupted();
            }
            $audit = $this->scan($onlyId, $dryRun);
            if ($dryRun) {
                return ['ok' => true, 'dry_run' => true, 'audit' => $audit];
            }
            $stats = ['ok' => true, 'audit' => $audit, 'processed' => 0, 'success' => 0, 'failed' => 0, 'waiting_quota' => 0];
            foreach ($this->repository->pending($limit, $onlyId) as $ad) {
                if (!$this->repository->processing($ad)) {
                    continue;
                }
                $stats['processed']++;
                try {
                    $remote = $this->syncOne($ad, $source->findFull((int) $ad['inmueble_id']));
                    $this->repository->complete($ad, $remote);
                    $this->repository->log($ad, true, 200);
                    $stats['success']++;
                } catch (Throwable $e) {
                    if ($e->getCode() === 1001) {
                        $this->repository->waitQuota($ad, $e->getMessage());
                        $stats['waiting_quota']++;
                        continue;
                    }
                    $uncertain = $e->getCode() === 1002;
                    $this->repository->fail($ad, $e->getMessage(), $uncertain);
                    $this->repository->log($ad, false, $e->getCode() < 600 ? (int) $e->getCode() : 0, $e->getMessage());
                    $stats['failed']++;
                    $stats['ok'] = false;
                }
            }
            return $stats;
        } finally {
            $this->repository->unlock('worker');
        }
    }

    private function scan(?int $onlyId, bool $dryRun): array
    {
        $ads = $this->repository->allAds();
        $stats = ['publish' => 0, 'update' => 0, 'pause' => 0, 'delete' => 0, 'unchanged' => 0];
        foreach ($this->repository->properties($onlyId) as $id => $property) {
            $ad = $ads[$id] ?? null;
            if (!$ad && !MercadolibrePayloadBuilder::available($property)) {
                continue;
            }
            $intent = $ad['intent'] ?? 'auto';
            $hash = MercadolibrePayloadBuilder::fingerprint($property, $intent);
            $action = self::desiredAction($property, $ad ?? []);
            if ($ad && $ad['target_hash'] === $hash) {
                $stats['unchanged']++;
                continue;
            }
            $stats[$action]++;
            if (!$dryRun) {
                $this->repository->ensure((int) $id, (string) $property['reference_id']);
                $ad ??= $this->repository->ad((int) $id);
                $this->repository->target($ad, $hash, $action);
            }
        }
        if (!$dryRun && $onlyId === null) {
            // Retain the remote id even if a source row was physically removed.
            Database::pdo()->exec("UPDATE mercadolibre_ads a LEFT JOIN inmuebles i ON i.id=a.inmueble_id
                SET a.desired_action='pause',a.sync_status='pending',a.version=a.version+1,a.attempts=0,a.next_attempt_at=NULL
                WHERE i.id IS NULL AND a.external_id IS NOT NULL AND a.remote_status='active' AND a.desired_action<>'pause'");
        }
        return $stats;
    }

    public static function desiredAction(array $property, array $ad): string
    {
        if (($ad['intent'] ?? '') === 'deleted') {
            return 'delete';
        }
        if (($ad['intent'] ?? '') === 'paused' || !MercadolibrePayloadBuilder::available($property)) {
            return 'pause';
        }
        return empty($ad['external_id']) ? 'publish' : 'update';
    }

    private function syncOne(array $ad, ?array $property): string
    {
        $action = $ad['desired_action'];
        $item = null;
        if (!empty($ad['external_id'])) {
            $result = $this->client->get('/items/' . rawurlencode($ad['external_id']));
            $item = MercadolibreClient::requireSuccess($result);
        } else {
            $account = $this->repository->account();
            $found = MercadolibreClient::requireSuccess($this->client->get('/users/' . rawurlencode($account['user_id']) . '/items/search', ['sku' => $ad['reference_id']]));
            $matches = $found['results'] ?? [];
            if ((int) ($found['paging']['total'] ?? count($matches)) > 1) {
                throw new RuntimeException('Hay varios anuncios con este codigo en Mercado Libre. Revisa los duplicados antes de continuar.');
            }
            if (count($matches) === 1) {
                $candidate = MercadolibreClient::requireSuccess($this->client->get('/items/' . rawurlencode($matches[0])));
                if ((string) ($candidate['seller_custom_field'] ?? '') !== (string) $ad['reference_id']) {
                    throw new RuntimeException('La busqueda devolvio un anuncio con otro codigo.');
                }
                $item = $candidate;
            } elseif (!empty($ad['uncertain'])) {
                throw new RuntimeException('Una creacion anterior tuvo respuesta incierta. No se encontro aun el anuncio por codigo; revisa Mercado Libre antes de volver a crear.', 1002);
            }
        }
        if ($item) {
            $account = $this->repository->account();
            if ((string) ($item['seller_id'] ?? '') !== (string) $account['user_id'] || ($item['site_id'] ?? '') !== 'MCO') {
                throw new RuntimeException('El anuncio no pertenece a la cuenta colombiana conectada.');
            }
            $this->repository->rememberRemote((int) $ad['inmueble_id'], $item);
        }
        if (in_array($action, ['pause','delete'], true)) {
            if (!$item) {
                return 'not_sent';
            }
            if (in_array('deleted', $item['sub_status'] ?? [], true)) {
                return 'deleted';
            }
            if ($action === 'pause') {
                if (!in_array($item['status'], ['paused','closed'], true)) {
                    MercadolibreClient::requireSuccess($this->client->update($item['id'], ['status' => 'paused']));
                }
                return $this->verify($item['id'], ['paused','closed']);
            }
            if ($item['status'] !== 'closed') {
                MercadolibreClient::requireSuccess($this->client->update($item['id'], ['status' => 'closed']));
            }
            MercadolibreClient::requireSuccess($this->client->update($item['id'], ['deleted' => true]));
            return $this->verify($item['id'], ['deleted']);
        }
        if (!$property || !MercadolibrePayloadBuilder::available($property)) {
            throw new RuntimeException('El inmueble ya no esta disponible. El siguiente cron lo despublicara.');
        }
        if ($item && ($item['status'] === 'closed' || in_array('deleted', $item['sub_status'] ?? [], true))) {
            throw new RuntimeException('El anuncio esta finalizado. No se puede reactivar; requiere una republicacion revisada.');
        }
        $listingType = $item['listing_type_id'] ?? $this->listingType();
        $payload = $this->builder->build($property, $listingType);
        if (!$item) {
            MercadolibreClient::requireSuccess($this->client->validate($payload));
            $result = $this->client->create($payload);
            if ($result['status'] === 0 || $result['status'] >= 500 || ($result['success'] && empty($result['body']['id']))) {
                throw new RuntimeException('La creacion no devolvio confirmacion fiable. Se buscara el codigo antes de volver a publicar.', 1002);
            }
            $item = MercadolibreClient::requireSuccess($result);
            $this->repository->rememberRemote((int) $ad['inmueble_id'], $item);
            if ($this->remaining !== null) {
                $this->remaining[$listingType] = max(0, ($this->remaining[$listingType] ?? 0) - 1);
            }
        } else {
            $update = array_intersect_key($payload, array_flip(['title','price','category_id','pictures','location','attributes','seller_contact']));
            // A paused ad receives its new data before it becomes visible again.
            MercadolibreClient::requireSuccess($this->client->update($item['id'], $update));
            MercadolibreClient::requireSuccess($this->client->description($item['id'], $payload['description']['plain_text']));
            if ($item['status'] === 'paused') {
                MercadolibreClient::requireSuccess($this->client->update($item['id'], ['status' => 'active']));
            }
        }
        return $this->verify($item['id'], ['active']);
    }

    private function verify(string $id, array $expected): string
    {
        $item = MercadolibreClient::requireSuccess($this->client->get('/items/' . rawurlencode($id)));
        $status = in_array('deleted', $item['sub_status'] ?? [], true) ? 'deleted' : ($item['status'] ?? 'unknown');
        if (!in_array($status, $expected, true)) {
            throw new RuntimeException('Mercado Libre informa estado ' . $status . '; falta confirmar ' . implode('/', $expected) . '.');
        }
        return $status;
    }

    public function packs(): array
    {
        $account = $this->repository->account();
        if (!$account) {
            throw new RuntimeException('Conecta Mercado Libre para consultar tu paquete.');
        }
        return MercadolibreClient::requireSuccess($this->client->get('/users/' . rawurlencode($account['user_id']) . '/classifieds_promotion_packs', ['package_content' => 'publications', 'status' => 'active']));
    }

    private function listingType(): string
    {
        if ($this->remaining === null) {
            $this->remaining = self::availablePacks($this->packs(), $this->builder->rootCategory());
        }
        foreach (['silver','gold','gold_premium'] as $type) {
            if (($this->remaining[$type] ?? 0) > 0) {
                return $type;
            }
        }
        throw new RuntimeException('Sin cupos disponibles en el paquete inmobiliario. Se reintentara cuando se libere un cupo.', 1001);
    }

    public static function availablePacks(array $packs, string $category): array
    {
        $remaining = [];
        foreach ($packs as $pack) {
            if (($pack['status'] ?? '') !== 'active' || ($pack['category_id'] ?? '') !== $category
                || ($pack['package_content'] ?? '') !== 'publications') {
                continue;
            }
            if (!empty($pack['date_expires']) && strtotime($pack['date_expires']) <= time()) {
                continue;
            }
            foreach ($pack['listing_details'] ?? [] as $detail) {
                $type = $detail['listing_type_id'] ?? '';
                $remaining[$type] = ($remaining[$type] ?? 0) + max(0, (int) ($detail['remaining_listings'] ?? 0));
            }
        }
        return $remaining;
    }
}
