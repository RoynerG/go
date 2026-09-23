<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Models\OperationsRepository;
$checks = 0;
function assertOperation(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$filters = OperationsRepository::filters(['view'=>'<script>','portal'=>'malicious','q'=>['x'],'action'=>'DROP TABLE','status'=>'other']);
assertOperation($filters === ['view'=>'queue','portal'=>'','status'=>'','action'=>'','q'=>''], 'Filtros con lista permitida');
$dsn = getenv('ML_TEST_DSN') ?: '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=portales_ml_test$/D', $dsn)) throw new RuntimeException('Usa solo ML_TEST_DSN local de pruebas.');
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TEMPORARY TABLE inmuebles (id INT,reference_id VARCHAR(100),titulo VARCHAR(255))');
$pdo->exec('CREATE TEMPORARY TABLE inmueble_ubicaciones (inmueble_id INT,barrio VARCHAR(100))');
foreach (['proppit','fincaraiz','mercadolibre'] as $portal) {
    $pdo->exec("CREATE TEMPORARY TABLE {$portal}_ads (inmueble_id INT,desired_action VARCHAR(30),sync_status VARCHAR(30),remote_status VARCHAR(30),last_error TEXT,updated_at DATETIME,next_sync_at DATETIME,next_attempt_at DATETIME,retry_count INT,attempts INT)");
    $pdo->exec("CREATE TEMPORARY TABLE {$portal}_logs (id INT,inmueble_id INT,action VARCHAR(30),success INT,error_message TEXT,created_at DATETIME,http_status INT)");
}
$insert = $pdo->prepare('INSERT INTO inmuebles VALUES (?,?,?)');
for ($i=1; $i<=85; $i++) {
    $insert->execute([$i,'REF-'.$i,$i === 85 ? '<script>alert(1)</script>' : 'Inmueble '.$i]);
    $pdo->exec("INSERT INTO inmueble_ubicaciones VALUES ({$i},'Bocagrande')");
    $status = $i === 1 ? 'processing' : ($i === 2 ? 'failed' : 'pending');
    $pdo->exec("INSERT INTO mercadolibre_ads VALUES ({$i},'publish','{$status}','not_sent',NULL,'2026-09-20 12:00:00',NULL,NULL,0,0)");
    $pdo->exec("INSERT INTO mercadolibre_logs VALUES ({$i},{$i},'publish',1,NULL,'2026-09-20 12:00:00',201)");
}
$pdo->exec("INSERT INTO fincaraiz_ads VALUES (2,'update','failed','active','Falta foto','2026-09-20 12:00:00',NULL,NULL,2,0)");
$pdo->exec("INSERT INTO proppit_logs VALUES (1,999,'delete_ad',1,NULL,'2026-09-21 12:00:00',200)");
$repo = new OperationsRepository($pdo);
$page = $repo->page([]);
assertOperation($page['total'] === 86 && count($page['items']) === 20 && $page['pages'] === 5, 'Paginacion real, no limitada a recientes');
assertOperation($page['items'][0]['sync_status'] === 'processing', 'Lo que se procesa aparece primero');
$last = $repo->page(['page'=>999]);
assertOperation($last['page'] === 5 && count($last['items']) === 6, 'Pagina fuera de rango acotada');
assertOperation($repo->page(['q'=>'REF-85'])['total'] === 1, 'Busca fuera de los primeros ochenta');
assertOperation($repo->page(['view'=>'errors'])['total'] === 2, 'Errores de ambos portales');
assertOperation($repo->page(['view'=>'errors','portal'=>'fincaraiz'])['total'] === 1, 'Filtro de portal en errores');
assertOperation($repo->page(['action'=>'update'])['total'] === 1, 'Filtro de accion');
assertOperation($repo->page(['status'=>'processing'])['total'] === 1, 'Filtro de procesamiento');
assertOperation($repo->page(['q'=>"' OR 1=1 --"])['total'] === 0, 'Busqueda parametrizada');
assertOperation($repo->page(['q'=>'%'])['total'] === 0, 'Busqueda literal, no comodin inesperado');
$history = $repo->page(['view'=>'history']);
assertOperation($history['total'] === 86, 'Historial completo');
assertOperation($history['items'][0]['titulo'] === null && $history['items'][0]['desired_action'] === 'pause', 'Historial conserva inmueble retirado y traduce delete_ad');
assertOperation($repo->page(['view'=>'history','status'=>'failed'])['total'] === 0, 'Errores actuales no se confunden con errores historicos');
$activity = $repo->page(['q'=>'REF-85']);
ob_start(); require dirname(__DIR__).'/app/Views/panel/activity-results.php'; $html=ob_get_clean();
assertOperation(!str_contains($html,'<script>alert') && str_contains($html,'&lt;script&gt;'), 'Contenido del inmueble escapado');
$pdo->exec("INSERT INTO proppit_ads VALUES (3,'delete','pending','published',NULL,'2026-09-20 12:00:00',NULL,NULL,0,0)");
assertOperation($repo->page(['action'=>'pause','portal'=>'proppit'])['total'] === 1, 'Despublicar agrupa delete de Proppit sin confundirlo con eliminar ML');
echo "OK: {$checks} comprobaciones de operaciones. Solo tablas temporales locales.\n";
