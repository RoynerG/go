<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Models\InmuebleRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Bogota'));

$apply = in_array('--apply', $argv, true);
$sourceUrl = 'https://sucasainmobiliaria.com.co/wp-json/jet-cct/barrios';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--apply') {
        $sourceUrl = $arg;
        break;
    }
}

new InmuebleRepository();
$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$json = fetchJson($sourceUrl);
$items = json_decode($json, true);
if (!is_array($items)) {
    fwrite(STDERR, "No se pudo leer JSON valido desde {$sourceUrl}" . PHP_EOL);
    exit(1);
}

$stats = [
    'source' => count($items),
    'catalog_upserts' => 0,
    'fincaraiz_mappings' => 0,
    'locations_completed' => 0,
    'skipped' => 0,
];

$catalogRows = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        $stats['skipped']++;
        continue;
    }

    $barrio = trim((string) ($item['barrio'] ?? ''));
    if ($barrio === '') {
        $stats['skipped']++;
        continue;
    }

    $latitud = trim((string) ($item['latitud'] ?? ''));
    $longitud = trim((string) ($item['longitud'] ?? ''));
    $ciudad = trim((string) ($item['ciudad'] ?? '')) ?: 'Cartagena';
    $departamento = trim((string) ($item['departamento'] ?? '')) ?: 'Bolivar';
    $pais = trim((string) ($item['pais'] ?? '')) ?: 'Colombia';
    $codigoPostal = trim((string) ($item['codigo_postal'] ?? ''));
    $fincaraizLocationId = trim((string) ($item['fincaraiz_location_id'] ?? ''));
    $fincaraizLocationName = trim((string) ($item['fincaraiz_location_name'] ?? '')) ?: $barrio;
    $fincaraizLocationType = trim((string) ($item['fincaraiz_location_type'] ?? ''));

    $row = [
        'barrio_nombre' => $barrio,
        'barrio_norm' => normalizeBarrio($barrio),
        'latitud' => $latitud,
        'longitud' => $longitud,
        'ciudad' => $ciudad,
        'departamento' => $departamento,
        'pais' => $pais,
        'codigo_postal' => $codigoPostal,
        'ciencuadras_locality_id' => trim((string) ($item['ciencuadras_locality_id'] ?? '')),
        'fincaraiz_location_id' => $fincaraizLocationId,
        'fincaraiz_location_name' => $fincaraizLocationName,
        'fincaraiz_location_type' => $fincaraizLocationType,
        'source_cct_id' => (int) ($item['_ID'] ?? 0) ?: null,
        'source_updated_at' => normalizeDate((string) ($item['cct_modified'] ?? '')),
    ];

    $catalogRows[$row['barrio_norm']] = $row;
}

if ($apply) {
    $pdo->beginTransaction();
}

