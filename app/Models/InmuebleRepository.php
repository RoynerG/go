<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class InmuebleRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::pdo();
        $this->ensureOptionalSchema();
    }

    public function upsertFromPayload(array $payload): int
    {
        $referenceId = $this->referenceId($payload);
        $existing = $this->findByReference($referenceId);
        $data = $this->normalize($payload, $referenceId);
        if ($existing) {
            $data['is_exclusive'] = (int) ($existing['is_exclusive'] ?? 0);
        }

        $this->pdo->beginTransaction();
        try {
            if ($existing) {
                $this->update((int) $existing['id'], $data);
                $id = (int) $existing['id'];
            } else {
                $id = $this->insert($data);
            }

            $this->replaceLocation($id, $payload);
            $this->replaceContacts($id, $payload);
            $this->replaceFeatures($id, $payload);
            $this->replaceMedia($id, $payload);
            $this->upsertProppitAd($id, $payload, $existing ? 'update' : 'publish', (int) $data['publicar_proppit'] === 1);
            $this->upsertFincaraizAd($id, $existing ? 'update' : 'publish', (int) $data['publicar_fincaraiz'] === 1);

            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markForDelete(string $referenceId, ?string $reason = null): bool
    {
        $inmueble = $this->findByReference($referenceId);
        if (!$inmueble) {
            return false;
        }

        $statement = $this->pdo->prepare("UPDATE inmuebles SET estado = 'no_disponible', publicar_proppit = 0, publicar_fincaraiz = 0, updated_at = NOW() WHERE id = :id");
        $statement->execute(['id' => $inmueble['id']]);

        $statement = $this->pdo->prepare(
            "UPDATE proppit_ads
             SET desired_action = 'delete', sync_status = 'pending', last_error = :reason, updated_at = NOW()
             WHERE inmueble_id = :inmueble_id"
        );
        $statement->execute([
            'inmueble_id' => $inmueble['id'],
            'reason' => $reason,
        ]);

        $this->ensureFincaraizActionAd((int) $inmueble['id'], 'pause', $reason);

        return true;
    }

    public function setPublishIntent(int $inmuebleId, bool $publish): bool
    {
        return $this->queueProppitAction($inmuebleId, $publish ? 'publish' : 'delete');
    }

    public function queueProppitAction(int $inmuebleId, string $action): bool
    {
        $inmueble = $this->findFull($inmuebleId);
        if (!$inmueble || !in_array($action, ['publish', 'update', 'delete'], true)) {
            return false;
        }

        if ($action !== 'delete' && $inmueble['estado'] === 'no_disponible') {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE inmuebles SET publicar_proppit = :publicar, updated_at = NOW() WHERE id = :id'
            )->execute([
                'publicar' => $action === 'delete' ? 0 : 1,
                'id' => $inmuebleId,
            ]);

            $this->ensureActionAd($inmuebleId, $action);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function queueFincaraizAction(int $inmuebleId, string $action): bool
    {
        $inmueble = $this->findFull($inmuebleId);
        if (!$inmueble || !in_array($action, ['publish', 'update', 'pause', 'activate', 'verify'], true)) {
            return false;
        }

        $fincaraizAd = $inmueble['fincaraiz'] ?? [];
        if (
            $action === 'publish'
            && trim((string) ($fincaraizAd['external_id'] ?? '')) !== ''
            && (string) ($fincaraizAd['remote_status'] ?? '') === 'disabled'
        ) {
            $action = 'activate';
        }

        if (!in_array($action, ['pause', 'verify'], true) && $inmueble['estado'] === 'no_disponible') {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            if ($action !== 'verify') {
                $this->pdo->prepare(
                    'UPDATE inmuebles SET publicar_fincaraiz = :publicar, updated_at = NOW() WHERE id = :id'
                )->execute([
                    'publicar' => $action === 'pause' ? 0 : 1,
                    'id' => $inmuebleId,
                ]);
            }

            $this->ensureFincaraizActionAd($inmuebleId, $action);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function toggleProppitFlag(int $inmuebleId, string $flag): bool
    {
        if (!in_array($flag, ['is_boosted', 'is_exclusive'], true)) {
            return false;
        }

        $inmueble = $this->findFull($inmuebleId);
        if (!$inmueble) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE inmuebles SET {$flag} = IF({$flag} = 1, 0, 1), updated_at = NOW() WHERE id = :id")
                ->execute(['id' => $inmuebleId]);
            $this->ensureActionAd($inmuebleId, ($inmueble['proppit']['remote_status'] ?? null) === 'published' ? 'update' : 'publish');
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function refreshProppitQueueFromInmuebles(): array
    {
        $publicables = $this->enablePublishByDefault();
        $boosted = $this->enableBoostedByDefault();
        $rows = $this->pdo->query(
            "SELECT i.id, i.estado, i.publicar_proppit, i.updated_at AS inmueble_updated_at,
                    p.desired_action, p.remote_status, p.sync_status, p.last_synced_at
             FROM inmuebles i
             LEFT JOIN proppit_ads p ON p.inmueble_id = i.id"
        )->fetchAll();

        $stats = [
            'publish' => 0,
            'update' => 0,
            'delete' => 0,
            'skipped' => 0,
            'public_enabled' => $publicables,
            'boosted_enabled' => $boosted['enabled'],
            'boosted_disabled' => $boosted['disabled'],
        ];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $isPublic = (int) ($row['publicar_proppit'] ?? 0) === 1;
            $isAvailable = (string) ($row['estado'] ?? '') !== 'no_disponible';
            $remoteStatus = (string) ($row['remote_status'] ?? '');
            $syncStatus = (string) ($row['sync_status'] ?? '');
            $desiredAction = (string) ($row['desired_action'] ?? '');

            if (!$isPublic || !$isAvailable) {
                if ($remoteStatus === 'published' || $desiredAction !== 'delete') {
                    $this->ensureActionAd($id, 'delete');
                    $stats['delete']++;
                    continue;
                }
                if (in_array($remoteStatus, ['deleted', 'not_sent', ''], true) && $desiredAction === 'delete' && $syncStatus !== 'synced') {
                    $this->markDeleteAlreadySynced($id);
                }
                $stats['skipped']++;
                continue;
            }

            if ($syncStatus === 'processing') {
                $stats['skipped']++;
                continue;
            }

            if ($remoteStatus === '' || in_array($remoteStatus, ['not_sent', 'deleted', 'error'], true)) {
                if ($desiredAction !== 'publish' || $syncStatus !== 'pending') {
                    $this->ensureActionAd($id, 'publish');
                    $stats['publish']++;
                    continue;
                }
                $stats['skipped']++;
                continue;
            }

            if ($remoteStatus === 'published') {
                $inmuebleUpdated = strtotime((string) ($row['inmueble_updated_at'] ?? '')) ?: 0;
                $lastSynced = strtotime((string) ($row['last_synced_at'] ?? '')) ?: 0;
                if ($lastSynced === 0 || $inmuebleUpdated > $lastSynced || $syncStatus === 'failed') {
                    if ($desiredAction !== 'update' || $syncStatus !== 'pending') {
                        $this->ensureActionAd($id, 'update');
                        $stats['update']++;
                        continue;
                    }
                }
            }

            $stats['skipped']++;
        }

        return $stats;
    }

    public function refreshFincaraizQueueFromInmuebles(): array
    {
        $publicables = $this->enableFincaraizPublishByDefault();
        $rows = $this->pdo->query(
            "SELECT i.id, i.estado, i.publicar_fincaraiz, i.updated_at AS inmueble_updated_at,
                    f.desired_action, f.remote_status, f.sync_status, f.last_synced_at, f.external_id
             FROM inmuebles i
             LEFT JOIN fincaraiz_ads f ON f.inmueble_id = i.id"
        )->fetchAll();

        $stats = [
            'publish' => 0,
            'update' => 0,
            'pause' => 0,
            'activate' => 0,
            'verify' => 0,
            'skipped' => 0,
            'public_enabled' => $publicables,
        ];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $isPublic = (int) ($row['publicar_fincaraiz'] ?? 0) === 1;
            $isAvailable = (string) ($row['estado'] ?? '') !== 'no_disponible';
            $remoteStatus = (string) ($row['remote_status'] ?? '');
            $syncStatus = (string) ($row['sync_status'] ?? '');
            $desiredAction = (string) ($row['desired_action'] ?? '');
            $hasExternalId = trim((string) ($row['external_id'] ?? '')) !== '';

            if ($desiredAction === 'verify' && $syncStatus === 'pending') {
                $stats['verify']++;
                continue;
            }

            if (!$isPublic || !$isAvailable) {
                if ($hasExternalId && !in_array($remoteStatus, ['disabled', 'deleted'], true)) {
                    $this->ensureFincaraizActionAd($id, 'pause');
                    $stats['pause']++;
                    continue;
                }
                $stats['skipped']++;
                continue;
            }

            if ($syncStatus === 'processing') {
                $stats['skipped']++;
                continue;
            }

            if ($hasExternalId && $remoteStatus === 'disabled') {
                if ($desiredAction !== 'activate' || $syncStatus !== 'pending') {
                    $this->ensureFincaraizActionAd($id, 'activate');
                    $stats['activate']++;
                    continue;
                }
                $stats['skipped']++;
                continue;
            }

            if (!$hasExternalId || in_array($remoteStatus, ['', 'not_sent', 'deleted', 'error'], true)) {
                if ($desiredAction !== 'publish' || $syncStatus !== 'pending') {
                    $this->ensureFincaraizActionAd($id, 'publish');
                    $stats['publish']++;
                    continue;
                }
                $stats['skipped']++;
                continue;
            }

            $inmuebleUpdated = strtotime((string) ($row['inmueble_updated_at'] ?? '')) ?: 0;
            $lastSynced = strtotime((string) ($row['last_synced_at'] ?? '')) ?: 0;
            if ($remoteStatus === 'active' && ($lastSynced === 0 || $inmuebleUpdated > $lastSynced || $syncStatus === 'failed')) {
                if ($desiredAction !== 'update' || $syncStatus !== 'pending') {
                    $this->ensureFincaraizActionAd($id, 'update');
                    $stats['update']++;
                    continue;
                }
            }

            $stats['skipped']++;
        }

        return $stats;
    }

    private function enablePublishByDefault(): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE inmuebles
             SET publicar_proppit = 1, updated_at = NOW()
             WHERE estado <> 'no_disponible' AND publicar_proppit = 0"
        );
        $statement->execute();
        return $statement->rowCount();
    }

    private function enableFincaraizPublishByDefault(): int
    {
        if (!\App\Core\Env::bool('FINCARAIZ_AUTO_MARK', false)) {
            return 0;
        }

        $quota = $this->fincaraizQuota();
        if ($quota <= 0) {
            $statement = $this->pdo->prepare(
                "UPDATE inmuebles
                 SET publicar_fincaraiz = 0, updated_at = NOW()
                 WHERE publicar_fincaraiz = 1"
            );
            $statement->execute();
            return $statement->rowCount();
        }

        $idsStatement = $this->pdo->prepare(
            "SELECT i.id
             FROM inmuebles i
             LEFT JOIN fincaraiz_ads f ON f.inmueble_id = i.id
             WHERE i.estado <> 'no_disponible'
             ORDER BY
                (i.publicar_fincaraiz = 1) DESC,
                (f.remote_status = 'active') DESC,
                i.is_boosted DESC,
                i.updated_at DESC,
                i.id DESC
             LIMIT :quota"
        );
        $idsStatement->bindValue(':quota', $quota, PDO::PARAM_INT);
        $idsStatement->execute();
        $ids = array_map('intval', $idsStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (!$ids) {
            $statement = $this->pdo->prepare(
                "UPDATE inmuebles
                 SET publicar_fincaraiz = 0, updated_at = NOW()
                 WHERE publicar_fincaraiz = 1"
            );
            $statement->execute();
            return $statement->rowCount();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $enable = $this->pdo->prepare(
            "UPDATE inmuebles
             SET publicar_fincaraiz = 1, updated_at = NOW()
             WHERE id IN ({$placeholders}) AND publicar_fincaraiz = 0"
        );
        $enable->execute($ids);
        $changed = $enable->rowCount();

        $disable = $this->pdo->prepare(
            "UPDATE inmuebles
             SET publicar_fincaraiz = 0, updated_at = NOW()
             WHERE estado <> 'no_disponible'
               AND publicar_fincaraiz = 1
               AND id NOT IN ({$placeholders})"
        );
        $disable->execute($ids);

        return $changed + $disable->rowCount();
    }

    private function fincaraizQuota(): int
    {
        return max(0, (int) (\App\Core\Env::get('FINCARAIZ_QUOTA', '50') ?: 50));
    }

    private function enableBoostedByDefault(): array
    {
        $statement = $this->pdo->prepare(
            "UPDATE inmuebles
             SET is_boosted = 1, updated_at = NOW()
             WHERE estado <> 'no_disponible' AND is_boosted = 0"
        );
        $statement->execute();
        return ['enabled' => $statement->rowCount(), 'disabled' => 0];
    }

    private function markDeleteAlreadySynced(int $inmuebleId): void
    {
        $this->pdo->prepare(
            "UPDATE proppit_ads
             SET remote_status = 'deleted',
                 sync_status = 'synced',
                 last_error = NULL,
                 last_synced_at = COALESCE(last_synced_at, NOW()),
                 updated_at = NOW()
             WHERE inmueble_id = :inmueble_id AND desired_action = 'delete'"
        )->execute(['inmueble_id' => $inmuebleId]);
    }

    public function findByReference(string $referenceId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM inmuebles WHERE reference_id = :reference_id LIMIT 1');
        $statement->execute(['reference_id' => $referenceId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findFull(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM inmuebles WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $inmueble = $statement->fetch();
        if (!$inmueble) {
            return null;
        }

        $inmueble['ubicacion'] = $this->one('SELECT * FROM inmueble_ubicaciones WHERE inmueble_id = :id', $id);
        $inmueble['contactos'] = $this->many('SELECT * FROM inmueble_contactos WHERE inmueble_id = :id', $id);
        $inmueble['caracteristicas'] = $this->many('SELECT * FROM inmueble_caracteristicas WHERE inmueble_id = :id ORDER BY tipo, valor', $id);
        $inmueble['multimedia'] = $this->many('SELECT * FROM inmueble_multimedia WHERE inmueble_id = :id ORDER BY orden, id', $id);
        $inmueble['proppit'] = $this->one('SELECT * FROM proppit_ads WHERE inmueble_id = :id', $id);
        $inmueble['fincaraiz'] = $this->one('SELECT * FROM fincaraiz_ads WHERE inmueble_id = :id', $id);
        $inmueble['logs'] = $this->many('SELECT * FROM proppit_logs WHERE inmueble_id = :id ORDER BY created_at DESC LIMIT 20', $id);
        $inmueble['fincaraiz_logs'] = $this->many('SELECT * FROM fincaraiz_logs WHERE inmueble_id = :id ORDER BY created_at DESC LIMIT 20', $id);

        return $inmueble;
    }

    public function mediaById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.*, i.reference_id
             FROM inmueble_multimedia m
             INNER JOIN inmuebles i ON i.id = m.inmueble_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function panelRows(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        [$where, $params] = $this->panelWhere($filters);
        $page = max(1, $page);
        $perPage = min(48, max(6, $perPage));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT i.id, i.reference_id, i.titulo, i.estado, i.precio_venta, i.precio_arriendo,
                    i.tipo_inmueble, i.categoria, i.destinacion,
                    i.habitaciones, i.banos, i.area_construida, i.area_privada, i.parqueaderos,
                    i.estrato, i.publicar_proppit, i.publicar_fincaraiz, i.is_boosted, i.is_exclusive,
                    u.direccion, u.barrio,
                    (SELECT m.url FROM inmueble_multimedia m WHERE m.inmueble_id = i.id ORDER BY FIELD(m.tipo, 'portada', 'galeria'), m.orden, m.id LIMIT 1) AS portada_url,
                    p.desired_action, p.remote_status, p.sync_status, p.last_error, p.last_synced_at, p.updated_at
                    , f.desired_action AS fincaraiz_desired_action, f.remote_status AS fincaraiz_remote_status,
                    f.sync_status AS fincaraiz_sync_status, f.external_id AS fincaraiz_external_id,
                    f.external_url AS fincaraiz_external_url, f.last_error AS fincaraiz_last_error,
                    f.last_synced_at AS fincaraiz_last_synced_at
             FROM inmuebles i
             LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id = i.id
             LEFT JOIN proppit_ads p ON p.inmueble_id = i.id
             LEFT JOIN fincaraiz_ads f ON f.inmueble_id = i.id
             " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
             ORDER BY i.updated_at DESC
             LIMIT :limit OFFSET :offset";

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        foreach ($rows as &$row) {
            $row['contactos'] = $this->many('SELECT * FROM inmueble_contactos WHERE inmueble_id = :id', (int) $row['id']);
            $row['caracteristicas'] = $this->many('SELECT * FROM inmueble_caracteristicas WHERE inmueble_id = :id ORDER BY tipo, valor', (int) $row['id']);
            $row['multimedia'] = $this->many('SELECT * FROM inmueble_multimedia WHERE inmueble_id = :id ORDER BY orden, id', (int) $row['id']);
        }

        return $rows;
    }

    public function panelCount(array $filters = []): int
    {
        [$where, $params] = $this->panelWhere($filters);
        $sql = "SELECT COUNT(*)
             FROM inmuebles i
             LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id = i.id
             LEFT JOIN proppit_ads p ON p.inmueble_id = i.id
             LEFT JOIN fincaraiz_ads f ON f.inmueble_id = i.id
             " . ($where ? 'WHERE ' . implode(' AND ', $where) : '');

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function filterOptions(): array
    {
        return [
            'tipos' => $this->columnOptions('SELECT DISTINCT tipo_inmueble FROM inmuebles WHERE tipo_inmueble IS NOT NULL AND tipo_inmueble <> "" ORDER BY tipo_inmueble'),
            'categorias' => $this->columnOptions('SELECT DISTINCT categoria FROM inmuebles WHERE categoria IS NOT NULL AND categoria <> "" ORDER BY categoria'),
            'destinaciones' => $this->columnOptions('SELECT DISTINCT destinacion FROM inmuebles WHERE destinacion IS NOT NULL AND destinacion <> "" ORDER BY destinacion'),
            'barrios' => $this->columnOptions('SELECT DISTINCT barrio FROM inmueble_ubicaciones WHERE barrio IS NOT NULL AND barrio <> "" ORDER BY barrio'),
            'proppit_estados' => $this->columnOptions('SELECT DISTINCT remote_status FROM proppit_ads WHERE remote_status IS NOT NULL AND remote_status <> "" ORDER BY remote_status'),
            'fincaraiz_estados' => $this->columnOptions('SELECT DISTINCT remote_status FROM fincaraiz_ads WHERE remote_status IS NOT NULL AND remote_status <> "" ORDER BY remote_status'),
        ];
    }

    public function operationSummary(): array
    {
        $proppitQueue = $this->pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(sync_status = 'pending') AS pending,
                SUM(sync_status = 'processing') AS processing,
                SUM(sync_status = 'synced') AS synced,
                SUM(sync_status = 'failed') AS failed,
                SUM(desired_action = 'publish') AS publish_actions,
                SUM(desired_action = 'update') AS update_actions,
                SUM(desired_action = 'delete') AS delete_actions,
                SUM(remote_status = 'published') AS published,
                SUM(remote_status = 'deleted') AS deleted,
                SUM(remote_status = 'error') AS remote_errors,
                MAX(last_synced_at) AS last_synced_at,
                MAX(updated_at) AS queue_updated_at
             FROM proppit_ads"
        )->fetch() ?: [];

        $fincaraizQueue = $this->pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(sync_status = 'pending') AS pending,
                SUM(sync_status = 'processing') AS processing,
                SUM(sync_status = 'synced') AS synced,
                SUM(sync_status = 'failed') AS failed,
                SUM(desired_action = 'publish') AS publish_actions,
                SUM(desired_action = 'update') AS update_actions,
                SUM(desired_action = 'pause') AS pause_actions,
                SUM(desired_action = 'activate') AS activate_actions,
                SUM(desired_action = 'verify') AS verify_actions,
                SUM(remote_status = 'active') AS published,
                SUM(remote_status = 'disabled') AS paused,
                SUM(remote_status = 'error') AS remote_errors,
                MAX(last_synced_at) AS last_synced_at,
                MAX(updated_at) AS queue_updated_at
             FROM fincaraiz_ads"
        )->fetch() ?: [];
        $fincaraizQueue['quota'] = $this->fincaraizQuota();
        $fincaraizQueue['quota_used'] = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM inmuebles WHERE estado <> 'no_disponible' AND publicar_fincaraiz = 1")
            ->fetchColumn();

        $proppitLogs = $this->pdo->query(
            "SELECT l.id, l.inmueble_id, i.reference_id, i.titulo, l.action, l.method, l.http_status,
                    l.success, l.error_message, l.created_at
             FROM proppit_logs l
             LEFT JOIN inmuebles i ON i.id = l.inmueble_id
             ORDER BY l.created_at DESC
             LIMIT 12"
        )->fetchAll();

        $fincaraizLogs = $this->pdo->query(
            "SELECT l.id, l.inmueble_id, i.reference_id, i.titulo, l.action, l.method, l.http_status,
                    l.success, l.error_message, l.created_at
             FROM fincaraiz_logs l
             LEFT JOIN inmuebles i ON i.id = l.inmueble_id
             ORDER BY l.created_at DESC
             LIMIT 12"
        )->fetchAll();

        return [
            'queue' => $proppitQueue,
            'logs' => $proppitLogs,
            'queue_items' => $this->portalQueueItems(30),
            'state_summary' => $this->portalStateSummary(8),
            'portals' => [
                'proppit' => ['queue' => $proppitQueue, 'logs' => $proppitLogs],
                'fincaraiz' => ['queue' => $fincaraizQueue, 'logs' => $fincaraizLogs],
            ],
        ];
    }

    public function portalQueueItems(int $limit = 30): array
    {
        $limit = min(80, max(1, $limit));
        $sql = "
            SELECT * FROM (
                SELECT
                    'proppit' AS portal,
                    i.id,
                    i.reference_id,
                    i.titulo,
                    i.estado,
                    i.precio_venta,
                    i.precio_arriendo,
                    i.habitaciones,
                    i.banos,
                    i.area_construida,
                    i.area_privada,
                    u.barrio,
                    u.direccion,
                    (SELECT m.url FROM inmueble_multimedia m WHERE m.inmueble_id = i.id ORDER BY FIELD(m.tipo, 'portada', 'galeria'), m.orden, m.id LIMIT 1) AS portada_url,
                    p.desired_action,
                    p.remote_status,
                    p.sync_status,
                    p.last_error,
                    p.last_synced_at,
                    p.updated_at,
                    CASE p.sync_status WHEN 'failed' THEN 1 WHEN 'pending' THEN 2 WHEN 'processing' THEN 3 ELSE 4 END AS sort_weight
                FROM proppit_ads p
                INNER JOIN inmuebles i ON i.id = p.inmueble_id
                LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id = i.id
                WHERE p.sync_status IN ('pending','processing','failed')

                UNION ALL

                SELECT
                    'fincaraiz' AS portal,
                    i.id,
                    i.reference_id,
                    i.titulo,
                    i.estado,
                    i.precio_venta,
                    i.precio_arriendo,
                    i.habitaciones,
                    i.banos,
                    i.area_construida,
                    i.area_privada,
                    u.barrio,
                    u.direccion,
                    (SELECT m.url FROM inmueble_multimedia m WHERE m.inmueble_id = i.id ORDER BY FIELD(m.tipo, 'portada', 'galeria'), m.orden, m.id LIMIT 1) AS portada_url,
                    f.desired_action,
                    f.remote_status,
                    f.sync_status,
                    f.last_error,
                    f.last_synced_at,
                    f.updated_at,
                    CASE f.sync_status WHEN 'failed' THEN 1 WHEN 'pending' THEN 2 WHEN 'processing' THEN 3 ELSE 4 END AS sort_weight
                FROM fincaraiz_ads f
                INNER JOIN inmuebles i ON i.id = f.inmueble_id
                LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id = i.id
                WHERE f.sync_status IN ('pending','processing','failed')
            ) q
            ORDER BY sort_weight ASC, updated_at DESC
            LIMIT :limit";

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $this->decoratePortalRows($statement->fetchAll() ?: []);
    }

    public function portalStateSummary(int $limit = 8): array
    {
        $limit = min(30, max(1, $limit));

        return [
            'publicados' => $this->portalStateRows(
                "p.remote_status = 'published'",
                "f.remote_status = 'active'",
                $limit
            ),
            'eliminados' => $this->portalStateRows(
                "p.remote_status = 'deleted' OR p.desired_action = 'delete'",
                "f.remote_status IN ('disabled','deleted') OR f.desired_action = 'pause'",
                $limit
            ),
            'errores' => $this->portalStateRows(
                "p.remote_status = 'error' OR p.sync_status = 'failed' OR COALESCE(p.last_error, '') <> ''",
                "f.remote_status = 'error' OR f.sync_status = 'failed' OR COALESCE(f.last_error, '') <> ''",
                $limit
            ),
        ];
    }

    private function portalStateRows(string $proppitCondition, string $fincaraizCondition, int $limit): array
    {
        $sql = "
            SELECT * FROM (
                SELECT
                    'proppit' AS portal,
                    i.id,
                    i.reference_id,
                    i.titulo,
                    i.estado,
                    i.precio_venta,
                    i.precio_arriendo,
                    i.habitaciones,
                    i.banos,
                    i.area_construida,
                    i.area_privada,
                    u.barrio,
                    u.direccion,
                    (SELECT m.url FROM inmueble_multimedia m WHERE m.inmueble_id = i.id ORDER BY FIELD(m.tipo, 'portada', 'galeria'), m.orden, m.id LIMIT 1) AS portada_url,
                    p.desired_action,
                    p.remote_status,
                    p.sync_status,
                    p.last_error,
                    p.last_synced_at,
                    p.updated_at,
                    1 AS sort_weight
                FROM proppit_ads p
                INNER JOIN inmuebles i ON i.id = p.inmueble_id
                LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id = i.id
                WHERE {$proppitCondition}

                UNION ALL

                SELECT
                    'fincaraiz' AS portal,
                    i.id,
                    i.reference_id,
                    i.titulo,
                    i.estado,
                    i.precio_venta,
                    i.precio_arriendo,
                    i.habitaciones,
                    i.banos,
                    i.area_construida,
                    i.area_privada,
                    u.barrio,
                    u.direccion,
                    (SELECT m.url FROM inmueble_multimedia m WHERE m.inmueble_id = i.id ORDER BY FIELD(m.tipo, 'portada', 'galeria'), m.orden, m.id LIMIT 1) AS portada_url,
                    f.desired_action,
                    f.remote_status,
                    f.sync_status,
                    f.last_error,
                    f.last_synced_at,
                    f.updated_at,
                    1 AS sort_weight
                FROM fincaraiz_ads f
                INNER JOIN inmuebles i ON i.id = f.inmueble_id
                LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id = i.id
                WHERE {$fincaraizCondition}
            ) q
            ORDER BY updated_at DESC
            LIMIT :limit";

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $this->decoratePortalRows($statement->fetchAll() ?: []);
    }

    private function decoratePortalRows(array $rows): array
    {
        $portalLabels = ['proppit' => 'Proppit', 'fincaraiz' => 'Finca Raiz'];
        $actionLabels = [
            'publish' => 'Publicar',
            'update' => 'Actualizar',
            'delete' => 'Despublicar',
            'pause' => 'Despublicar',
            'activate' => 'Activar',
            'verify' => 'Verificar',
        ];

        foreach ($rows as &$row) {
            $portal = (string) ($row['portal'] ?? '');
            $action = (string) ($row['desired_action'] ?? '');
            $row['portal_label'] = $portalLabels[$portal] ?? ucfirst($portal);
            $row['action_label'] = $actionLabels[$action] ?? ($action !== '' ? ucfirst($action) : 'Sin accion');
            $row['price_label'] = $this->moneyLabel((float) ($row['precio_venta'] ?: $row['precio_arriendo'] ?: 0));
            $row['specs_label'] = (int) ($row['habitaciones'] ?? 0) . ' Hab · '
                . (int) ($row['banos'] ?? 0) . ' Ba · '
                . number_format((float) ($row['area_construida'] ?: $row['area_privada'] ?: 0), 0, ',', '.') . ' m2';
        }

        return $rows;
    }

    private function moneyLabel(float $value): string
    {
        return $value > 0 ? '$' . number_format($value, 0, ',', '.') : 'Sin precio';
    }

    private function normalize(array $payload, string $referenceId): array
    {
        return [
            'wp_post_id' => $this->intOrNull($payload['wp_post_id'] ?? $payload['id_inmueble'] ?? null),
            'reference_id' => $referenceId,
            'titulo' => trim((string) ($payload['titulo'] ?? $payload['post_title'] ?? 'Inmueble sin titulo')),
            'texto_corto' => $this->strOrNull($payload['texto_corto'] ?? $payload['texto-corto'] ?? null),
            'descripcion' => trim((string) ($payload['descripcion'] ?? $payload['post_content'] ?? 'Sin descripcion')),
            'tipo_inmueble' => trim((string) ($payload['tipo_inmueble'] ?? '')),
            'categoria' => $this->strOrNull($payload['categoria'] ?? null),
            'destinacion' => $this->strOrNull($payload['destinacion'] ?? null),
            'precio_venta' => $this->decimalOrNull($payload['precio_venta'] ?? null),
            'precio_arriendo' => $this->decimalOrNull($payload['precio_arriendo'] ?? null),
            'precio_iva' => $this->decimalOrNull($payload['precio_iva'] ?? null),
            'precio_admin' => $this->decimalOrNull($payload['precio_admin'] ?? $payload['precio_administracion'] ?? null),
            'area_construida' => $this->decimalOrNull($payload['area_construida'] ?? null),
            'area_privada' => $this->decimalOrNull($payload['area_privada'] ?? null),
            'area_terreno' => $this->decimalOrNull($payload['area_terreno'] ?? null),
            'habitaciones' => (int) ($payload['habitaciones'] ?? 0),
            'banos' => (int) ($payload['banos'] ?? 0),
            'pisos' => $this->intOrNull($payload['pisos'] ?? null),
            'estrato' => $this->intOrNull($this->firstPayloadValue($payload, ['estrato', 'stratum', 'estrato_inmueble'])),
            'parqueaderos' => (int) ($payload['parqueaderos'] ?? 0),
            'depositos' => (int) ($payload['depositos'] ?? 0),
            'ano_construccion' => $this->intOrNull($payload['ano_construccion'] ?? null),
            'estado' => $this->normalizeEstado($payload['estado'] ?? 'disponible'),
            'publicar_proppit' => $this->normalizeEstado($payload['estado'] ?? 'disponible') === 'no_disponible' ? 0 : 1,
            'publicar_fincaraiz' => $this->normalizeEstado($payload['estado'] ?? 'disponible') === 'no_disponible'
                ? 0
                : ($this->bool($payload['publicar_fincaraiz'] ?? $payload['fincaraiz'] ?? true) ? 1 : 0),
            'is_boosted' => $this->normalizeEstado($payload['estado'] ?? 'disponible') === 'no_disponible' ? 0 : 1,
            'is_exclusive' => $this->bool($this->firstPayloadValue($payload, ['isExclusive', 'is_exclusive', 'exclusive', 'exclusivo'], false)) ? 1 : 0,
            'source_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function insert(array $data): int
    {
        $columns = array_keys($data);
        $sql = 'INSERT INTO inmuebles (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
        $this->pdo->prepare($sql)->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    private function update(int $id, array $data): void
    {
        $sets = array_map(fn (string $column): string => "{$column} = :{$column}", array_keys($data));
        $data['id'] = $id;
        $sql = 'UPDATE inmuebles SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
        $this->pdo->prepare($sql)->execute($data);
    }

    private function replaceLocation(int $id, array $payload): void
    {
        $barrio = $this->strOrNull($payload['barrio'] ?? null);
        $barrioData = $this->barrioCatalogData($barrio);
        $cctData = $this->cctLocationData($payload);
        $latitud = $payload['latitud'] ?? $payload['lat'] ?? $barrioData['latitud'] ?? $cctData['latitud'] ?? 0;
        $longitud = $payload['longitud'] ?? $payload['lng'] ?? $payload['long'] ?? $barrioData['longitud'] ?? $cctData['longitud'] ?? 0;

        $this->pdo->prepare('DELETE FROM inmueble_ubicaciones WHERE inmueble_id = :id')->execute(['id' => $id]);
        $statement = $this->pdo->prepare(
            'INSERT INTO inmueble_ubicaciones
             (inmueble_id, direccion, barrio, ciudad, departamento, pais, codigo_postal, latitud, longitud, visibilidad)
             VALUES (:inmueble_id, :direccion, :barrio, :ciudad, :departamento, :pais, :codigo_postal, :latitud, :longitud, :visibilidad)'
        );
        $statement->execute([
            'inmueble_id' => $id,
            'direccion' => (string) ($payload['direccion'] ?? ''),
            'barrio' => $barrio ?: $this->strOrNull($cctData['barrio'] ?? null),
            'ciudad' => (string) (($payload['ciudad'] ?? null) ?: ($cctData['ciudad'] ?? null) ?: ($barrioData['ciudad'] ?? null) ?: 'Cartagena'),
            'departamento' => $this->strOrNull(($payload['departamento'] ?? null) ?: ($cctData['departamento'] ?? null) ?: ($barrioData['departamento'] ?? null) ?: 'Bolivar'),
            'pais' => $this->countryCode(($payload['pais'] ?? null) ?: ($barrioData['pais'] ?? null) ?: 'CO'),
            'codigo_postal' => $this->strOrNull(($payload['codigo_postal'] ?? null) ?: ($payload['postcode'] ?? null) ?: ($barrioData['codigo_postal'] ?? null)),
            'latitud' => (float) $latitud,
            'longitud' => (float) $longitud,
            'visibilidad' => in_array(($payload['visibilidad_proppit'] ?? 'approximate'), ['accurate', 'approximate'], true)
                ? $payload['visibilidad_proppit']
                : 'approximate',
        ]);
    }

    private function barrioCatalogData(?string $barrio): ?array
    {
        if (!$barrio) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT latitud, longitud, ciudad, departamento, pais, codigo_postal
             FROM app_barrios_catalog
             WHERE barrio_norm = :barrio_norm AND activo = 1
             LIMIT 1'
        );
        $statement->execute(['barrio_norm' => $this->normalizeBarrio($barrio)]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function cctLocationData(array $payload): ?array
    {
        $postId = $payload['wp_post_id'] ?? $payload['id_inmueble'] ?? null;
        $referenceId = $payload['reference_id'] ?? null;
        $codigo = $referenceId ? 'INM-' . preg_replace('/^INM-/i', '', (string) $referenceId) : null;

        if (!$postId && !$referenceId) {
            return null;
        }

        $where = [];
        $params = [];
        if ($postId) {
            $where[] = '_ID = :post_id';
            $params['post_id'] = (int) $postId;
        }
        if ($codigo) {
            $where[] = 'codigo = :codigo';
            $params['codigo'] = $codigo;
        }
        if ($referenceId) {
            $where[] = 'codigo = :reference_codigo';
            $params['reference_codigo'] = (string) $referenceId;
        }

        $statement = $this->pdo->prepare(
            'SELECT barrio, ciudad, departamento, latitud, longitud
             FROM wp_jet_cct_inmueble
             WHERE (' . implode(' OR ', $where) . ')
               AND latitud IS NOT NULL AND latitud <> "" AND CAST(latitud AS DECIMAL(11,8)) <> 0
               AND longitud IS NOT NULL AND longitud <> "" AND CAST(longitud AS DECIMAL(11,8)) <> 0
             LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function normalizeBarrio(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim((string) $value);

        return [
            'morros' => 'zona norte',
            'barcelona' => 'barcelona de indias',
        ][$value] ?? $value;
    }

    private function replaceContacts(int $id, array $payload): void
    {
        $this->pdo->prepare('DELETE FROM inmueble_contactos WHERE inmueble_id = :id')->execute(['id' => $id]);
        $contacts = [
            'propietario' => ['nombre_propietario', 'celular_propietario', 'correo_propietario'],
            'consultor' => ['nombre_consultor', 'celular_consultor', 'correo_consultor'],
        ];

        foreach ($contacts as $type => [$nameKey, $phoneKey, $emailKey]) {
            if (empty($payload[$nameKey]) && empty($payload[$phoneKey]) && empty($payload[$emailKey])) {
                continue;
            }
            $this->insertContact($id, $type, $payload[$nameKey] ?? null, $payload[$phoneKey] ?? null, $payload[$emailKey] ?? null);
        }

        $this->insertContact(
            $id,
            'publicacion',
            $payload['contacto_nombre'] ?? $payload['nombre_consultor'] ?? $payload['nombre_propietario'] ?? null,
            $payload['contacto_celular'] ?? $payload['celular_consultor'] ?? $payload['celular_propietario'] ?? null,
            $payload['contacto_correo'] ?? $payload['correo_consultor'] ?? $payload['correo_propietario'] ?? null
        );
    }

    private function insertContact(int $id, string $type, $name, $phone, $email): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO inmueble_contactos (inmueble_id, tipo, nombre, celular, correo)
             VALUES (:inmueble_id, :tipo, :nombre, :celular, :correo)'
        );
        $statement->execute([
            'inmueble_id' => $id,
            'tipo' => $type,
            'nombre' => $this->strOrNull($name),
            'celular' => $this->strOrNull($phone),
            'correo' => $this->strOrNull($email),
        ]);
    }

    private function replaceFeatures(int $id, array $payload): void
    {
        $this->pdo->prepare('DELETE FROM inmueble_caracteristicas WHERE inmueble_id = :id')->execute(['id' => $id]);
        $groups = [
            'interna' => $this->mergeLists($payload, ['internas', 'caracteristicas_internas', 'interiores']),
            'externa' => $this->mergeLists($payload, ['externas', 'caracteristicas_externas', 'exteriores']),
            'servicio' => $this->mergeLists($payload, ['servicios', 'instalaciones', 'amenities', 'amenidades']),
            'cerca_de' => $this->mergeLists($payload, ['cerca_de', 'cerca', 'nearbyLocations', 'nearby_locations']),
        ];

        foreach ($groups as $type => $values) {
            foreach ($values as $value) {
                $this->pdo->prepare(
                    'INSERT INTO inmueble_caracteristicas (inmueble_id, tipo, valor) VALUES (:inmueble_id, :tipo, :valor)'
                )->execute(['inmueble_id' => $id, 'tipo' => $type, 'valor' => $value]);
            }
        }
    }

    private function replaceMedia(int $id, array $payload): void
    {
        $this->pdo->prepare('DELETE FROM inmueble_multimedia WHERE inmueble_id = :id')->execute(['id' => $id]);
        $order = 0;
        foreach ($this->list($payload['portada_url'] ?? $payload['portada'] ?? []) as $url) {
            $this->insertMedia($id, 'portada', $url, $order++);
        }
        foreach ($this->list($payload['galeria_urls'] ?? $payload['galeria'] ?? []) as $url) {
            $this->insertMedia($id, 'galeria', $url, $order++);
        }
        foreach ($this->list($payload['planos_urls'] ?? $payload['planos'] ?? []) as $url) {
            $this->insertMedia($id, 'plano', $url, $order++);
        }
        foreach ($this->list($payload['tour_url'] ?? $payload['virtual-tour'] ?? $payload['virtual_tour'] ?? []) as $url) {
            $this->insertMedia($id, 'tour', $url, $order++);
        }
    }

    private function insertMedia(int $id, string $type, string $url, int $order): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO inmueble_multimedia (inmueble_id, tipo, url, orden) VALUES (:inmueble_id, :tipo, :url, :orden)'
        )->execute(['inmueble_id' => $id, 'tipo' => $type, 'url' => $url, 'orden' => $order]);
    }

    private function upsertProppitAd(int $id, array $payload, string $action, bool $publishIntent): void
    {
        $publisher = (string) ($payload['publisher_external_id'] ?? getenv('PROPPIT_PUBLISHER_EXTERNAL_ID') ?: getenv('PROPPIT_CRM') ?: 'go_cartagena_real_state');
        $country = strtoupper((string) ($payload['pais'] ?? getenv('PROPPIT_COUNTRY') ?: 'CO'));

        if (!$publishIntent) {
            $statement = $this->pdo->prepare(
                "UPDATE proppit_ads
                 SET desired_action = 'delete', sync_status = 'pending', updated_at = NOW()
                 WHERE inmueble_id = :inmueble_id"
            );
            $statement->execute(['inmueble_id' => $id]);
            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO proppit_ads (inmueble_id, publisher_external_id, country, desired_action, sync_status)
             VALUES (:inmueble_id, :publisher_external_id, :country, :desired_action, 'pending')
             ON DUPLICATE KEY UPDATE
               publisher_external_id = VALUES(publisher_external_id),
               country = VALUES(country),
               desired_action = VALUES(desired_action),
               sync_status = 'pending',
               updated_at = NOW()"
        );
        $statement->execute([
            'inmueble_id' => $id,
            'publisher_external_id' => $publisher,
            'country' => $country,
            'desired_action' => $action,
        ]);
    }

    private function ensurePublishAd(int $inmuebleId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO proppit_ads (inmueble_id, publisher_external_id, country, desired_action, sync_status)
             VALUES (:inmueble_id, :publisher_external_id, :country, 'publish', 'pending')
             ON DUPLICATE KEY UPDATE
               desired_action = IF(remote_status = 'published', 'update', 'publish'),
               sync_status = 'pending',
               last_error = NULL,
               updated_at = NOW()"
        );
        $statement->execute([
            'inmueble_id' => $inmuebleId,
            'publisher_external_id' => getenv('PROPPIT_PUBLISHER_EXTERNAL_ID') ?: getenv('PROPPIT_CRM') ?: 'go_cartagena_real_state',
            'country' => getenv('PROPPIT_COUNTRY') ?: 'CO',
        ]);
    }

    private function ensureDeleteAd(int $inmuebleId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO proppit_ads (inmueble_id, publisher_external_id, country, desired_action, sync_status, remote_status)
             VALUES (:inmueble_id, :publisher_external_id, :country, 'delete', 'pending', 'not_sent')
             ON DUPLICATE KEY UPDATE
               desired_action = 'delete',
               sync_status = 'pending',
               updated_at = NOW()"
        );
        $statement->execute([
            'inmueble_id' => $inmuebleId,
            'publisher_external_id' => getenv('PROPPIT_PUBLISHER_EXTERNAL_ID') ?: getenv('PROPPIT_CRM') ?: 'go_cartagena_real_state',
            'country' => getenv('PROPPIT_COUNTRY') ?: 'CO',
        ]);
    }

    private function ensureActionAd(int $inmuebleId, string $action): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO proppit_ads (inmueble_id, publisher_external_id, country, desired_action, sync_status)
             VALUES (:inmueble_id, :publisher_external_id, :country, :desired_action, 'pending')
             ON DUPLICATE KEY UPDATE
               publisher_external_id = VALUES(publisher_external_id),
               country = VALUES(country),
               desired_action = VALUES(desired_action),
               remote_status = IF(remote_status = 'error' AND VALUES(desired_action) = 'publish', 'not_sent', remote_status),
               sync_status = 'pending',
               last_error = NULL,
               next_sync_at = NULL,
               updated_at = NOW()"
        );
        $statement->execute([
            'inmueble_id' => $inmuebleId,
            'publisher_external_id' => getenv('PROPPIT_PUBLISHER_EXTERNAL_ID') ?: getenv('PROPPIT_CRM') ?: 'go_cartagena_real_state',
            'country' => getenv('PROPPIT_COUNTRY') ?: 'CO',
            'desired_action' => $action,
        ]);
    }

    private function upsertFincaraizAd(int $id, string $action, bool $publishIntent): void
    {
        if (!$publishIntent) {
            $this->ensureFincaraizActionAd($id, 'pause');
            return;
        }

        $this->ensureFincaraizActionAd($id, $action);
    }

    private function ensureFincaraizActionAd(int $inmuebleId, string $action, ?string $reason = null): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO fincaraiz_ads (inmueble_id, client_id, desired_action, sync_status, last_error)
             VALUES (:inmueble_id, :client_id, :desired_action, 'pending', :last_error)
             ON DUPLICATE KEY UPDATE
               client_id = VALUES(client_id),
               desired_action = VALUES(desired_action),
               remote_status = IF(remote_status = 'error' AND VALUES(desired_action) = 'publish', 'not_sent', remote_status),
               sync_status = 'pending',
               last_error = VALUES(last_error),
               next_sync_at = NULL,
               updated_at = NOW()"
        );
        $statement->execute([
            'inmueble_id' => $inmuebleId,
            'client_id' => \App\Core\Env::get('FINCARAIZ_CLIENT_ID', '7750b42d-a577-11f1-8d89-06ecc7fea243'),
            'desired_action' => $action,
            'last_error' => $reason,
        ]);
    }

    public function updateFincaraizLocationMapping(string $barrio, string $locationId, ?string $name = null): bool
    {
        $barrio = trim($barrio);
        $locationId = trim($locationId);
        if ($barrio === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $locationId)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO fincaraiz_location_mappings (barrio_norm, barrio, location_id, location_name)
             VALUES (:barrio_norm, :barrio, :location_id, :location_name)
             ON DUPLICATE KEY UPDATE
               barrio = VALUES(barrio),
               location_id = VALUES(location_id),
               location_name = VALUES(location_name),
               updated_at = NOW()"
        );
        $statement->execute([
            'barrio_norm' => $this->normalizeBarrio($barrio),
            'barrio' => $barrio,
            'location_id' => $locationId,
            'location_name' => $name ?: $barrio,
        ]);

        return true;
    }

    private function referenceId(array $payload): string
    {
        if (!empty($payload['reference_id'])) {
            return (string) $payload['reference_id'];
        }

        $postId = $payload['wp_post_id'] ?? $payload['id_inmueble'] ?? null;
        return $postId ? (string) $postId : strtoupper(bin2hex(random_bytes(4)));
    }

    private function one(string $sql, int $id): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function many(string $sql, int $id): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }

    private function columnOptions(string $sql): array
    {
        return array_values(array_filter(array_map('strval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function panelWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['codigo'] ?? '') !== '') {
            $where[] = 'i.reference_id LIKE :codigo';
            $params['codigo'] = '%' . $filters['codigo'] . '%';
        }
        if (($filters['direccion'] ?? '') !== '') {
            $where[] = '(u.direccion LIKE :direccion OR u.barrio LIKE :direccion OR i.titulo LIKE :direccion)';
            $params['direccion'] = '%' . $filters['direccion'] . '%';
        }
        foreach (['tipo_inmueble', 'categoria', 'destinacion'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where[] = "i.{$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }
        if (($filters['barrio'] ?? '') !== '') {
            $where[] = 'u.barrio = :barrio';
            $params['barrio'] = $filters['barrio'];
        }
        if (($filters['marcado'] ?? '') === 'si') {
            $where[] = 'i.publicar_proppit = 1';
        }
        if (($filters['marcado'] ?? '') === 'no') {
            $where[] = 'i.publicar_proppit = 0';
        }
        if (($filters['proppit_estado'] ?? '') !== '') {
            $where[] = 'p.remote_status = :proppit_estado';
            $params['proppit_estado'] = $filters['proppit_estado'];
        }
        if (($filters['proppit_action'] ?? '') !== '') {
            $where[] = 'p.desired_action = :proppit_action';
            $params['proppit_action'] = $filters['proppit_action'];
        }
        if (($filters['fincaraiz_estado'] ?? '') !== '') {
            $where[] = 'f.remote_status = :fincaraiz_estado';
            $params['fincaraiz_estado'] = $filters['fincaraiz_estado'];
        }
        if (($filters['fincaraiz_action'] ?? '') !== '') {
            $where[] = 'f.desired_action = :fincaraiz_action';
            $params['fincaraiz_action'] = $filters['fincaraiz_action'];
        }
        if (($filters['portal'] ?? '') === 'proppit') {
            $where[] = "(i.publicar_proppit = 1 OR p.remote_status IS NOT NULL AND p.remote_status <> 'not_sent')";
        }
        if (($filters['portal'] ?? '') === 'fincaraiz') {
            $where[] = "(i.publicar_fincaraiz = 1 OR f.remote_status IS NOT NULL AND f.remote_status <> 'not_sent')";
        }

        return [$where, $params];
    }

    private function list($value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $this->list($decoded);
            }
            $unserialized = @unserialize($trimmed, ['allowed_classes' => false]);
            if (is_array($unserialized)) {
                return $this->list($unserialized);
            }
        }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $items = array_merge($items, $this->list($item));
                    continue;
                }
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $items[] = trim((string) $item);
                }
            }
            return array_values(array_unique($items));
        }
        if (is_string($value) && strpos($value, ',') !== false) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }
        return is_scalar($value) && trim((string) $value) !== '' ? [trim((string) $value)] : [];
    }

    private function mergeLists(array $payload, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $values = array_merge($values, $this->list($payload[$key]));
            }
        }
        return array_values(array_unique($values));
    }

    private function firstPayloadValue(array $payload, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $list = $this->list($payload[$key]);
            if ($list !== []) {
                return $list[0];
            }
            if (is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }
        return $default;
    }

    private function strOrNull($value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' ? null : $value;
    }

    private function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function decimalOrNull($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function bool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off', ''], true);
    }

    private function countryCode($value): string
    {
        $country = strtoupper(trim((string) $value));
        return in_array($country, ['CO', 'COL', 'COLOMBIA'], true) ? 'CO' : substr($country ?: 'CO', 0, 2);
    }

    private function normalizeEstado($estado): string
    {
        return in_array(strtolower((string) $estado), ['no disponible', 'no_disponible', 'deleted'], true)
            ? 'no_disponible'
            : 'disponible';
    }

    private function ensureOptionalSchema(): void
    {
        $this->ensureColumn('inmuebles', 'publicar_fincaraiz', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER publicar_proppit');
        $this->ensureColumn('inmuebles', 'is_boosted', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER publicar_proppit');
        $this->ensureColumn('inmuebles', 'is_exclusive', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_boosted');
        $this->ensureColumn('inmueble_ubicaciones', 'codigo_postal', 'VARCHAR(20) DEFAULT NULL AFTER pais');
        $this->ensureColumn('app_barrios_catalog', 'ciudad', 'VARCHAR(150) DEFAULT NULL AFTER longitud');
        $this->ensureColumn('app_barrios_catalog', 'departamento', 'VARCHAR(150) DEFAULT NULL AFTER ciudad');
        $this->ensureColumn('app_barrios_catalog', 'pais', 'VARCHAR(60) DEFAULT NULL AFTER departamento');
        $this->ensureColumn('app_barrios_catalog', 'codigo_postal', 'VARCHAR(20) DEFAULT NULL AFTER pais');
        $this->ensureColumn('app_barrios_catalog', 'ciencuadras_locality_id', 'VARCHAR(100) DEFAULT NULL AFTER codigo_postal');
        $this->ensureColumn('app_barrios_catalog', 'fincaraiz_location_id', 'VARCHAR(100) DEFAULT NULL AFTER ciencuadras_locality_id');
        $this->ensureColumn('app_barrios_catalog', 'fincaraiz_location_name', 'VARCHAR(220) DEFAULT NULL AFTER fincaraiz_location_id');
        $this->ensureColumn('app_barrios_catalog', 'fincaraiz_location_type', 'VARCHAR(80) DEFAULT NULL AFTER fincaraiz_location_name');
        $this->ensureColumn('app_barrios_catalog', 'source_cct_id', 'BIGINT UNSIGNED DEFAULT NULL AFTER fincaraiz_location_type');
        $this->ensureColumn('app_barrios_catalog', 'source_updated_at', 'DATETIME DEFAULT NULL AFTER source_cct_id');
        $this->ensureCaracteristicasTypes();
        $this->ensureFincaraizTables();
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $statement = $this->pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $this->pdo->quote($column));
        if (!$statement || !$statement->fetch()) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function ensureCaracteristicasTypes(): void
    {
        $statement = $this->pdo->query("SHOW COLUMNS FROM inmueble_caracteristicas LIKE 'tipo'");
        $column = $statement ? $statement->fetch() : null;
        $type = (string) ($column['Type'] ?? '');
        foreach (['servicio', 'cerca_de'] as $required) {
            if (strpos($type, "'" . $required . "'") === false) {
                $this->pdo->exec("ALTER TABLE inmueble_caracteristicas MODIFY tipo ENUM('interna','externa','servicio','cerca_de') NOT NULL");
                return;
            }
        }
    }

    private function ensureFincaraizTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS fincaraiz_ads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inmueble_id BIGINT UNSIGNED NOT NULL,
                client_id VARCHAR(100) NOT NULL,
                external_id VARCHAR(120) DEFAULT NULL,
                fr_property_id VARCHAR(120) DEFAULT NULL,
                external_url VARCHAR(500) DEFAULT NULL,
                desired_action ENUM('publish','update','pause','activate','verify') NOT NULL DEFAULT 'publish',
                remote_status ENUM('not_sent','pending','active','disabled','deleted','error') NOT NULL DEFAULT 'not_sent',
                sync_status ENUM('pending','processing','synced','failed') NOT NULL DEFAULT 'pending',
                task_id VARCHAR(120) DEFAULT NULL,
                payload_hash CHAR(64) DEFAULT NULL,
                last_payload JSON DEFAULT NULL,
                last_response JSON DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                retry_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_synced_at DATETIME DEFAULT NULL,
                next_sync_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_fincaraiz_inmueble (inmueble_id),
                KEY idx_fincaraiz_pending (sync_status, next_sync_at),
                KEY idx_fincaraiz_external (external_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS fincaraiz_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inmueble_id BIGINT UNSIGNED DEFAULT NULL,
                action VARCHAR(60) NOT NULL,
                method VARCHAR(10) NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                request_payload JSON DEFAULT NULL,
                response_payload JSON DEFAULT NULL,
                http_status INT DEFAULT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_fincaraiz_logs_inmueble (inmueble_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS fincaraiz_location_mappings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barrio_norm VARCHAR(180) NOT NULL,
                barrio VARCHAR(180) NOT NULL,
                location_id VARCHAR(100) NOT NULL,
                location_name VARCHAR(220) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_fincaraiz_barrio_norm (barrio_norm)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
