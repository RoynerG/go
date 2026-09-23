<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\PortalDisplay;
use PDO;

final class OperationsRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::pdo();
    }

    public static function filters(array $input): array
    {
        $choice = static fn (string $key, array $allowed, string $default = ''): string =>
            in_array($input[$key] ?? '', $allowed, true) ? $input[$key] : $default;
        return [
            'view' => $choice('view', ['queue', 'errors', 'history'], 'queue'),
            'portal' => $choice('portal', ['proppit', 'fincaraiz', 'mercadolibre']),
            'status' => $choice('status', ['pending', 'processing', 'failed', 'synced']),
            'action' => $choice('action', ['publish', 'update', 'pause', 'delete', 'activate', 'verify', 'sync_error']),
            'q' => is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 120) : '',
        ];
    }

    public function page(array $input): array
    {
        $filters = self::filters($input);
        $history = $filters['view'] === 'history';
        $parts = [];
        foreach (['proppit', 'fincaraiz', 'mercadolibre'] as $portal) {
            if ($filters['portal'] !== '' && $filters['portal'] !== $portal) continue;
            if ($history) {
                $parts[] = "SELECT l.id entry_id, l.inmueble_id, '{$portal}' portal,
                    CASE l.action WHEN 'create_ad' THEN 'publish' WHEN 'create' THEN 'publish'
                        WHEN 'update_ad' THEN 'update' WHEN 'delete_ad' THEN 'pause' ELSE l.action END desired_action,
                    IF(l.success=1,'synced','failed') sync_status, '' remote_status,
                    l.error_message last_error, l.created_at updated_at, NULL next_attempt_at,
                    NULL attempts, l.http_status FROM {$portal}_logs l";
            } else {
                $retry = $portal === 'mercadolibre' ? 'next_attempt_at' : 'next_sync_at';
                $attempts = $portal === 'mercadolibre' ? 'attempts' : 'retry_count';
                $action = $portal === 'proppit' ? "CASE a.desired_action WHEN 'delete' THEN 'pause' ELSE a.desired_action END" : 'a.desired_action';
                $parts[] = "SELECT a.inmueble_id entry_id, a.inmueble_id, '{$portal}' portal,
                    {$action} desired_action, a.sync_status, a.remote_status, a.last_error, a.updated_at,
                    a.{$retry} next_attempt_at, a.{$attempts} attempts, NULL http_status
                    FROM {$portal}_ads a WHERE a.sync_status IN ('pending','processing','failed')";
            }
        }
        $from = '(' . implode(' UNION ALL ', $parts) . ') activity
            LEFT JOIN inmuebles i ON i.id=activity.inmueble_id
            LEFT JOIN inmueble_ubicaciones u ON u.inmueble_id=activity.inmueble_id';
        $where = ['1=1'];
        $params = [];
        $status = $filters['view'] === 'errors' ? 'failed' : $filters['status'];
        if ($status !== '') { $where[] = 'activity.sync_status=?'; $params[] = $status; }
        if ($filters['action'] !== '') { $where[] = 'activity.desired_action=?'; $params[] = $filters['action']; }
        if ($filters['q'] !== '') {
            $where[] = "LOCATE(LOWER(?),LOWER(CONCAT_WS(' ',i.reference_id,i.titulo,u.barrio,activity.last_error)))>0";
            $params[] = $filters['q'];
        }
        $where = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, (int) ($input['page'] ?? 1)));
        $offset = ($page - 1) * 20;
        $order = $history ? '' : "CASE activity.sync_status WHEN 'processing' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END,";
        $query = $this->pdo->prepare("SELECT activity.*, i.reference_id, i.titulo, u.barrio
            FROM {$from} WHERE {$where}
            ORDER BY {$order} activity.updated_at DESC, activity.portal, activity.entry_id DESC LIMIT 20 OFFSET {$offset}");
        $query->execute($params);
        $items = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['portal_label'] = ['proppit'=>'Proppit','fincaraiz'=>'Finca Raiz','mercadolibre'=>'Mercado Libre'][$item['portal']];
            $item['action_label'] = $item['desired_action'] === 'delete' && $item['portal'] === 'proppit'
                ? 'Despublicar' : PortalDisplay::action($item['desired_action']);
        }
        unset($item);
        return ['items'=>$items, 'filters'=>$filters, 'total'=>$total, 'page'=>$page, 'pages'=>$pages,
            'from'=>$total ? $offset + 1 : 0, 'to'=>min($total, $offset + 20)];
    }
}