try {
    $catalogSql = "INSERT INTO app_barrios_catalog
        (barrio_nombre, barrio_norm, latitud, longitud, ciudad, departamento, pais, codigo_postal,
         ciencuadras_locality_id, fincaraiz_location_id, fincaraiz_location_name, fincaraiz_location_type,
         source_cct_id, source_updated_at, activo, created_at, updated_at)
        VALUES
        (:barrio_nombre, :barrio_norm, :latitud, :longitud, :ciudad, :departamento, :pais, :codigo_postal,
         :ciencuadras_locality_id, :fincaraiz_location_id, :fincaraiz_location_name, :fincaraiz_location_type,
         :source_cct_id, :source_updated_at, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          barrio_nombre = VALUES(barrio_nombre),
          latitud = IF(VALUES(latitud) <> '', VALUES(latitud), latitud),
          longitud = IF(VALUES(longitud) <> '', VALUES(longitud), longitud),
          ciudad = IF(VALUES(ciudad) <> '', VALUES(ciudad), ciudad),
          departamento = IF(VALUES(departamento) <> '', VALUES(departamento), departamento),
          pais = IF(VALUES(pais) <> '', VALUES(pais), pais),
          codigo_postal = IF(VALUES(codigo_postal) <> '', VALUES(codigo_postal), codigo_postal),
          ciencuadras_locality_id = IF(VALUES(ciencuadras_locality_id) <> '', VALUES(ciencuadras_locality_id), ciencuadras_locality_id),
          fincaraiz_location_id = IF(VALUES(fincaraiz_location_id) <> '', VALUES(fincaraiz_location_id), fincaraiz_location_id),
          fincaraiz_location_name = IF(VALUES(fincaraiz_location_name) <> '', VALUES(fincaraiz_location_name), fincaraiz_location_name),
          fincaraiz_location_type = IF(VALUES(fincaraiz_location_type) <> '', VALUES(fincaraiz_location_type), fincaraiz_location_type),
          source_cct_id = COALESCE(VALUES(source_cct_id), source_cct_id),
          source_updated_at = COALESCE(VALUES(source_updated_at), source_updated_at),
          activo = 1,
          updated_at = NOW()";

    $mappingSql = "INSERT INTO fincaraiz_location_mappings
        (barrio_norm, barrio, location_id, location_name, created_at, updated_at)
        VALUES (:barrio_norm, :barrio, :location_id, :location_name, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          barrio = VALUES(barrio),
          location_id = VALUES(location_id),
          location_name = VALUES(location_name),
          updated_at = NOW()";

    $catalogStmt = $pdo->prepare($catalogSql);
    $mappingStmt = $pdo->prepare($mappingSql);

    foreach ($catalogRows as $row) {
        if ($apply) {
            $catalogStmt->execute($row);
        }
        $stats['catalog_upserts']++;

        if (preg_match('/^[0-9a-fA-F-]{36}$/', $row['fincaraiz_location_id'])) {
            if ($apply) {
                $mappingStmt->execute([
                    'barrio_norm' => $row['barrio_norm'],
                    'barrio' => $row['barrio_nombre'],
                    'location_id' => $row['fincaraiz_location_id'],
                    'location_name' => $row['fincaraiz_location_name'],
                ]);
            }
            $stats['fincaraiz_mappings']++;
        }
    }

    $manualAliases = [
        [
            'barrio' => 'Morros',
            'source_barrio' => 'La Boquilla',
            'location_id' => '7e6db945-4175-4b87-9c67-f8768fa84b35',
            'location_name' => 'La Boquilla',
            'location_type' => 'NEIGHBOURHOOD',
        ],
        [
            'barrio' => 'Zona norte',
            'source_barrio' => 'Zona norte',
            'location_id' => '169b40dd-c55b-48a9-9d2f-7be15cf8d006',
            'location_name' => '2. localidad de la virgen y turistica',
            'location_type' => 'ZONE',
        ],
        [
            'barrio' => 'Anillovial',
            'source_barrio' => 'La Boquilla',
            'location_id' => '7e6db945-4175-4b87-9c67-f8768fa84b35',
            'location_name' => 'La Boquilla',
            'location_type' => 'NEIGHBOURHOOD',
        ],
        [
            'barrio' => 'Barcelona',
            'source_barrio' => 'Barcelona',
            'location_id' => '941d8ac9-4614-44df-b127-cf7086f07961',
            'location_name' => 'Barcelona de Indias',
            'location_type' => 'NEIGHBOURHOOD',
        ],
        [
            'barrio' => 'Chambacu',
            'source_barrio' => 'Chambacu',
            'location_id' => 'e0ab7579-86fa-4623-a3e7-c63dfeeab1d0',
            'location_name' => 'Chambacu',
            'location_type' => 'NEIGHBOURHOOD',
        ],
    ];

    foreach ($manualAliases as $alias) {
        $sourceKey = normalizeBarrio($alias['source_barrio']);
        $source = $catalogRows[$sourceKey] ?? null;
        if (!$source) {
            $lookup = $pdo->prepare('SELECT * FROM app_barrios_catalog WHERE barrio_norm = :barrio_norm LIMIT 1');
            $lookup->execute(['barrio_norm' => $sourceKey]);
            $source = $lookup->fetch() ?: null;
        }

        $aliasRow = [
            'barrio_nombre' => $alias['barrio'],
            'barrio_norm' => normalizeBarrio($alias['barrio']),
            'latitud' => (string) ($source['latitud'] ?? ''),
            'longitud' => (string) ($source['longitud'] ?? ''),
            'ciudad' => (string) ($source['ciudad'] ?? 'Cartagena'),
            'departamento' => (string) ($source['departamento'] ?? 'Bolivar'),
            'pais' => (string) ($source['pais'] ?? 'Colombia'),
            'codigo_postal' => (string) ($source['codigo_postal'] ?? '130002'),
            'ciencuadras_locality_id' => (string) ($source['ciencuadras_locality_id'] ?? ''),
            'fincaraiz_location_id' => $alias['location_id'],
            'fincaraiz_location_name' => $alias['location_name'],
            'fincaraiz_location_type' => $alias['location_type'],
            'source_cct_id' => $source['source_cct_id'] ?? null,
            'source_updated_at' => $source['source_updated_at'] ?? null,
        ];

        if ($apply) {
            $catalogStmt->execute($aliasRow);
            $mappingStmt->execute([
                'barrio_norm' => $aliasRow['barrio_norm'],
                'barrio' => $aliasRow['barrio_nombre'],
                'location_id' => $alias['location_id'],
                'location_name' => $alias['location_name'],
            ]);
        }
        $catalogRows[$aliasRow['barrio_norm']] = $aliasRow;
        $stats['catalog_upserts']++;
        $stats['fincaraiz_mappings']++;
    }

    if ($apply) {
        $stats['locations_completed'] = completeInmuebleLocations($pdo, $catalogRows);
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo ($apply ? 'APLICADO' : 'SIMULACION') . PHP_EOL;
foreach ($stats as $key => $value) {
    echo $key . ': ' . $value . PHP_EOL;
}

function completeInmuebleLocations(PDO $pdo, array $catalogRows): int
{
    $updated = 0;
    $locations = $pdo->query('SELECT id, barrio, ciudad, departamento, pais, codigo_postal, latitud, longitud FROM inmueble_ubicaciones')->fetchAll();
    $stmt = $pdo->prepare(
        'UPDATE inmueble_ubicaciones
         SET ciudad = :ciudad,
             departamento = :departamento,
             pais = :pais,
             codigo_postal = :codigo_postal,
             latitud = :latitud,
             longitud = :longitud,
             updated_at = NOW()
         WHERE id = :id'
    );

    foreach ($locations as $location) {
        $key = normalizeBarrio((string) ($location['barrio'] ?? ''));
        if ($key === '' || !isset($catalogRows[$key])) {
            continue;
        }

        $catalog = $catalogRows[$key];
        $lat = (float) ($location['latitud'] ?? 0);
        $lng = (float) ($location['longitud'] ?? 0);
        $needsLatLng = $lat === 0.0 || $lng === 0.0;

        $next = [
            'id' => (int) $location['id'],
            'ciudad' => trim((string) ($location['ciudad'] ?? '')) ?: $catalog['ciudad'],
            'departamento' => trim((string) ($location['departamento'] ?? '')) ?: $catalog['departamento'],
            'pais' => countryCode(trim((string) ($location['pais'] ?? '')) ?: $catalog['pais']),
            'codigo_postal' => trim((string) ($location['codigo_postal'] ?? '')) ?: ($catalog['codigo_postal'] ?: null),
            'latitud' => $needsLatLng && $catalog['latitud'] !== '' ? (float) $catalog['latitud'] : $lat,
            'longitud' => $needsLatLng && $catalog['longitud'] !== '' ? (float) $catalog['longitud'] : $lng,
        ];

        if ($needsLatLng || empty($location['ciudad']) || empty($location['departamento']) || empty($location['codigo_postal'])) {
            $stmt->execute($next);
            $updated++;
        }
    }

    return $updated;
}

function fetchJson(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header' => "Accept: application/json\r\nUser-Agent: Portales-Go-Barrios-Importer\r\n",
        ],
    ]);
    $json = @file_get_contents($url, false, $context);
    if (is_string($json) && $json !== '') {
        return $json;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Portales-Go-Barrios-Importer',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        if (is_string($response) && $response !== '') {
            return $response;
        }
    }

    return '';
}

function normalizeDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

function normalizeBarrio(string $value): string
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
    return trim((string) $value);
}

function countryCode(string $value): string
{
    $country = strtoupper(trim($value));
    return in_array($country, ['CO', 'COL', 'COLOMBIA'], true) ? 'CO' : substr($country ?: 'CO', 0, 2);
}
