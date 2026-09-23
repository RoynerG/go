<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\MercadolibreClient;
use App\Services\MercadolibrePayloadBuilder as Builder;
use App\Services\MercadolibreSyncService as Sync;
use App\Models\MercadolibreRepository;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function rejects(callable $callback, string $text): void
{
    try { $callback(); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), $text), 'Error esperado: ' . $text); return; }
    throw new RuntimeException('No se rechazo: ' . $text);
}

foreach (['CLIENT_ID','CLIENT_SECRET','REDIRECT_URI','TOKEN_KEY'] as $key) $_ENV['MERCADOLIBRE_' . $key] = '';
check(count(MercadolibreClient::configurationIssues()) === 4, 'Diagnostico identifica las cuatro variables faltantes');
$_ENV['MERCADOLIBRE_CLIENT_ID'] = 'test-app';
$_ENV['MERCADOLIBRE_CLIENT_SECRET'] = 'test-secret-not-real';
$_ENV['MERCADOLIBRE_REDIRECT_URI'] = 'https://example.com/callback';
$_ENV['MERCADOLIBRE_TOKEN_KEY'] = base64_encode(str_repeat('x', 32));
check(MercadolibreClient::configured(), 'Configuracion valida habilita conexion');
$_ENV['MERCADOLIBRE_TOKEN_KEY'] = 'invalid-key';
check(array_keys(MercadolibreClient::configurationIssues()) === ['MERCADOLIBRE_TOKEN_KEY'], 'Clave de cifrado invalida se identifica sin mostrar el secreto');
check(!str_contains(json_encode(MercadolibreClient::configurationIssues()), 'test-secret-not-real'), 'Diagnostico no expone credenciales');
$_ENV['MERCADOLIBRE_TOKEN_KEY'] = base64_encode(str_repeat('x', 32));
$_ENV['MERCADOLIBRE_REDIRECT_URI'] = 'http://example.com/callback';
check(!MercadolibreClient::configured(), 'Callback requiere HTTPS');
$_ENV['MERCADOLIBRE_REDIRECT_URI'] = 'https://example.com/callback';
check(\App\Core\PortalDisplay::state('active') === 'Publicado', 'Estado remoto traducido');
check(\App\Core\PortalDisplay::state('synced') === 'Confirmado', 'Cola no se confunde con publicacion');
check(\App\Core\PortalDisplay::action('sync_error') === 'Validacion del inmueble', 'Error local identificado');
$_ENV['MERCADOLIBRE_DEFAULT_PROPERTY_AGE'] = '';
check(Builder::propertyAge([]) === null, 'No inventa edad si el valor provisional esta deshabilitado');
check(Builder::propertyAge(['ano_construccion'=>(int) date('Y')-12]) === 12, 'Antiguedad desde ano de construccion');
check(Builder::propertyAge(['ano_construccion'=>(int) date('Y')+1]) === null, 'No envia una antiguedad negativa');
check(Builder::propertyAge(['source_payload'=>'{"edad_inmueble":0}']) === 0, 'Conserva antiguedad cero conocida');
check(Builder::propertyAge(['source_payload'=>'{"edad_inmueble":"5 a 10"}']) === null, 'No transforma rangos en edades inventadas');
$_ENV['MERCADOLIBRE_DEFAULT_PROPERTY_AGE'] = '8';
check(Builder::propertyAge([]) === 8, 'Aplica el provisional elegido por el usuario');
check(Builder::propertyAge(['mercadolibre_property_age'=>3]) === 3, 'Permite antiguedad elegida por inmueble');
check(Builder::propertyAge(['ano_construccion'=>(int) date('Y')-12,'mercadolibre_property_age'=>3]) === 12, 'El dato real sustituye al provisional');
check(Builder::fingerprint([], 'auto') !== Builder::fingerprint(['ano_construccion'=>(int) date('Y')-12], 'auto'), 'Actualizar el ano provoca nueva sincronizacion');
$_ENV['MERCADOLIBRE_CONTACT_PHONE'] = '';
$_ENV['MERCADOLIBRE_CONTACT_WHATSAPP'] = '';
$_ENV['MERCADOLIBRE_CONTACT_EMAIL'] = '';
check(count(Builder::contactIssues()) === 3, 'Contacto faltante se detecta antes de procesar cada inmueble');

