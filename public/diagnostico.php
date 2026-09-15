<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

\App\Core\Env::load(dirname(__DIR__) . '/.env');

$secret = \App\Core\Env::get('API_SHARED_SECRET', '');
$key = $_GET['key'] ?? '';

if ($secret === '' || !hash_equals($secret, (string) $key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$db = ['ok' => false, 'error' => null];
try {
    \App\Core\Database::pdo()->query('SELECT 1');
    $db['ok'] = true;
} catch (Throwable $exception) {
    $db['error'] = $exception->getMessage();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'php_version' => PHP_VERSION,
    'extensions' => [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
    ],
    'env' => [
        'app_url' => \App\Core\Env::get('APP_URL'),
        'app_base_path' => \App\Core\Env::get('APP_BASE_PATH'),
        'db_database_configured' => \App\Core\Env::get('DB_DATABASE') !== '',
    ],
    'database' => $db,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
