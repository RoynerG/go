<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

require dirname(__DIR__) . '/app/bootstrap.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Bogota'));

$pdo = Database::pdo();
$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 250;

$idsStatement = $pdo->prepare(
    "SELECT f.id
     FROM fincaraiz_ads f
     INNER JOIN inmuebles i ON i.id = f.inmueble_id
    WHERE i.estado <> 'no_disponible'
      AND i.publicar_fincaraiz = 1
      AND EXISTS (
        SELECT 1
        FROM inmueble_multimedia m
        WHERE m.inmueble_id = i.id
          AND m.tipo IN ('portada', 'galeria')
          AND m.url IS NOT NULL
          AND m.url <> ''
      )
    ORDER BY f.updated_at DESC
     LIMIT :limit"
);
$idsStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
$idsStatement->execute();
$ids = array_map('intval', $idsStatement->fetchAll(PDO::FETCH_COLUMN) ?: []);

if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(
        "UPDATE fincaraiz_ads
         SET desired_action = IF(external_id IS NULL OR external_id = '', 'publish', 'update'),
             sync_status = 'pending',
             last_error = NULL,
             next_sync_at = NULL,
             updated_at = NOW()
         WHERE id IN ({$placeholders})"
    );
    $statement->execute($ids);
    $count = $statement->rowCount();
} else {
    $count = 0;
}

echo json_encode([
    'ok' => true,
    'queued' => (int) $count,
    'message' => 'Avisos de Finca Raiz reencolados para reenviar fotos JPG.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