class MlFixtureClient extends MercadolibreClient
{
    public array $calls = [];
    public array $replies = [];
    public array $definitions = [
        ['id' => 'BEDROOMS'], ['id' => 'FULL_BATHROOMS'], ['id' => 'TOTAL_AREA'], ['id' => 'SOCIAL_STRATUM'],
    ];
    public function __construct() {}
    public function catalog(string $path): array
    {
        return match ($path) {
            '/sites/MCO/categories' => [['id' => 'MCO_ROOT', 'name' => 'Inmuebles']],
            '/categories/MCO_ROOT' => ['children_categories' => [['id' => 'MCO_APARTMENT', 'name' => 'Apartamentos']]],
            '/categories/MCO_APARTMENT' => ['children_categories' => [['id' => 'MCO_SALE', 'name' => 'Venta'], ['id' => 'MCO_RENT','name' => 'Arriendo']]],
            '/categories/MCO_SALE', '/categories/MCO_RENT' => ['id' => basename($path), 'settings' => ['listing_allowed' => true,'vertical' => 'real_estate']],
            '/categories/MCO_SALE/attributes', '/categories/MCO_RENT/attributes' => $this->definitions,
            '/classified_locations/countries/CO' => ['states' => [['id' => 'STATE', 'name' => 'Bolivar']]],
            '/classified_locations/states/STATE' => ['cities' => [['id' => 'CITY', 'name' => 'Cartagena']]],
            '/classified_locations/cities/CITY' => ['neighborhoods' => [['id' => 'NEIGHBORHOOD', 'name' => 'Bocagrande']]],
            default => throw new RuntimeException('Catalogo inesperado: ' . $path),
        };
    }
    protected function request(string $method, string $path, ?array $payload = null): array
    {
        $this->calls[] = [$method, $path, $payload];
        if (!$this->replies) throw new RuntimeException('Llamada inesperada: ' . $method . ' ' . $path);
        [$expectedMethod, $expectedPath, $response] = array_shift($this->replies);
        check($method === $expectedMethod && $path === $expectedPath, 'Orden API ' . $method . ' ' . $path);
        return $response;
    }
}

