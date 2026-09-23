<?php

declare(strict_types=1);

use App\Controllers\ApiInmuebleController;
use App\Controllers\CronController;
use App\Controllers\MediaController;
use App\Controllers\PanelController;
use App\Core\Env;
use App\Core\Router;

require dirname(__DIR__) . '/app/bootstrap.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Bogota'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$router = new Router();

$router->get('/', [PanelController::class, 'index']);
$router->get('/media/fincaraiz/{id}', [MediaController::class, 'fincaraizImage']);
$router->get('/panel/login', [\App\Controllers\AuthController::class, 'showLogin']);
$router->post('/panel/login', [\App\Controllers\AuthController::class, 'login']);
$router->post('/logout', [\App\Controllers\AuthController::class, 'logout']);
$router->get('/panel/inmuebles', [PanelController::class, 'index']);
$router->get('/panel/cola-cron', [PanelController::class, 'queuePage']);
$router->get('/panel/operaciones', [PanelController::class, 'queuePage']);
$router->get('/panel/operacion/estado', [PanelController::class, 'operationStatus']);
$router->get('/panel/logs', [PanelController::class, 'logsPage']);
$router->get('/panel/automatizacion', [PanelController::class, 'automationPage']);
$router->get('/panel/estados', [PanelController::class, 'statesPage']);
$router->get('/panel/publicados', [PanelController::class, 'publishedPage']);
$router->get('/panel/eliminados', [PanelController::class, 'deletedPage']);
$router->get('/panel/errores', [PanelController::class, 'errorsPage']);
$router->get('/panel/inmuebles/{id}', [PanelController::class, 'show']);
$router->post('/panel/inmuebles/{id}/publicar', [PanelController::class, 'publish']);
$router->post('/panel/inmuebles/{id}/actualizar', [PanelController::class, 'updateInProppit']);
$router->post('/panel/inmuebles/{id}/despublicar', [PanelController::class, 'unpublishInProppit']);
$router->post('/panel/inmuebles/{id}/no-publicar', [PanelController::class, 'unpublishInProppit']);
$router->post('/panel/inmuebles/{id}/destacado', [PanelController::class, 'toggleBoosted']);
$router->post('/panel/inmuebles/{id}/exclusivo', [PanelController::class, 'toggleExclusive']);
$router->post('/panel/inmuebles/{id}/fincaraiz/publicar', [PanelController::class, 'publishInFincaraiz']);
$router->post('/panel/inmuebles/{id}/fincaraiz/actualizar', [PanelController::class, 'updateInFincaraiz']);
$router->post('/panel/inmuebles/{id}/fincaraiz/despublicar', [PanelController::class, 'unpublishInFincaraiz']);
$router->post('/panel/inmuebles/{id}/fincaraiz/verificar', [PanelController::class, 'verifyInFincaraiz']);
$router->post('/panel/proppit/procesar-cola', [PanelController::class, 'processQueue']);
$router->post('/panel/fincaraiz/procesar-cola', [PanelController::class, 'processFincaraizQueue']);
$router->post('/api/inmuebles', [ApiInmuebleController::class, 'store']);
$router->put('/api/inmuebles/{reference_id}', [ApiInmuebleController::class, 'update']);
$router->post('/api/inmuebles/{reference_id}/despublicar', [ApiInmuebleController::class, 'unpublish']);
$router->post('/cron/proppit-sync', [CronController::class, 'sync']);
$router->post('/cron/fincaraiz-sync', [CronController::class, 'syncFincaraiz']);
$router->post('/cron/portales-sync', [CronController::class, 'syncAll']);
$router->post('/cron/mercadolibre-sync', [CronController::class, 'syncMercadolibre']);
$router->post('/panel/mercadolibre/conectar', [\App\Controllers\MercadolibreController::class, 'connect']);
$router->get('/panel/mercadolibre/callback', [\App\Controllers\MercadolibreController::class, 'callback']);
$router->get('/panel/mercadolibre/estado', [\App\Controllers\MercadolibreController::class, 'status']);
$router->post('/panel/mercadolibre/paquetes', [\App\Controllers\MercadolibreController::class, 'packs']);
$router->post('/panel/mercadolibre/procesar-cola', [\App\Controllers\MercadolibreController::class, 'process']);
$router->post('/panel/inmuebles/{id}/mercadolibre/{action}', [\App\Controllers\MercadolibreController::class, 'action']);

$router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
