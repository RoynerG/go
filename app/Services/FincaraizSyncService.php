<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Models\InmuebleRepository;
use PDO;
use RuntimeException;
use Throwable;

final class FincaraizSyncService
{
    private PDO $pdo;
    private InmuebleRepository $inmuebles;
    private FincaraizPayloadBuilder $builder;
    private FincaraizClient $client;

    public function __construct()
    {
        $this->pdo = Database::pdo();
        $this->inmuebles = new InmuebleRepository();
        $this->builder = new FincaraizPayloadBuilder();
        $this->client = new FincaraizClient();
    }

    public function run(int $limit = 20): array
    {
        $this->resetStaleProcessing();
        $ads = $this->pendingAds($limit);
        return $this->runAds($ads);
    }

    public function runInmueble(int $inmuebleId): array
    {
        $ad = $this->adByInmueble($inmuebleId);
        if (!$ad) {
            return ['processed' => 0, 'synced' => 0, 'failed' => 0, 'inmueble_ids' => []];
        }

        return $this->runAds([$ad]);
    }

    private function runAds(array $ads): array
    {
        $result = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'queued' => 0, 'inmueble_ids' => []];

        foreach ($ads as $ad) {
            $result['processed']++;
            $result['inmueble_ids'][] = (int) $ad['inmueble_id'];
            try {
                $this->markProcessing((int) $ad['id']);
                $final = $this->syncOne($ad);
                if ($final) {
                    $result['synced']++;
                } else {
                    $result['queued']++;
                }
            } catch (Throwable $exception) {
                $this->fail((int) $ad['id'], $exception->getMessage());
                $this->log((int) $ad['inmueble_id'], 'sync_error', 'LOCAL', 'worker', null, null, null, false, $exception->getMessage());
                $result['failed']++;
            }
        }