$_ENV['MERCADOLIBRE_CONTACT_EMAIL'] = 'pruebas@example.com';
$_ENV['MERCADOLIBRE_CONTACT_PHONE'] = '+57 300 000 0000';
$_ENV['MERCADOLIBRE_CONTACT_WHATSAPP'] = '3000000000';
$_ENV['MERCADOLIBRE_CATEGORY_MAP'] = '{}';
$_ENV['MERCADOLIBRE_LOCATION_MAP'] = '{}';
$property = [
    'id' => 1, 'reference_id' => 'TEST-1', 'estado' => 'disponible', 'titulo' => 'Apartamento de prueba',
    'descripcion' => '<p>Descripcion del inmueble de prueba.</p>', 'tipo_inmueble' => 'Apartamento',
    'precio_venta' => 500000000, 'precio_arriendo' => 2500000, 'estrato' => 5, 'habitaciones' => 2, 'banos' => 2,
    'area_construida' => 90, 'ubicacion' => ['ciudad' => 'Cartagena', 'departamento' => 'Bolivar',
        'barrio' => 'Bocagrande', 'direccion' => 'Direccion de prueba', 'latitud' => 10.4, 'longitud' => -75.5],
    'multimedia' => [['id' => 50, 'tipo' => 'galeria', 'url' => 'https://example.com/b.jpg', 'orden' => 1],
        ['id' => 51, 'tipo' => 'portada', 'url' => 'https://example.com/a.jpg', 'orden' => 0]],
];
$client = new MlFixtureClient();
$builder = new Builder($client);
$payload = $builder->build($property, 'silver');
check($payload['buying_mode'] === 'classified' && $payload['currency_id'] === 'COP', 'Clasificados Colombia');
check($payload['category_id'] === 'MCO_SALE' && $payload['price'] === 500000000.0, 'Venta por defecto en oferta dual');
check($payload['pictures'][0]['source'] === 'https://example.com/a.jpg', 'Portada primero');
check($payload['seller_contact']['phone2'] === '3000000000' && $payload['seller_contact']['country_code2'] === '57', 'WhatsApp separado del codigo de pais');
check($payload['location']['neighborhood']['id'] === 'NEIGHBORHOOD', 'Barrio homologado');
check($payload['description']['plain_text'] === 'Descripcion del inmueble de prueba.', 'Descripcion sin HTML');
$_ENV['MERCADOLIBRE_DUAL_OFFER'] = 'rent';
check($builder->build($property, 'gold')['price'] === 2500000.0, 'Arriendo usa su precio');
$_ENV['MERCADOLIBRE_DUAL_OFFER'] = 'sale';
rejects(fn () => $builder->build(array_replace($property, ['multimedia' => []]), 'silver'), 'fotos');
rejects(fn () => $builder->build(array_replace($property, ['precio_venta' => 0, 'precio_arriendo' => 0]), 'silver'), 'precio');
rejects(fn () => $builder->build(array_replace_recursive($property, ['ubicacion' => ['latitud' => 100]]), 'silver'), 'coordenadas');
rejects(fn () => $builder->build(array_replace_recursive($property, ['ubicacion' => ['barrio' => 'No existe']]), 'silver'), 'coincidencia exacta');
rejects(fn () => $builder->build(array_replace($property, ['tipo_inmueble' => 'Desconocido']), 'silver'), 'coincidencia exacta');
$client->definitions[] = ['id' => 'ROOMS', 'name' => 'Ambientes', 'tags' => ['required' => true]];
rejects(fn () => $builder->build($property, 'silver'), 'ROOMS');
$withRooms = $property + ['source_payload' => json_encode(['ambientes' => 4])];
check(count(array_filter($builder->build($withRooms, 'silver')['attributes'], fn ($a) => $a['id'] === 'ROOMS' && $a['value_name'] === '4')) === 1, 'Ambientes proviene del dato explicito');
array_pop($client->definitions);
$client->definitions[] = ['id'=>'PROPERTY_AGE','name'=>'Antiguedad','value_type'=>'number_unit','default_unit'=>'anos','tags'=>['required'=>true]];
$ageAttributes = $builder->build($property, 'silver')['attributes'];
check(count(array_filter($ageAttributes, fn ($a) => $a['id'] === 'PROPERTY_AGE' && $a['value_name'] === '8 anos')) === 1, 'Edad provisional con unidad del catalogo');
$ageAttributes = $builder->build($property + ['ano_construccion'=>(int) date('Y')-12], 'silver')['attributes'];
check(count(array_filter($ageAttributes, fn ($a) => $a['id'] === 'PROPERTY_AGE' && $a['value_name'] === '12 anos')) === 1, 'Edad real reemplaza provisional en payload');
array_pop($client->definitions);
check(Sync::desiredAction($property, []) === 'publish', 'Nuevo inmueble se publica');
check(Sync::desiredAction($property, ['external_id' => 'MCO123']) === 'update', 'Existente no se duplica');
check(Sync::desiredAction(array_replace($property, ['estado' => 'no_disponible']), ['external_id' => 'MCO123']) === 'pause', 'No disponible se pausa');
check(Sync::desiredAction($property, ['intent' => 'paused']) === 'pause', 'Pausa manual persistente');
check(Sync::desiredAction($property, ['intent' => 'deleted']) === 'delete', 'Eliminacion manual persistente');
check(!Builder::available(['estado' => 'pendiente']), 'Estado desconocido no se publica');
$hash = Builder::fingerprint($property, 'auto');
check($hash === Builder::fingerprint(array_replace($property, ['updated_at' => '2030-01-01', 'proppit' => ['status' => 'pending']]), 'auto'), 'Cambios de otros portales no reencolan');
$regenerated = $property;
$regenerated['multimedia'][0]['id'] = 999;
check($hash === Builder::fingerprint($regenerated, 'auto'), 'IDs regenerados no reencolan fotos iguales');
check($hash !== Builder::fingerprint(array_replace($property, ['precio_venta' => 600000000]), 'auto'), 'Cambio de precio se detecta');
check($hash !== Builder::fingerprint($property, 'paused'), 'Cambio de intencion se detecta');
$pack = ['status' => 'active', 'category_id' => 'MCO_ROOT', 'package_content' => 'publications',
    'date_expires' => '2099-01-01', 'listing_details' => [['listing_type_id' => 'silver', 'remaining_listings' => 105],
    ['listing_type_id' => 'gold', 'remaining_listings' => 30], ['listing_type_id' => 'gold_premium', 'remaining_listings' => 15]]];
