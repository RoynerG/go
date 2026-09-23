<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Portales Go</title>
  <link rel="icon" href="https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Url::to('/assets.css')) ?>?v=20260922-ops3">
</head>
<body class="portal-page">
<?php
$pagination = $pagination ?? ['page' => 1, 'per_page' => 12, 'total' => count($rows), 'total_pages' => 1];
$total = (int) (($pagination['total'] ?? null) ?: count($rows));
$user = \App\Core\Auth::user();
$csrf = \App\Core\Auth::csrf();
$filters = $filters ?? [];
$flash = $flash ?? null;
$options = $options ?? ['tipos' => [], 'categorias' => [], 'destinaciones' => [], 'barrios' => [], 'proppit_estados' => [], 'fincaraiz_estados' => []];
$operation = $operation ?? ['queue' => [], 'logs' => []];
$portalOps = $operation['portals'] ?? [];
$queue = $portalOps['proppit']['queue'] ?? $operation['queue'] ?? [];
$fincaraizQueue = $portalOps['fincaraiz']['queue'] ?? [];
$mlQueue = $portalOps['mercadolibre']['queue'] ?? [];
$stateLabel = [\App\Core\PortalDisplay::class, 'state'];
$mlReady = \App\Services\MercadolibreClient::configured() && !empty($operation['mercadolibre_connected']) && \App\Core\Env::bool('MERCADOLIBRE_ENABLED');
$fincaraizQuota = (int) ($fincaraizQueue['quota'] ?? (\App\Core\Env::get('FINCARAIZ_QUOTA', '50') ?: 50));
$fincaraizQuotaUsed = (int) ($fincaraizQueue['quota_used'] ?? $fincaraizQueue['published'] ?? 0);
$statsTotal = (int) ($operation['inventory']['total'] ?? $total);
$statsAvailable = (int) ($operation['inventory']['available'] ?? 0);
$statsPublished = (int) ($queue['published'] ?? 0);
$statsFincaraizPublished = (int) ($fincaraizQueue['published'] ?? 0);
$statsFincaraizPaused = (int) ($fincaraizQueue['paused'] ?? 0);
$statsPending = (int) ($queue['pending'] ?? 0) + (int) ($queue['processing'] ?? 0) + (int) ($fincaraizQueue['pending'] ?? 0) + (int) ($fincaraizQueue['processing'] ?? 0);
$statsErrors = (int) ($queue['errors'] ?? 0) + (int) ($fincaraizQueue['errors'] ?? 0);
$statsPending += (int) ($mlQueue['pending'] ?? 0) + (int) ($mlQueue['processing'] ?? 0);
$statsErrors += (int) ($mlQueue['failed'] ?? 0);
$activePage = $activePage ?? 'inmuebles';
$listPages = ['inmuebles', 'publicados', 'eliminados', 'errores'];
$isListPage = in_array($activePage, $listPages, true);
$pageTitles = [
  'inmuebles' => 'Inmuebles',
  'operaciones' => 'Operaciones',
  'cola-cron' => 'Cola de publicaciones',
  'logs' => 'Historial de operaciones',
  'automatizacion' => 'Conexiones',
  'estados' => 'Estados y errores',
  'publicados' => 'Inmuebles publicados',
  'eliminados' => 'Inmuebles eliminados',
  'errores' => 'Inmuebles con error',
];
$pageTitle = $pageTitles[$activePage] ?? 'Inmuebles disponibles';
$listRoute = [
  'inmuebles' => '/panel/inmuebles',
  'publicados' => '/panel/publicados',
  'eliminados' => '/panel/eliminados',
  'errores' => '/panel/errores',
][$activePage] ?? '/panel/inmuebles';
$activeClass = fn (string $page): string => $activePage === $page ? ' class="is-active"' : '';
$selected = fn (string $key, string $value): string => (($filters[$key] ?? '') === $value) ? ' selected' : '';
$currentUrl = (string) ($_SERVER['REQUEST_URI'] ?? \App\Core\Url::to('/panel/inmuebles'));
$pageUrl = function (int $page) use ($filters, $listRoute): string {
  $query = array_filter($filters, fn ($value) => $value !== '');
  $query['page'] = $page;
  return \App\Core\Url::to($listRoute) . '?' . http_build_query($query);
};
$portalUrl = function (string $portal) use ($filters, $listRoute): string {
  $query = array_filter($filters, fn ($value) => $value !== '');
  unset($query['page'], $query['proppit_estado'], $query['fincaraiz_estado'], $query['proppit_action'], $query['fincaraiz_action']);
  if ($portal === '') {
    unset($query['portal']);
  } else {
    $query['portal'] = $portal;
  }
  $suffix = $query ? '?' . http_build_query($query) : '';
  return \App\Core\Url::to($listRoute) . $suffix;
};
$portalFilter = (string) ($filters['portal'] ?? '');
if ($portalFilter === '' && in_array($activePage, ['publicados', 'eliminados', 'errores'], true)) {
  $portalFilter = 'proppit';
}
$actionPortal = in_array($portalFilter, ['fincaraiz','mercadolibre'], true) ? $portalFilter : 'proppit';
$actionPortalLabel = ['fincaraiz' => 'Finca Raiz', 'mercadolibre' => 'Mercado Libre', 'proppit' => 'Proppit'][$actionPortal];
$from = $pagination['total'] > 0 ? (($pagination['page'] - 1) * $pagination['per_page']) + 1 : 0;
$to = min($pagination['total'], $pagination['page'] * $pagination['per_page']);
?>
  <main class="portal-layout" data-operation-url="<?= htmlspecialchars(\App\Core\Url::to('/panel/operacion/estado') . ($activePage === 'operaciones' ? '?' . http_build_query(array_merge($activity['filters'], ['activity'=>1,'page'=>$activity['page']])) : '')) ?>" data-property-list-url="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles')) ?>">
    <aside class="portal-sidebar">
      <nav class="side-menu" aria-label="Menu del panel">
        <div class="side-brand">
          <span class="brand-logo"><img src="https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png" alt="Go Cartagena"></span>
          <strong>Portales Go</strong>
          <span>Control de portales</span>
        </div>
        <p class="side-label">General</p>
        <a<?= $activeClass('inmuebles') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles')) ?>">Panel de propiedades</a>
        <a<?= $activeClass('operaciones') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/operaciones')) ?>">Operaciones</a>
        <p class="side-label">Sistema</p>
        <a<?= $activeClass('automatizacion') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/automatizacion')) ?>">Conexiones</a>
      </nav>
      <section class="sidebar-status">
        <strong>Colas activas</strong>
        <?php foreach (['proppit'=>'Proppit','fincaraiz'=>'Finca Raiz','mercadolibre'=>'Mercado Libre'] as $portal=>$label): ?>
        <span><?= $label ?>: <b data-operation-metric="<?= $portal ?>:pending"><?= (int) ($portalOps[$portal]['queue']['pending'] ?? 0) ?></b> en espera · <b data-operation-metric="<?= $portal ?>:failed"><?= (int) ($portalOps[$portal]['queue']['failed'] ?? 0) ?></b> con error</span>
        <?php endforeach; ?>
      </section>
    </aside>

    <section class="portal-content">
      <header class="portal-header">
        <div class="top-title">
          <span>Propiedades</span>
          <strong><?= htmlspecialchars($pageTitle) ?></strong>
        </div>
        <div class="top-actions">
          <a class="queue-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/operaciones')) ?>">Operaciones</a>
          <button class="theme-toggle" type="button" data-theme-toggle aria-label="Cambiar tema">Modo</button>
          <div class="account-pill"><?= htmlspecialchars((string) ($user['name'] ?? 'Administrador')) ?></div>
          <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/logout')) ?>">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
            <button class="logout-link" type="submit">Cerrar sesion</button>
          </form>
        </div>
      </header>

      <div class="portal-title-row">
        <div>
          <h2><?= htmlspecialchars($pageTitle) ?><?= $isListPage ? ': ' . $total : '' ?></h2>
          <?php if ($isListPage): ?>
            <p><?= $from ?>-<?= $to ?> de <?= (int) $pagination['total'] ?> inmuebles · <?= $portalFilter === '' ? 'Todos los portales' : htmlspecialchars($actionPortalLabel) ?></p>
          <?php else: ?>
            <p>Resumen operativo de Proppit, Finca Raiz y Mercado Libre.</p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isListPage): ?>
      <section class="stats-grid" aria-label="Resumen de portales">
        <article>
          <span>Inmuebles disponibles</span>
          <strong data-inventory-available><?= $statsAvailable ?></strong><small><?= $statsTotal ?> registros en total</small>
        </article>
        <article>
          <span>Publicados Proppit</span>
          <strong data-operation-metric="proppit:published"><?= $statsPublished ?></strong>
        </article>
        <article>
          <span>Activos Finca Raiz</span>
          <strong><span data-operation-metric="fincaraiz:published"><?= $statsFincaraizPublished ?></span> / <?= $fincaraizQuota ?></strong>
          <small>Cupo contratado · Estado registrado</small>
        </article>
        <article>
          <span>Publicados Mercado Libre</span>
          <strong data-operation-metric="mercadolibre:published"><?= (int) ($mlQueue['published'] ?? 0) ?></strong><small><?= $mlReady ? 'Sincronizacion habilitada' : 'Conexion pendiente o desactivada' ?></small>
        </article>
        <article>
          <span>En espera y procesando</span>
          <strong data-operation-pending><?= $statsPending ?></strong>
        </article>
        <article>
          <span>Errores en portales</span>
          <strong data-operation-errors><?= $statsErrors ?></strong>
        </article>
      </section>
      <?php endif; ?>

      <?php if (($isListPage && $portalFilter === 'mercadolibre') || $activePage === 'automatizacion'): ?>
        <?php require __DIR__ . '/mercadolibre.php'; ?>
      <?php endif; ?>

      <?php if ($isListPage): ?>
      <form class="admin-filter-panel" method="get" action="<?= htmlspecialchars(\App\Core\Url::to($listRoute)) ?>">
        <div class="quick-filter-row">
          <label class="search-field">
            <span>Buscar</span>
            <input type="text" data-table-search name="direccion" value="<?= htmlspecialchars((string) ($filters['direccion'] ?? '')) ?>" placeholder="Codigo, barrio, direccion o tipo...">
          </label>
          <label>
            <span>Codigo</span>
            <input type="text" name="codigo" value="<?= htmlspecialchars((string) ($filters['codigo'] ?? '')) ?>" placeholder="Codigo">
          </label>
          <label>
            <span>Disponibilidad</span>
            <select name="disponibilidad"><option value="">Todas</option><option value="disponible"<?= $selected('disponibilidad','disponible') ?>>Disponible</option><option value="no_disponible"<?= $selected('disponibilidad','no_disponible') ?>>No disponible</option></select>
          </label>
          <button class="filter-submit" type="submit">Buscar</button>
          <a class="refresh-button" href="<?= htmlspecialchars($currentUrl) ?>" aria-label="Actualizar">Actualizar</a>
        </div>
        <div class="filter-grid">
          <label>
            <span>Portal</span>
            <select name="portal" data-portal-select>
              <?php if ($activePage === 'inmuebles'): ?>
                <option value=""<?= $selected('portal', '') ?>>Todos los portales</option>
              <?php endif; ?>
              <option value="proppit"<?= $portalFilter === 'proppit' ? ' selected' : '' ?>>Proppit</option>
              <option value="fincaraiz"<?= $portalFilter === 'fincaraiz' ? ' selected' : '' ?>>Finca Raiz</option>
              <option value="mercadolibre"<?= $portalFilter === 'mercadolibre' ? ' selected' : '' ?>>Mercado Libre</option>
            </select>
          </label>
          <label <?= $portalFilter === '' ? 'hidden' : '' ?>>
            <span>Marcado en portal</span>
            <select name="marcado">
              <option value="">Cualquier estado</option>
              <option value="si"<?= $selected('marcado', 'si') ?>>Si</option>
              <option value="no"<?= $selected('marcado', 'no') ?>>No</option>
            </select>
          </label>
          <label data-portal-filter="proppit" <?= !in_array($portalFilter, ['', 'proppit'], true) ? 'hidden' : '' ?>>
            <span>Estado Proppit</span>
            <select name="proppit_estado">
              <option value="">Cualquier estado registrado</option>
              <?php foreach ($options['proppit_estados'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('proppit_estado', $option) ?>><?= htmlspecialchars($stateLabel($option)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if ($portalFilter === 'mercadolibre'): ?>
          <label><span>Estado Mercado Libre</span><select name="mercadolibre_estado">
            <option value="">Todos</option>
            <?php foreach (['not_sent' => 'Sin publicar','active' => 'Publicado','paused' => 'Pausado','closed' => 'Finalizado','deleted' => 'Eliminado'] as $value => $label): ?>
              <option value="<?= $value ?>"<?= $selected('mercadolibre_estado', $value) ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select></label>
          <label><span>Cola Mercado Libre</span><select name="mercadolibre_cola">
            <option value="">Todos</option>
            <?php foreach (['pending' => 'En espera','processing' => 'Procesando','synced' => 'Confirmado','failed' => 'Con error'] as $value => $label): ?>
              <option value="<?= $value ?>"<?= $selected('mercadolibre_cola', $value) ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select></label>
          <?php endif; ?>
          <label>
            <span>Tipo</span>
            <select name="tipo_inmueble">
              <option value="">Todos</option>
              <?php foreach ($options['tipos'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('tipo_inmueble', $option) ?>><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Gestion</span>
            <select name="destinacion">
              <option value="">Todas</option>
              <?php foreach ($options['destinaciones'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('destinacion', $option) ?>><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Categoria</span>
            <select name="categoria">
              <option value="">Todas</option>
              <?php foreach ($options['categorias'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('categoria', $option) ?>><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Barrio</span>
            <select name="barrio">
              <option value="">Todos</option>
              <?php foreach ($options['barrios'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('barrio', $option) ?>><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label data-portal-filter="fincaraiz" <?= !in_array($portalFilter, ['', 'fincaraiz'], true) ? 'hidden' : '' ?>>
            <span>Estado Finca Raiz</span>
            <select name="fincaraiz_estado">
              <option value="">Todos</option>
              <?php foreach ($options['fincaraiz_estados'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('fincaraiz_estado', $option) ?>><?= htmlspecialchars($stateLabel($option)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <a class="clear-filters" href="<?= htmlspecialchars(\App\Core\Url::to($listRoute)) ?>">Limpiar filtros</a>
        </div>
      </form>

      <?php if ($portalFilter !== ''): ?>
      <section class="queue-toolbar">
        <strong>Pendientes de <?= htmlspecialchars($actionPortalLabel) ?></strong>
        <a class="refresh-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/cola-cron')) ?>">Ver cola y errores</a>
        <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/' . $actionPortal . '/procesar-cola')) ?>" data-ajax-queue data-action-label="<?= htmlspecialchars('procesar ' . $actionPortalLabel) ?>">
          <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="limit" value="20">
          <button type="submit" <?= $actionPortal === 'mercadolibre' && (!$mlReady) ? 'disabled' : '' ?>>Procesar pendientes</button>
        </form>
      </section>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($activePage === 'operaciones'): ?>
        <?php require __DIR__ . '/portal-overview.php'; ?>
        <?php require __DIR__ . '/operations.php'; ?>
      <?php endif; ?>

      <?php if ($activePage === 'automatizacion'): ?>
        <section class="cron-configuration">
          <div class="section-heading"><h2>Ejecucion programada</h2><a class="refresh-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/cola-cron')) ?>">Ver trabajos pendientes</a></div>
          <dl class="connection-facts">
            <div><dt>Script de todos los portales</dt><dd><code>cron/portales-sync.php</code></dd></div>
            <div><dt>Frecuencia recomendada en hosting</dt><dd>Cada minuto</dd></div>
            <div><dt>Programacion en hosting</dt><dd>No verificada desde el panel</dd></div>
          </dl>
        </section>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="panel-flash panel-flash-<?= htmlspecialchars((string) ($flash['type'] ?? 'info')) ?>">
          <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
        </div>
      <?php endif; ?>

      <?php if ($isListPage && !$rows): ?>
        <div class="portal-empty">
          <strong>Todavia no hay inmuebles guardados.</strong>
          <span>Cuando WordPress envie el primer formulario, apareceran aqui como tarjetas.</span>
        </div>
      <?php elseif ($isListPage): ?>
        <div class="property-table-wrap" id="inmuebles">
          <table class="property-table">
            <thead>
              <tr>
                <th>Codigo</th>
                <th>Inmueble</th>
                <th>Precio</th>
                <th>Estado</th>
                <th>Portales</th>
                <th>Promo</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $portalActionLabels = [
                'publish' => 'Publicar',
                'update' => 'Actualizar',
                'delete' => 'Eliminar / despublicar',
                'pause' => 'Despublicar',
                'activate' => 'Activar',
                'verify' => 'Verificar',
              ];
              $portalActionText = function (?string $action, ?string $syncStatus) use ($portalActionLabels): string {
                $action = trim((string) $action);
                $syncStatus = trim((string) $syncStatus);
                $label = $portalActionLabels[$action] ?? ($action !== '' ? ucfirst($action) : 'Sin accion');
                return match ($syncStatus) {
                  'pending' => 'En cola: ' . $label,
                  'processing' => 'Procesando: ' . $label,
                  'failed' => 'Fallo: ' . $label,
                  default => $action !== '' ? 'Ultima: ' . $label : 'Sin cola',
                };
              };
              $isMarked = (int) ($row['publicar_proppit'] ?? 0) === 1;
              $isFincaraizMarked = (int) ($row['publicar_fincaraiz'] ?? 0) === 1;
              $isUnavailable = (string) ($row['estado'] ?? '') === 'no_disponible';
              $isBoosted = (int) ($row['is_boosted'] ?? 0) === 1;
              $isExclusive = (int) ($row['is_exclusive'] ?? 0) === 1;
              $proppitAction = (string) ($row['desired_action'] ?? '');
              $proppitSync = (string) ($row['sync_status'] ?? 'pending');
              $proppitRemote = (string) ($row['remote_status'] ?? 'not_sent');
              $fincaraizAction = (string) ($row['fincaraiz_desired_action'] ?? '');
              $fincaraizSync = (string) ($row['fincaraiz_sync_status'] ?? 'pending');
              $fincaraizRemote = (string) ($row['fincaraiz_remote_status'] ?? 'not_sent');
              $rowActionPortal = $actionPortal;
              $rowPortalLabel = $rowActionPortal === 'fincaraiz' ? 'Finca Raiz' : 'Proppit';
              $rowPublishPath = $rowActionPortal === 'fincaraiz'
                ? '/panel/inmuebles/' . $row['id'] . '/fincaraiz/publicar'
                : '/panel/inmuebles/' . $row['id'] . '/publicar';
              $rowUpdatePath = $rowActionPortal === 'fincaraiz'
                ? '/panel/inmuebles/' . $row['id'] . '/fincaraiz/actualizar'
                : '/panel/inmuebles/' . $row['id'] . '/actualizar';
              $rowUnpublishPath = $rowActionPortal === 'fincaraiz'
                ? '/panel/inmuebles/' . $row['id'] . '/fincaraiz/despublicar'
                : '/panel/inmuebles/' . $row['id'] . '/despublicar';
              $rowPublishLabel = $rowActionPortal === 'fincaraiz' ? 'publicar finca raiz' : 'publicar';
              $rowUpdateLabel = $rowActionPortal === 'fincaraiz' ? 'actualizar finca raiz' : 'actualizar';
              $rowUnpublishLabel = $rowActionPortal === 'fincaraiz' ? 'despublicar finca raiz' : 'despublicar';
              if ($rowActionPortal === 'mercadolibre') {
                $rowPortalLabel = 'Mercado Libre';
                $rowPublishPath = '/panel/inmuebles/' . $row['id'] . '/mercadolibre/publicar';
                $rowUpdatePath = '/panel/inmuebles/' . $row['id'] . '/mercadolibre/actualizar';
                $rowUnpublishPath = '/panel/inmuebles/' . $row['id'] . '/mercadolibre/despublicar';
                $rowPublishLabel = 'publicar mercado libre';
                $rowUpdateLabel = 'actualizar mercado libre';
                $rowUnpublishLabel = 'despublicar mercado libre';
              }
              $rowPublishButtonText = $rowActionPortal === 'fincaraiz' && (string) ($row['fincaraiz_remote_status'] ?? '') === 'disabled'
                ? 'ACTIVAR EN ' . strtoupper($rowPortalLabel)
                : 'PUBLICAR EN ' . strtoupper($rowPortalLabel);
              $operationLabel = ((float) ($row['precio_arriendo'] ?? 0) > 0 && (float) ($row['precio_venta'] ?? 0) <= 0) ? 'Arriendo' : 'Venta';
              $price = (float) ($row['precio_venta'] ?: $row['precio_arriendo'] ?: 0);
              $image = $row['portada_url'] ?: 'https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png';
              $detail = [
                'id' => (int) $row['id'],
                'title' => (string) $row['titulo'],
                'code' => (string) $row['reference_id'],
                'barrio' => (string) ($row['barrio'] ?? ''),
                'direccion' => (string) ($row['direccion'] ?? ''),
                'price' => $price > 0 ? '$' . number_format($price, 0, ',', '.') : 'Sin precio',
                'image' => (string) $image,
                'marked' => $isMarked ? 'Marcado en portal' : 'No marcado en portal',
                'fincaraizMarked' => $isFincaraizMarked ? 'Marcado Finca Raiz' : 'No marcado Finca Raiz',
                'proppitAction' => $proppitAction,
                'proppitActionText' => $portalActionText($proppitAction, $proppitSync),
                'fincaraizAction' => $fincaraizAction,
                'fincaraizActionText' => $portalActionText($fincaraizAction, $fincaraizSync),
                'fincaraizSyncStatus' => $fincaraizSync,
                'fincaraizRemoteStatus' => $fincaraizRemote,
                'habitaciones' => (int) ($row['habitaciones'] ?? 0),
                'banos' => (int) ($row['banos'] ?? 0),
                'area' => number_format((float) ($row['area_construida'] ?: $row['area_privada'] ?: 0), 0, ',', '.'),
                'parqueaderos' => (int) ($row['parqueaderos'] ?? 0),
                'estrato' => (string) ($row['estrato'] ?? ''),
                'boosted' => $isBoosted ? 'Destacado activo' : 'Destacado inactivo',
                'exclusive' => $isExclusive ? 'Exclusivo activo' : 'Exclusivo inactivo',
                'features' => array_values(array_map(fn ($item) => (string) $item['valor'], $row['caracteristicas'] ?? [])),
                'contacts' => array_values(array_map(fn ($item) => [
                  'tipo' => (string) $item['tipo'],
                  'nombre' => (string) $item['nombre'],
                  'celular' => (string) $item['celular'],
                ], $row['contactos'] ?? [])),
                'media' => array_slice(array_values(array_map(fn ($item) => (string) $item['url'], $row['multimedia'] ?? [])), 0, 6),
                'fullUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id']),
                'canManage' => !$isUnavailable && $portalFilter !== '' && ($actionPortal !== 'mercadolibre' || $mlReady),
                'actionPortal' => $rowActionPortal,
                'actionPortalLabel' => $rowPortalLabel,
                'publishUrl' => \App\Core\Url::to($rowPublishPath),
                'updateUrl' => \App\Core\Url::to($rowUpdatePath),
                'unpublishUrl' => \App\Core\Url::to($rowUnpublishPath),
                'boostedUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/destacado'),
                'exclusiveUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/exclusivo'),
                'fincaraizPublishUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/publicar'),
                'fincaraizUpdateUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/actualizar'),
                'fincaraizUnpublishUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/despublicar'),
                'fincaraizVerifyUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/verificar'),
              ];
            ?>
              <tr data-property-id="<?= (int) $row['id'] ?>" data-row-search="<?= htmlspecialchars(strtolower((string) $row['reference_id'] . ' ' . (string) $row['titulo'] . ' ' . (string) ($row['barrio'] ?? '') . ' ' . (string) ($row['direccion'] ?? '') . ' ' . (string) ($row['tipo_inmueble'] ?? ''))) ?>">
                <td class="check-cell"><?= htmlspecialchars((string) $row['reference_id']) ?></td>
                <td>
                  <div class="table-property">
                    <img src="<?= htmlspecialchars((string) $image) ?>" alt="<?= htmlspecialchars((string) $row['titulo']) ?>" loading="lazy" decoding="async">
                    <div>
                      <strong><?= htmlspecialchars((string) $row['titulo']) ?></strong>
                      <span><?= htmlspecialchars($operationLabel) ?> · ID <?= htmlspecialchars((string) $row['reference_id']) ?></span>
                      <small><strong><?= htmlspecialchars((string) ($row['barrio'] ?: 'Sin barrio')) ?></strong> · <?= htmlspecialchars((string) ($row['direccion'] ?: 'Sin direccion')) ?></small>
                      <button class="link-button" type="button" data-detail="<?= htmlspecialchars(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>">Ver ficha</button>
                    </div>
                  </div>
                </td>
                <td>
                  <strong><?= $price > 0 ? '$' . number_format($price, 0, ',', '.') : 'Sin precio' ?></strong>
                  <span class="table-mini"><?= (int) ($row['habitaciones'] ?? 0) ?> Hab · <?= (int) ($row['banos'] ?? 0) ?> Ba · <?= number_format((float) ($row['area_construida'] ?: $row['area_privada'] ?: 0), 0, ',', '.') ?> m²</span>
                </td>
                <td>
                  <span class="table-mini"><?= $isUnavailable ? 'No disponible' : 'Disponible' ?></span>
                </td>
                <td class="portal-chip-cell">
                  <div class="portal-status-stack">
                    <div class="portal-status-row is-proppit" <?= !in_array($portalFilter, ['', 'proppit'], true) ? 'hidden' : '' ?>>
                      <div>
                        <strong>Proppit</strong>
                        <span data-card-proppit-action><?= htmlspecialchars($portalActionText($proppitAction, $proppitSync)) ?></span>
                      </div>
                      <div class="portal-status-tags">
                        <span class="table-pill table-pill-dark" data-card-remote data-state="<?= htmlspecialchars($proppitRemote) ?>"><?= htmlspecialchars($stateLabel($proppitRemote)) ?></span>
                        <span class="table-pill" data-card-proppit-sync data-state="<?= htmlspecialchars($proppitSync) ?>"><?= htmlspecialchars($stateLabel($proppitSync)) ?></span>
                      </div>
                    </div>
                    <div class="portal-status-row is-mercadolibre" <?= !in_array($portalFilter, ['', 'mercadolibre'], true) ? 'hidden' : '' ?>>
                      <div><strong>Mercado Libre</strong><span data-card-ml-action><?= htmlspecialchars($portalActionText($row['mercadolibre_desired_action'] ?? '', $row['mercadolibre_sync_status'] ?? '')) ?></span></div>
                      <div class="portal-status-tags">
                        <span class="table-pill" data-card-ml-remote data-state="<?= htmlspecialchars($row['mercadolibre_remote_status'] ?? 'not_sent') ?>"><?= htmlspecialchars($stateLabel($row['mercadolibre_remote_status'] ?? 'not_sent')) ?></span>
                        <span class="table-pill" data-card-ml-sync data-state="<?= htmlspecialchars($row['mercadolibre_sync_status'] ?? '') ?>"><?= htmlspecialchars($stateLabel($row['mercadolibre_sync_status'] ?? '')) ?></span>
                      </div>
                      <p class="property-error" data-card-ml-error <?= empty($row['mercadolibre_last_error']) ? 'hidden' : '' ?>><?= htmlspecialchars($row['mercadolibre_last_error'] ?? '') ?></p>
                    </div>
                    <div class="portal-status-row is-fincaraiz" <?= !in_array($portalFilter, ['', 'fincaraiz'], true) ? 'hidden' : '' ?>>
                      <div>
                        <strong>Finca Raiz</strong>
                        <span data-card-fr-action><?= htmlspecialchars($portalActionText($fincaraizAction, $fincaraizSync)) ?></span>
                      </div>
                      <div class="portal-status-tags">
                        <span class="table-pill table-pill-fr" data-card-fr-remote data-state="<?= htmlspecialchars($fincaraizRemote) ?>"><?= htmlspecialchars($stateLabel($fincaraizRemote)) ?></span>
                        <span class="table-pill" data-card-fr-sync data-state="<?= htmlspecialchars($fincaraizSync) ?>"><?= htmlspecialchars($stateLabel($fincaraizSync)) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="property-error table-error" data-card-error <?= $row['last_error'] ? '' : 'hidden' ?>><?= htmlspecialchars((string) ($row['last_error'] ?? '')) ?></div>
                  <div class="property-error table-error" data-card-fr-error <?= $row['fincaraiz_last_error'] ? '' : 'hidden' ?>><?= htmlspecialchars((string) ($row['fincaraiz_last_error'] ?? '')) ?></div>
                </td>
                <td>
                  <?php if ($rowActionPortal === 'mercadolibre'): ?>
                  <span data-card-ml-type><?= htmlspecialchars(['silver' => 'Plata','gold' => 'Oro','gold_premium' => 'Oro Premium'][$row['mercadolibre_listing_type'] ?? ''] ?? ($row['mercadolibre_listing_type'] ?: 'Sin asignar')) ?></span>
                  <?php elseif ($rowActionPortal === 'fincaraiz'): ?>
                  <span class="table-mini">No aplica</span>
                  <?php else: ?>
                  <small class="table-mini">Proppit</small>
                  <span data-card-boosted><?= $isBoosted ? 'Destacado: activo' : 'Destacado: inactivo' ?></span><br>
                  <span data-card-exclusive><?= $isExclusive ? 'Exclusivo: activo' : 'Exclusivo: inactivo' ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="property-actions table-actions">
                  <?php if ($portalFilter === ''): ?>
                    <?php foreach (['proppit'=>'Proppit','fincaraiz'=>'Finca Raiz','mercadolibre'=>'Mercado Libre'] as $portal=>$label): ?>
                      <a class="refresh-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles') . '?' . http_build_query(['portal'=>$portal,'codigo'=>$row['reference_id']])) ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                  <?php elseif ($rowActionPortal === 'mercadolibre' && !$mlReady): ?>
                    <a class="refresh-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/automatizacion')) ?>">Completar conexion</a>
                  <?php elseif ($isUnavailable): ?>
                    <div class="unavailable-note">No disponible</div>
                  <?php else: ?>
                    <details class="row-action-menu"><summary>Gestionar <?= htmlspecialchars($rowPortalLabel) ?></summary>
                    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to($rowPublishPath)) ?>" data-ajax-action data-action-label="<?= htmlspecialchars($rowPublishLabel) ?>">
                      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                      <button type="submit"><?= htmlspecialchars($rowPublishButtonText) ?></button>
                    </form>
                    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to($rowUpdatePath)) ?>" data-ajax-action data-action-label="<?= htmlspecialchars($rowUpdateLabel) ?>">
                      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                      <button type="submit" class="update-action">ACTUALIZAR <?= htmlspecialchars(strtoupper($rowPortalLabel)) ?></button>
                    </form>
                    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to($rowUnpublishPath)) ?>" data-ajax-action data-action-label="<?= htmlspecialchars($rowUnpublishLabel) ?>" data-confirm="1">
                      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                      <button type="submit" class="secondary-action">DESPUBLICAR <?= htmlspecialchars(strtoupper($rowPortalLabel)) ?></button>
                    </form>
                    <?php if ($rowActionPortal === 'fincaraiz'): ?>
                      <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/verificar')) ?>" data-ajax-action data-action-label="verificar finca raiz">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                        <button type="submit" class="update-action">VERIFICAR FINCA</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($rowActionPortal === 'mercadolibre'): ?>
                      <form class="ml-age-form" method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/mercadolibre/antiguedad')) ?>" data-ajax-action data-action-label="guardar antiguedad">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                        <label>Antiguedad si falta en origen<input name="property_age" type="number" min="0" max="999" step="1" value="<?= htmlspecialchars((string) ($row['mercadolibre_property_age'] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) \App\Core\Env::get('MERCADOLIBRE_DEFAULT_PROPERTY_AGE', '8')) ?>" aria-label="Antiguedad para inmueble <?= htmlspecialchars($row['reference_id']) ?>"></label>
                        <button type="submit" class="update-action">Guardar antiguedad</button>
                      </form>
                      <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/mercadolibre/eliminar')) ?>" data-ajax-action data-action-label="eliminar mercado libre" data-confirm="delete">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="confirm_delete" value="1">
                        <button type="submit" class="secondary-action">ELIMINAR DEFINITIVAMENTE</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($rowActionPortal === 'proppit'): ?>
                      <div class="flag-actions">
                        <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/destacado')) ?>" data-ajax-action data-action-label="destacado">
                          <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                          <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                          <button type="submit" data-card-boosted-button class="<?= $isBoosted ? 'flag-on' : '' ?>"><?= $isBoosted ? 'DESTACADO ON' : 'DESTACADO OFF' ?></button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/exclusivo')) ?>" data-ajax-action data-action-label="exclusivo">
                          <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                          <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                          <button type="submit" data-card-exclusive-button class="<?= $isExclusive ? 'flag-on' : '' ?>"><?= $isExclusive ? 'EXCLUSIVO ON' : 'EXCLUSIVO OFF' ?></button>
                        </form>
                      </div>
                    <?php endif; ?>
                    </details>
                  <?php endif; ?>
                  </div>
                </td>
              </tr>
          <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php endif; ?>

      <?php if ($isListPage): ?>
        <?php if ($pagination['total_pages'] > 1): ?>
          <nav class="pagination" aria-label="Paginacion">
            <?php if ($pagination['page'] > 1): ?>
              <a href="<?= htmlspecialchars($pageUrl($pagination['page'] - 1)) ?>">Anterior</a>
            <?php else: ?>
              <span>Anterior</span>
            <?php endif; ?>

            <?php
              $start = max(1, $pagination['page'] - 2);
              $end = min($pagination['total_pages'], $pagination['page'] + 2);
            ?>
            <?php if ($start > 1): ?>
              <a href="<?= htmlspecialchars($pageUrl(1)) ?>">1</a>
              <?php if ($start > 2): ?><span>...</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
              <?php if ($i === $pagination['page']): ?>
                <strong><?= $i ?></strong>
              <?php else: ?>
                <a href="<?= htmlspecialchars($pageUrl($i)) ?>"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end < $pagination['total_pages']): ?>
              <?php if ($end < $pagination['total_pages'] - 1): ?><span>...</span><?php endif; ?>
              <a href="<?= htmlspecialchars($pageUrl($pagination['total_pages'])) ?>"><?= $pagination['total_pages'] ?></a>
            <?php endif; ?>

            <?php if ($pagination['page'] < $pagination['total_pages']): ?>
              <a href="<?= htmlspecialchars($pageUrl($pagination['page'] + 1)) ?>">Siguiente</a>
            <?php else: ?>
              <span>Siguiente</span>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
  <div class="modal-backdrop" id="property-modal" aria-hidden="true" hidden>
    <section class="modal-card" role="dialog" aria-modal="true" aria-label="Ficha de inmueble">
      <button class="modal-close" type="button" data-close-modal>&times;</button>
      <div class="modal-media"></div>
      <div class="modal-content">
        <div class="modal-heading">
          <div>
            <span class="status-chip" data-modal-marked></span>
            <h2 data-modal-title></h2>
            <p data-modal-subtitle></p>
          </div>
          <strong data-modal-price></strong>
        </div>
        <div class="modal-specs" data-modal-specs></div>
        <div class="modal-section">
          <h3>Ubicacion</h3>
          <p data-modal-address></p>
        </div>
        <div class="modal-section">
          <h3>Caracteristicas</h3>
          <div class="feature-list" data-modal-features></div>
        </div>
        <div class="modal-section">
          <h3>Contactos</h3>
          <div data-modal-contacts></div>
        </div>
        <div class="modal-thumbs" data-modal-thumbs></div>
        <div class="modal-actions">
          <a href="#" data-modal-full>Abrir ficha completa</a>
          <div class="modal-sync-actions" data-modal-sync-actions>
            <form method="post" action="#" data-modal-publish-form data-ajax-action data-action-label="publicar">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit">Publicar</button>
            </form>
            <form method="post" action="#" data-modal-update-form data-ajax-action data-action-label="actualizar">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit">Actualizar</button>
            </form>
            <form method="post" action="#" data-modal-unpublish-form data-ajax-action data-action-label="despublicar" data-confirm="1">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit">Despublicar</button>
            </form>
            <form method="post" action="#" data-modal-boosted-form data-ajax-action data-action-label="destacado">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit" data-modal-boosted-label>Destacado</button>
            </form>
            <form method="post" action="#" data-modal-exclusive-form data-ajax-action data-action-label="exclusivo">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit" data-modal-exclusive-label>Exclusivo</button>
            </form>
            <form method="post" action="#" data-modal-fr-publish-form data-ajax-action data-action-label="publicar finca raiz">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit">Publicar FR</button>
            </form>
            <form method="post" action="#" data-modal-fr-update-form data-ajax-action data-action-label="actualizar finca raiz">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit">Actualizar FR</button>
            </form>
            <form method="post" action="#" data-modal-fr-unpublish-form data-ajax-action data-action-label="despublicar finca raiz" data-confirm="1">
              <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
              <button type="submit">Despublicar FR</button>
            </form>
          </div>
          <button type="button" data-close-modal>Cerrar</button>
        </div>
      </div>
    </section>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="<?= htmlspecialchars(\App\Core\Url::to('/panel.js')) ?>?v=20260922-ops3"></script>
</body>
</html>
