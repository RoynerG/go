<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\InmuebleRepository;
use PDO;
use Throwable;

final class ProppitSyncService
{
    private PDO $pdo;
    private InmuebleRepository $inmuebles;
    private ProppitPayloadBuilder $builder;
    private ProppitClient $client;

    public function __construct()
    {
        $this->pdo = Database::pdo();
        $this->inmuebles = new InmuebleRepository();
        $this->builder = new ProppitPayloadBuilder();
        $this->client = new ProppitClient();
    }

    public function run(int $limit = 20): array
    {
        $this->resetStaleProcessing();
        $ads = $this->pendingAds($limit);
        return $this->runAds($ads);
    }

    public function runPublicMarked(int $limit = 20): array
    {
        $this->resetStaleProcessing();
        $ads = $this->pendingPublicMarkedAds($limit);
        return $this->runAds($ads);
    }

    private function runAds(array $ads): array
    {
        $result = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'inmueble_ids' => []];

        foreach ($ads as $ad) {
            $result['processed']++;
            $result['inmueble_ids'][] = (int) $ad['inmueble_id'];
            try {
                $this->markProcessing((int) $ad['id']);
                $this->syncOne($ad);
                $result['synced']++;
            } catch (Throwable $exception) {
                $this->fail((int) $ad['id'], $exception->getMessage());
                $this->log((int) $ad['inmueble_id'], 'sync_error', 'LOCAL', 'worker', null, null, null, false, $exception->getMessage());
                $result['failed']++;
            }
        }