check(array_sum(Sync::availablePacks([$pack], 'MCO_ROOT')) === 150, 'Paquete mixto de 150');
check(Sync::availablePacks([array_replace($pack, ['date_expires' => '2000-01-01'])], 'MCO_ROOT') === [], 'No usa paquetes vencidos');
check(Sync::availablePacks([array_replace($pack, ['package_content' => 'upgrades'])], 'MCO_ROOT') === [], 'No consume paquetes de upgrades');
check(Sync::availablePacks([$pack], 'MCO_OTHER') === [], 'No consume cupo de otra categoria');

if (in_array('--database', $argv, true)) {
    $dsn = getenv('ML_TEST_DSN') ?: '';
    if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=portales_ml_test$/', $dsn)) {
        throw new RuntimeException('La prueba requiere ML_TEST_DSN local y base portales_ml_test.');
    }
    $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $repo = new MercadolibreRepository($pdo);
    $repo->migrate();
    $otherPdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $otherRepo = new MercadolibreRepository($otherPdo);
    check($repo->lock('worker') && !$otherRepo->lock('worker'), 'Dos workers no obtienen el mismo bloqueo');
    $repo->unlock('worker');
    check($otherRepo->lock('worker'), 'El siguiente worker toma el bloqueo liberado');
    $otherRepo->unlock('worker');
    $pdo->exec('DELETE FROM mercadolibre_ads');
    $repo->ensure(1, 'TEST-1');
    $repo->saveAccount('TEST', '123', 'TEST-ENCRYPTED-DATA', time()+3600);
    $ad = $repo->ad(1);
    $repo->manual(1, 'pause');
    check(!$repo->target($ad, 'hash', 'publish'), 'Un scan viejo no pisa una pausa concurrente');
    $ad = $repo->ad(1);
    check($ad['intent'] === 'paused', 'Pausa guardada');
    $repo->manual(1, 'publish');
    $ad = $repo->ad(1);
    $repo->target($ad, str_repeat('a',64), 'publish');
    $ad = $repo->ad(1);
    $repo->fail($ad, 'Prueba', false);
    check(count($repo->pending(10)) === 0, 'El backoff se respeta');
    $repo->manual(1, 'update');
    $ad = $repo->ad(1);
    $repo->waitQuota($ad, 'Cupo lleno');
    check((int) $repo->ad(1)['attempts'] === 0 && count($repo->pending(10)) === 0, 'Espera de cupo no consume intentos');
    $repo->manual(1, 'publish');
    $ad = $repo->ad(1);
    $repo->processing($ad);
    $repo->recoverInterrupted();
    check((int) $repo->ad(1)['uncertain'] === 1, 'Creacion interrumpida se marca incierta');
    $pdo->exec('UPDATE mercadolibre_ads SET uncertain=0');
    $ok = fn ($body) => ['success' => true, 'status' => 200, 'body' => $body];
    $remote = ['id' => 'MCO123', 'site_id' => 'MCO', 'seller_id' => 123, 'seller_custom_field' => 'TEST-1', 'status' => 'active', 'listing_type_id' => 'silver'];
    $client->replies = [
        ['GET','/users/123/items/search?sku=TEST-1',$ok(['results' => []])],
        ['GET','/users/123/classifieds_promotion_packs?package_content=publications&status=active',$ok([$pack])],
        ['POST','/items/validate',$ok([])], ['POST','/items',$ok($remote)], ['GET','/items/MCO123',$ok($remote)],
    ];
    $service = new Sync($repo, $client);
    $method = new ReflectionMethod($service, 'syncOne');
    check($method->invoke($service, $repo->ad(1), $property) === 'active', 'Crear y verificar anuncio');
    check($repo->ad(1)['external_id'] === 'MCO123', 'Guardar id remoto antes de continuar');
    $client->calls = [];
    $client->replies = [['GET','/items/MCO123',$ok($remote)], ['PUT','/items/MCO123',$ok($remote)],
        ['PUT','/items/MCO123/description',$ok([])], ['GET','/items/MCO123',$ok($remote)]];
    check($method->invoke($service, $repo->ad(1), $property) === 'active', 'Reintento con ID actualiza, no crea');
    check(count(array_filter($client->calls, fn ($call) => $call[0] === 'POST' && $call[1] === '/items')) === 0, 'Sin POST duplicado');
    $repo->manual(1, 'pause');
    $client->replies = [['GET','/items/MCO123',$ok($remote)], ['PUT','/items/MCO123',$ok([])], ['GET','/items/MCO123',$ok(array_replace($remote, ['status'=>'paused']))]];
    check($method->invoke($service, $repo->ad(1), $property) === 'paused', 'Pausar y verificar');
    $repo->manual(1, 'delete');
    $client->replies = [['GET','/items/MCO123',$ok($remote)], ['PUT','/items/MCO123',$ok([])], ['PUT','/items/MCO123',$ok([])], ['GET','/items/MCO123',$ok(array_replace($remote, ['status'=>'closed','sub_status'=>['deleted']]))]];
    check($method->invoke($service, $repo->ad(1), $property) === 'deleted', 'Cerrar, eliminar y verificar');
    $pdo->exec('UPDATE mercadolibre_ads SET external_id=NULL, uncertain=1');
    $repo->manual(1, 'publish');
    $client->replies = [['GET','/users/123/items/search?sku=TEST-1',$ok(['results'=>[]])]];
    rejects(fn () => $method->invoke($service, $repo->ad(1), $property), 'respuesta incierta');
    $ad = $repo->ad(1);
    $repo->manual(1, 'pause');
    $repo->complete($ad, 'active');
    check($repo->ad(1)['sync_status'] === 'pending' && $repo->ad(1)['desired_action'] === 'pause', 'Resultado viejo no borra nueva accion');
    $repo->savePropertyAge(1, 8);
    check((int) $repo->ad(1)['property_age'] === 8 && $repo->ad(1)['intent'] === 'paused', 'Guardar edad no republica un inmueble pausado manualmente');
    $repo->savePropertyAge(1, null);
    check($repo->ad(1)['property_age'] === null, 'Permite quitar la antiguedad individual');
    $pdo->exec('UPDATE mercadolibre_ads SET uncertain=0');
    $repo->fail($ad, 'Creacion incierta con accion concurrente', true);
    check((int) $repo->ad(1)['uncertain'] === 1 && $repo->ad(1)['desired_action'] === 'pause', 'Creacion incierta conserva proteccion tras accion concurrente');
    check($client->replies === [], 'Todas las respuestas simuladas se utilizaron');
}
echo "OK: {$checks} comprobaciones Mercado Libre. No se enviaron solicitudes reales.\n";