        return $result;
    }

    private function syncOne(array $ad): bool
    {
        $inmueble = $this->inmuebles->findFull((int) $ad['inmueble_id']);
        if (!$inmueble) {
            throw new RuntimeException('Inmueble no encontrado.');
        }

        $action = (string) $ad['desired_action'];
        if ($action === 'verify') {
            return $this->verify($ad, $inmueble);
        }

        if (in_array($action, ['update', 'pause', 'activate'], true) && trim((string) ($ad['external_id'] ?? '')) === '') {
            if ($action === 'update') {
                $action = 'publish';
            } else {
                throw new RuntimeException('No hay listing_id confirmado para esa accion en Finca Raiz.');
            }
        }

        if ($action === 'pause') {
            $response = $this->client->changeStatus((string) $ad['external_id'], 'DISABLED', $this->clientId($ad));
            $this->log((int) $ad['inmueble_id'], 'pause_listing', 'PATCH', '/listing/status', [
                'listing_id' => $ad['external_id'],
                'status' => 'DISABLED',
            ], $response, $response['status'], $response['success'], $this->responseError($response));
            return $this->storeQueuedResult((int) $ad['id'], $response, 'pause');
        }

        if ($action === 'activate') {
            $response = $this->client->changeStatus((string) $ad['external_id'], 'ACTIVE', $this->clientId($ad));
            $this->log((int) $ad['inmueble_id'], 'activate_listing', 'PATCH', '/listing/status', [
                'listing_id' => $ad['external_id'],
                'status' => 'ACTIVE',
            ], $response, $response['status'], $response['success'], $this->responseError($response));
            return $this->storeQueuedResult((int) $ad['id'], $response, 'activate');
        }

        $payload = $this->builder->build($inmueble, $ad);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (($ad['remote_status'] ?? '') === 'active' && ($ad['payload_hash'] ?? '') === $hash && $action !== 'update') {
            $this->markSynced((int) $ad['id'], 'active', $ad['external_id'] ?? null, $ad['fr_property_id'] ?? null, $ad['external_url'] ?? null, $payload, [
                'success' => true,
                'status' => 204,
                'body' => ['skipped' => true],
            ], $hash);
            return true;
        }

        if ($action === 'update' && !empty($ad['external_id'])) {
            $response = $this->client->updateListing((string) $ad['external_id'], $payload);
            $this->log((int) $ad['inmueble_id'], 'update_listing', 'PATCH', '/listing', $payload, $response, $response['status'], $response['success'], $this->responseError($response));
        } else {
            $response = $this->client->createListing($payload);
            $this->log((int) $ad['inmueble_id'], 'create_listing', 'POST', '/listing', $payload, $response, $response['status'], $response['success'], $this->responseError($response));
        }

        if (!$response['success']) {
            throw new RuntimeException($this->responseError($response) ?: 'Finca Raiz rechazo la solicitud.');
        }

        $taskId = $this->taskId($response['body'] ?? []);
        if (!$taskId) {
            $listingId = $this->listingId($response['body'] ?? []) ?: ($ad['external_id'] ?? null);
            $frPropertyId = $this->frPropertyId($response['body'] ?? []) ?: ($ad['fr_property_id'] ?? null);
            $this->markSynced((int) $ad['id'], 'active', $listingId, $frPropertyId, $this->publicUrl($frPropertyId), $payload, $response, $hash);
            return true;
        }

        $this->markVerificationPending((int) $ad['id'], $taskId, $action, $payload, $response, $hash);
        return false;
    }

    private function verify(array $ad, array $inmueble): bool
    {
        $taskId = trim((string) ($ad['task_id'] ?? ''));
        if ($taskId === '') {
            throw new RuntimeException('No hay task_id pendiente para verificar.');
        }

        $response = $this->client->getTask($taskId);
        $this->log((int) $ad['inmueble_id'], 'verify_task', 'GET', '/task/' . $taskId, null, $response, $response['status'], $response['success'], $this->responseError($response));
        if (!$response['success']) {
            throw new RuntimeException($this->responseError($response) ?: 'No se pudo verificar la tarea en Finca Raiz.');
        }

        $body = $response['body'] ?? [];
        $task = is_array($body['task'] ?? null) ? $body['task'] : $body;
        $content = $this->taskContent($task, (string) $inmueble['reference_id']);
        $status = strtoupper((string) (($content['status'] ?? null) ?: ($task['status'] ?? '')));

        if (in_array($status, ['ERROR', 'FAILED'], true)) {
            throw new RuntimeException($this->taskError($task, $content));
        }

        $previousAction = (string) (($this->decode($ad['last_response'])['action'] ?? '') ?: 'publish');
        if (!in_array($status, ['COMPLETED', 'FORWARDED', 'SUCCESS'], true)) {
            $response['action'] = $previousAction;
            $this->markStillPending((int) $ad['id'], $response);
            return false;
        }

        $listingId = $this->listingId($content) ?: $this->listingId($body) ?: ($ad['external_id'] ?? null);
        $frPropertyId = $this->frPropertyId($content) ?: $this->frPropertyId($body) ?: ($ad['fr_property_id'] ?? null);
        $remoteStatus = in_array($previousAction, ['pause'], true) ? 'disabled' : 'active';
        $this->markSynced(
            (int) $ad['id'],
            $remoteStatus,
            $listingId,
            $frPropertyId,
            $remoteStatus === 'active' ? $this->publicUrl($frPropertyId) : null,
            $this->decode($ad['last_payload']),
            $response,
            $ad['payload_hash'] ?? null
        );

        return true;
    }

    private function pendingAds(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM fincaraiz_ads
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
            "UPDATE fincaraiz_ads
             SET sync_status = 'pending', updated_at = NOW()
             WHERE sync_status = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
    }

    private function adByInmueble(int $inmuebleId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM fincaraiz_ads WHERE inmueble_id = :inmueble_id LIMIT 1');
        $statement->execute(['inmueble_id' => $inmuebleId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function markProcessing(int $id): void
    {
        $this->pdo->prepare("UPDATE fincaraiz_ads SET sync_status = 'processing', updated_at = NOW() WHERE id = :id")->execute(['id' => $id]);
    }

    private function markVerificationPending(int $id, string $taskId, string $action, array $payload, array $response, string $hash): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE fincaraiz_ads
             SET desired_action = 'verify',
                 remote_status = 'pending',
                 sync_status = 'pending',
                 task_id = :task_id,
                 payload_hash = :payload_hash,
                 last_payload = :last_payload,
                 last_response = :last_response,
                 last_error = NULL,
                 retry_count = 0,
                 next_sync_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $response['action'] = $action;
        $statement->execute([
            'task_id' => $taskId,
            'payload_hash' => $hash,
            'last_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
        ]);
    }

    private function markStillPending(int $id, array $response): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE fincaraiz_ads
             SET sync_status = 'pending',
                 last_response = :last_response,
                 next_sync_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $statement->execute([
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
        ]);
    }

    private function markSynced(int $id, string $remoteStatus, ?string $listingId, ?string $frPropertyId, ?string $externalUrl, ?array $payload, array $response, ?string $hash = null): void
    {
        $desiredAction = $remoteStatus === 'disabled' ? 'pause' : 'update';
        $statement = $this->pdo->prepare(
            "UPDATE fincaraiz_ads
             SET remote_status = :remote_status,
                 sync_status = 'synced',
                 desired_action = :desired_action,
                 external_id = COALESCE(:external_id, external_id),
                 fr_property_id = COALESCE(:fr_property_id, fr_property_id),
                 external_url = :external_url,
                 task_id = NULL,
                 payload_hash = COALESCE(:payload_hash, payload_hash),
                 last_payload = COALESCE(:last_payload, last_payload),
                 last_response = :last_response,
                 last_error = NULL,
                 retry_count = 0,
                 last_synced_at = NOW(),
                 next_sync_at = NULL,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $statement->execute([
            'remote_status' => $remoteStatus,
            'desired_action' => $desiredAction,
            'external_id' => $listingId,
            'fr_property_id' => $frPropertyId,
            'external_url' => $externalUrl,
            'payload_hash' => $hash,
            'last_payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'last_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $id,
        ]);
    }

    private function fail(int $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE fincaraiz_ads
             SET sync_status = 'failed',
                 remote_status = 'error',
                 last_error = :error,
                 retry_count = retry_count + 1,
                 next_sync_at = DATE_ADD(NOW(), INTERVAL LEAST(60, retry_count + 1) MINUTE),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $statement->execute(['error' => $error, 'id' => $id]);
    }

    private function storeQueuedResult(int $id, array $response, string $action): bool
    {
        if (!$response['success']) {
            throw new RuntimeException($this->responseError($response) ?: 'Finca Raiz rechazo la solicitud.');
        }

        $taskId = $this->taskId($response['body'] ?? []);
        if (!$taskId) {
            $this->markSynced($id, $action === 'pause' ? 'disabled' : 'active', null, null, null, null, $response);
            return true;
        }

        $this->markVerificationPending($id, $taskId, $action, [], $response, hash('sha256', $taskId));
        return false;
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
            'INSERT INTO fincaraiz_logs
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

    private function taskId(array $data): ?string
    {
        $id = $data['task']['id'] ?? $data['task_id'] ?? $data['id'] ?? null;
        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }

    private function taskContent(array $task, string $referenceId): array
    {
        $contents = $task['content'] ?? [];
        if (!is_array($contents)) {
            return [];
        }
        foreach ($contents as $content) {
            if (is_array($content) && (string) ($content['external_code'] ?? '') === $referenceId) {
                return $content;
            }
        }

        $first = reset($contents);
        return is_array($first) ? $first : [];
    }

    private function listingId(array $data): ?string
    {
        foreach (['listing_id', 'listingId', 'id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function frPropertyId(array $data): ?string
    {
        foreach (['fr_property_id', 'frPropertyId'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function clientId(array $ad): string
    {
        return trim((string) ($ad['client_id'] ?? Env::get('FINCARAIZ_CLIENT_ID', '7750b42d-a577-11f1-8d89-06ecc7fea243')));
    }

    private function publicUrl(?string $frPropertyId): ?string
    {
        $id = trim((string) $frPropertyId);
        if ($id === '') {
            return null;
        }

        return rtrim((string) Env::get('FINCARAIZ_PAGE_URL', 'https://www.fincaraiz.com.co'), '/') . '/detalle/' . rawurlencode($id);
    }

    private function responseError(array $response): ?string
    {
        $body = $response['body'] ?? [];
        if (is_array($body)) {
            $message = $body['detail'] ?? $body['error'] ?? $body['message'] ?? null;
            if (is_scalar($message) && trim((string) $message) !== '') {
                return (string) $message;
            }
        }

        return $response['error'] ?: ($response['raw'] ?: null);
    }

    private function taskError(array $task, array $content): string
    {
        $messages = $content['messages'] ?? $task['messages'] ?? null;
        if (is_array($messages) && $messages !== []) {
            return json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return 'La tarea de Finca Raiz termino con error.';
    }

    private function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
