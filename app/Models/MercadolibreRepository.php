<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class MercadolibreRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::pdo();
    }

    public function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mercadolibre_account (
            id INT NOT NULL PRIMARY KEY, client_id VARCHAR(100) NOT NULL,
            user_id VARCHAR(100) NOT NULL, token_data TEXT NOT NULL,
            expires_at BIGINT NOT NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mercadolibre_ads (
            inmueble_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            reference_id VARCHAR(191) NOT NULL, external_id VARCHAR(100) NULL,
            external_url TEXT NULL, listing_type_id VARCHAR(40) NULL, intent VARCHAR(20) NOT NULL DEFAULT 'auto',
            desired_action VARCHAR(20) NOT NULL DEFAULT 'publish',
            remote_status VARCHAR(30) NOT NULL DEFAULT 'not_sent',
            sync_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            target_hash CHAR(64) NOT NULL DEFAULT '', synced_hash CHAR(64) NOT NULL DEFAULT '',
            version INT NOT NULL DEFAULT 1, attempts INT NOT NULL DEFAULT 0,
            uncertain TINYINT(1) NOT NULL DEFAULT 0, next_attempt_at DATETIME NULL,
            last_error TEXT NULL, last_synced_at DATETIME NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY ml_item (external_id), KEY ml_queue (sync_status, next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$this->pdo->query("SHOW COLUMNS FROM mercadolibre_ads LIKE 'listing_type_id'")->fetch()) {
            $this->pdo->exec('ALTER TABLE mercadolibre_ads ADD COLUMN listing_type_id VARCHAR(40) NULL');
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mercadolibre_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            inmueble_id BIGINT UNSIGNED NOT NULL, reference_id VARCHAR(191) NOT NULL,
            action VARCHAR(30) NOT NULL, success TINYINT(1) NOT NULL,
            http_status INT NOT NULL DEFAULT 0, error_message TEXT NULL,
            created_at DATETIME NOT NULL, KEY ml_log_property (inmueble_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function ad(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM mercadolibre_ads WHERE inmueble_id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function properties(?int $onlyId = null): array
    {
        $where = $onlyId !== null ? ' WHERE id=' . (int) $onlyId : '';
        $rows = $this->pdo->query('SELECT * FROM inmuebles' . $where . ' ORDER BY id')->fetchAll();
        $properties = [];
        foreach ($rows as $row) {
            $properties[(int) $row['id']] = $row + ['ubicacion' => [], 'multimedia' => [], 'caracteristicas' => []];
        }
        if (!$properties) return [];
        $ids = implode(',', array_keys($properties));
        foreach (['inmueble_ubicaciones' => ['ubicacion','inmueble_id'], 'inmueble_multimedia' => ['multimedia','orden, id'],
            'inmueble_caracteristicas' => ['caracteristicas','tipo, valor']] as $table => [$key, $order]) {
            foreach ($this->pdo->query("SELECT * FROM {$table} WHERE inmueble_id IN ({$ids}) ORDER BY {$order}")->fetchAll() as $row) {
                if ($key === 'ubicacion') {
                    $properties[(int) $row['inmueble_id']][$key] = $row;
                } else {
                    $properties[(int) $row['inmueble_id']][$key][] = $row;
                }
            }
        }
        return $properties;
    }

    public function allAds(): array
    {
        $ads = [];
        foreach ($this->pdo->query('SELECT * FROM mercadolibre_ads')->fetchAll() as $ad) {
            $ads[(int) $ad['inmueble_id']] = $ad;
        }
        return $ads;
    }

    public function account(): ?array
    {
        return $this->pdo->query('SELECT * FROM mercadolibre_account WHERE id = 1')->fetch() ?: null;
    }

    public function saveAccount(string $clientId, string $userId, string $encrypted, int $expires): void
    {
        $q = $this->pdo->prepare('INSERT INTO mercadolibre_account (id,client_id,user_id,token_data,expires_at,updated_at)
            VALUES (1,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id),user_id=VALUES(user_id),
            token_data=VALUES(token_data),expires_at=VALUES(expires_at),updated_at=NOW()');
        $q->execute([$clientId, $userId, $encrypted, $expires]);
    }

    public function lock(string $name): bool
    {
        $q = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $q->execute(['portales-go-ml-' . $name]);
        return (int) $q->fetchColumn() === 1;
    }

    public function unlock(string $name): void
    {
        $q = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $q->execute(['portales-go-ml-' . $name]);
    }

    public function ensure(int $id, string $reference): void
    {
        $q = $this->pdo->prepare('INSERT IGNORE INTO mercadolibre_ads (inmueble_id,reference_id,updated_at) VALUES (?,?,NOW())');
        $q->execute([$id, $reference]);
    }

    public function manual(int $id, string $action): void
    {
        $intent = match ($action) { 'pause' => 'paused', 'delete' => 'deleted', default => 'auto' };
        $q = $this->pdo->prepare("UPDATE mercadolibre_ads SET intent=?, desired_action=?, sync_status='pending',
            version=version+1, attempts=0, next_attempt_at=NULL, last_error=NULL, updated_at=NOW() WHERE inmueble_id=?");
        $q->execute([$intent, $action, $id]);
    }

    public function target(array $ad, string $hash, string $action): bool
    {
        if ($ad['target_hash'] === $hash) {
            return false;
        }
        // Compare the version so a concurrent manual pause cannot be overwritten by the scanner.
        $q = $this->pdo->prepare("UPDATE mercadolibre_ads SET target_hash=?,desired_action=?,sync_status='pending',
            version=version+1,attempts=0,next_attempt_at=NULL,last_error=NULL,updated_at=NOW()
            WHERE inmueble_id=? AND version=?");
        $q->execute([$hash, $action, $ad['inmueble_id'], $ad['version']]);
        return $q->rowCount() > 0;
    }

    public function pending(int $limit, ?int $onlyId = null): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "SELECT * FROM mercadolibre_ads WHERE sync_status IN ('pending','failed')
            AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) AND attempts < 5";
        if ($onlyId !== null) {
            $sql .= ' AND inmueble_id=' . (int) $onlyId;
        }
        return $this->pdo->query($sql . " ORDER BY (desired_action IN ('pause','delete')) DESC, updated_at, inmueble_id LIMIT {$limit}")->fetchAll();
    }

    public function recoverInterrupted(): void
    {
        // A process that stopped after POST /items may already have created an ad.
        $this->pdo->exec("UPDATE mercadolibre_ads SET sync_status='failed',
            uncertain=IF(external_id IS NULL,1,uncertain),next_attempt_at=NULL,
            last_error='Ejecucion interrumpida; se verificara el anuncio antes de continuar.'
            WHERE sync_status='processing'");
    }

    public function processing(array $ad): bool
    {
        $q = $this->pdo->prepare("UPDATE mercadolibre_ads SET sync_status='processing',updated_at=NOW()
            WHERE inmueble_id=? AND version=?");
        $q->execute([$ad['inmueble_id'], $ad['version']]);
        return $q->rowCount() > 0;
    }

    public function rememberRemote(int $id, array $item): void
    {
        if (empty($item['id'])) {
            return;
        }
        $status = in_array('deleted', $item['sub_status'] ?? [], true) ? 'deleted' : ($item['status'] ?? 'unknown');
        $q = $this->pdo->prepare('UPDATE mercadolibre_ads SET external_id=?,external_url=?,remote_status=?,listing_type_id=?,uncertain=0 WHERE inmueble_id=?');
        $q->execute([$item['id'], $item['permalink'] ?? '', $status, $item['listing_type_id'] ?? null, $id]);
    }

    public function complete(array $ad, string $remote): void
    {
        $q = $this->pdo->prepare("UPDATE mercadolibre_ads SET sync_status='synced',remote_status=?,synced_hash=target_hash,
            attempts=0,uncertain=0,next_attempt_at=NULL,last_error=NULL,last_synced_at=NOW(),updated_at=NOW()
            WHERE inmueble_id=? AND version=?");
        $q->execute([$remote, $ad['inmueble_id'], $ad['version']]);
    }

    public function fail(array $ad, string $message, bool $uncertain = false): void
    {
        // An ambiguous creation remains ambiguous even if a newer manual action exists.
        if ($uncertain) {
            $mark = $this->pdo->prepare('UPDATE mercadolibre_ads SET uncertain=1 WHERE inmueble_id=? AND external_id IS NULL');
            $mark->execute([$ad['inmueble_id']]);
        }
        $delay = min(3600, 60 * (2 ** min(6, (int) $ad['attempts'])));
        $q = $this->pdo->prepare("UPDATE mercadolibre_ads SET sync_status='failed',last_error=?,attempts=attempts+1,
            uncertain=GREATEST(uncertain,?),next_attempt_at=DATE_ADD(NOW(), INTERVAL {$delay} SECOND),updated_at=NOW()
            WHERE inmueble_id=? AND version=?");
        $q->execute([$message, (int) $uncertain, $ad['inmueble_id'], $ad['version']]);
    }

    public function log(array $ad, bool $success, int $status, ?string $error = null): void
    {
        $q = $this->pdo->prepare('INSERT INTO mercadolibre_logs (inmueble_id,reference_id,action,success,http_status,error_message,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $q->execute([$ad['inmueble_id'], $ad['reference_id'], $ad['desired_action'], (int) $success, $status, $error]);
    }

    public function waitQuota(array $ad, string $message): void
    {
        $q = $this->pdo->prepare("UPDATE mercadolibre_ads SET sync_status='pending',last_error=?,
            next_attempt_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE),updated_at=NOW() WHERE inmueble_id=? AND version=?");
        $q->execute([$message, $ad['inmueble_id'], $ad['version']]);
    }

    public function overview(): array
    {
        $queue = $this->pdo->query("SELECT COUNT(*) total, SUM(sync_status='pending') pending,
            SUM(sync_status='processing') processing, SUM(sync_status='failed') failed,
            SUM(remote_status='active') published, MAX(last_synced_at) last_synced_at FROM mercadolibre_ads")->fetch();
        $items = $this->pdo->query("SELECT a.*, i.titulo, u.barrio, 'mercadolibre' portal, 'Mercado Libre' portal_label,
            a.inmueble_id id FROM mercadolibre_ads a LEFT JOIN inmuebles i ON i.id=a.inmueble_id
            LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id=a.inmueble_id
            ORDER BY (a.sync_status IN ('pending','processing','failed')) DESC,a.updated_at DESC LIMIT 80")->fetchAll();
        $logs = $this->pdo->query("SELECT l.*,i.titulo,'mercadolibre' portal,'Mercado Libre' portal_label
            FROM mercadolibre_logs l LEFT JOIN inmuebles i ON i.id=l.inmueble_id ORDER BY l.id DESC LIMIT 50")->fetchAll();
        return ['queue' => $queue, 'items' => $items, 'logs' => $logs];
    }
}