        return $result;
    }

    public function runInmueble(int $inmuebleId): array
    {
        $ad = $this->adByInmueble($inmuebleId);
        $result = ['processed' => 0, 'synced' => 0, 'failed' => 0];
        if (!$ad) {
            return $result;
        }

        $result['processed']++;
        try {
            $this->markProcessing((int) $ad['id']);
            $this->syncOne($ad);
            $result['synced']++;
        } catch (Throwable $exception) {
            $this->fail((int) $ad['id'], $exception->getMessage());
            $this->log((int) $ad['inmueble_id'], 'sync_error', 'LOCAL', 'worker', null, null, null, false, $exception->getMessage());
            $result['failed']++;
        }

        return $result;
    }

    private function syncOne(array $ad): void
    {
        $inmueble = $this->inmuebles->findFull((int) $ad['inmueble_id']);
        if (!$inmueble) {
            throw new \RuntimeException('Inmueble no encontrado.');
        }

        if ($ad['desired_action'] === 'delete' || $inmueble['estado'] === 'no_disponible') {
            if ($ad['remote_status'] !== 'published') {
                $this->markSynced((int) $ad['id'], 'deleted', null, [
                    'success' => true,
                    'status' => 204,
                    'body' => ['skipped' => true, 'reason' => 'not_published_in_proppit'],
                ]);
                return;
            }

            $response = $this->client->deleteAd($ad['country'], $inmueble['reference_id'], $ad['publisher_external_id']);
            $this->log((int) $ad['inmueble_id'], 'delete_ad', 'DELETE', '/ads/' . $inmueble['reference_id'], null, $response, $response['status'], $response['success'], $response['error']);
            if (!$response['success']) {
                throw new \RuntimeException($this->responseError($response));
            }
            $this->markSynced((int) $ad['id'], 'deleted', null, $response);
            return;
        }

        $payload = $this->builder->build($inmueble, $ad);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($ad['remote_status'] === 'published' && $ad['payload_hash'] === $hash && $ad['desired_action'] !== 'update') {
            $this->markSynced((int) $ad['id'], 'published', $payload, ['success' => true, 'status' => 204, 'body' => ['skipped' => true]]);
            return;
        }

        if ($ad['remote_status'] === 'published') {
            $response = $this->client->updateAd($ad['country'], $inmueble['reference_id'], $payload);
            $action = 'update_ad';
            $method = 'PUT';
        } else {
            $response = $this->client->createAd($ad['country'], $payload);
            $action = 'create_ad';
            $method = 'POST';
        }

        $this->log((int) $ad['inmueble_id'], $action, $method, '/ads', $payload, $response, $response['status'], $response['success'], $response['error']);
        if (!$response['success']) {
            throw new \RuntimeException($this->responseError($response));
        }

        $this->markSynced((int) $ad['id'], 'published', $payload, $response, $hash);
    }

    private function pendingAds(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM proppit_ads
             WHERE sync_status IN ('pending','failed')
               AND (next_sync_at IS NULL OR next_sync_at <= NOW())
             ORDER BY updated_at ASC
             LIMIT :limit"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    private function resetStaleProcessing(): void
    {
        $this->pdo->exec(
            "UPDATE proppit_ads
             SET sync_status = 'pending', updated_at = NOW()
             WHERE sync_status = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
    }

    private function pendingPublicMarkedAds(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.*
             FROM proppit_ads p
             INNER JOIN inmuebles i ON i.id = p.inmueble_id
             WHERE i.publicar_proppit = 1
               AND i.estado <> 'no_disponible'
               AND p.desired_action IN ('publish', 'update')
               AND p.sync_status IN ('pending','failed')
               AND (p.next_sync_at IS NULL OR p.next_sync_at <= NOW())
             ORDER BY p.updated_at ASC
             LIMIT :limit"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    private function adByInmueble(int $inmuebleId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM proppit_ads WHERE inmueble_id = :inmueble_id LIMIT 1');
        $statement->execute(['inmueble_id' => $inmuebleId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function markProcessing(int $id): void
    {
        $this->pdo->prepare("UPDATE proppit_ads SET sync_status = 'processing', updated_at = NOW() WHERE id = :id")->execute(['id' => $id]);
    }

    private function markSynced(int $id, string $remoteStatus, ?array $payload, array $response, ?string $hash = null): void
    {
        $desiredAction = $remoteStatus === 'deleted' ? 'delete' : 'publish';
        $statement = $this->pdo->prepare(
            "UPDATE proppit_ads
             SET remote_status = :remote_status,
                 sync_status = 'synced',
                 desired_action = :desired_action,
                 payload_hash = COALESCE(:payload_hash, payload_hash),
                 last_payload = COALESCE(:last_payload, last_payload),
                 last_response = :last_response,
                 last_error = NULL,
                 retry_count = 0,
                 last_synced_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $statement->execute([
            'remote_status' => $remoteStatus,
            'desired_action' => $desiredAction,
            'payload_hash' => $hash,
            'last_payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
        ]);
    }

    private function fail(int $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE proppit_ads
             SET sync_status = 'failed',
                 last_error = :error,
                 retry_count = retry_count + 1,
                 next_sync_at = DATE_ADD(NOW(), INTERVAL LEAST(60, retry_count + 1) MINUTE),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $statement->execute(['error' => $error, 'id' => $id]);
    }

    private function log(
        ?int $inmuebleId,
        string $action,
        string $method,
        string $endpoint,
        ?array $request,
        ?array $response,
        ?int $status,
        bool $success,
        ?string $error
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO proppit_logs
             (inmueble_id, action, method, endpoint, request_payload, response_payload, http_status, success, error_message)
             VALUES (:inmueble_id, :action, :method, :endpoint, :request_payload, :response_payload, :http_status, :success, :error_message)'
        );
        $statement->execute([
            'inmueble_id' => $inmuebleId,
            'action' => $action,
            'method' => $method,
            'endpoint' => $endpoint,
            'request_payload' => $request ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'response_payload' => $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'http_status' => $status,
            'success' => $success ? 1 : 0,
            'error_message' => $error,
        ]);
    }

    private function responseError(array $response): string
    {
        if (!empty($response['body']['error'])) {
            return (string) $response['body']['error'];
        }
        if (!empty($response['raw'])) {
            return (string) $response['raw'];
        }
        return $response['error'] ?: 'Error desconocido en Proppit.';
    }
}
