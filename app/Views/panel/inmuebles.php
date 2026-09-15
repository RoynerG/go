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
  <link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Url::to('/assets.css')) ?>?v=20260915-1">
</head>
<body class="portal-page">
<?php
$pagination = $pagination ?? ['page' => 1, 'per_page' => 12, 'total' => count($rows), 'total_pages' => 1];
$total = (int) (($pagination['total'] ?? null) ?: count($rows));
$published = count(array_filter($rows, fn ($row) => (int) ($row['publicar_proppit'] ?? 0) === 1));
$pending = count(array_filter($rows, fn ($row) => in_array(($row['sync_status'] ?? ''), ['pending', 'processing'], true) || in_array(($row['fincaraiz_sync_status'] ?? ''), ['pending', 'processing'], true)));
$failed = count(array_filter($rows, fn ($row) => ($row['sync_status'] ?? '') === 'failed' || ($row['remote_status'] ?? '') === 'error' || ($row['fincaraiz_sync_status'] ?? '') === 'failed' || ($row['fincaraiz_remote_status'] ?? '') === 'error'));
$user = \App\Core\Auth::user();
$csrf = \App\Core\Auth::csrf();
$filters = $filters ?? [];
$flash = $flash ?? null;
$options = $options ?? ['tipos' => [], 'categorias' => [], 'destinaciones' => [], 'barrios' => [], 'proppit_estados' => [], 'fincaraiz_estados' => []];
$operation = $operation ?? ['queue' => [], 'logs' => []];
$portalOps = $operation['portals'] ?? [];
$queue = $portalOps['proppit']['queue'] ?? $operation['queue'] ?? [];
$fincaraizQueue = $portalOps['fincaraiz']['queue'] ?? [];
$queueItems = $operation['queue_items'] ?? [];
$stateSummary = $operation['state_summary'] ?? ['publicados' => [], 'eliminados' => [], 'errores' => []];
$fincaraizQuota = (int) ($fincaraizQueue['quota'] ?? (\App\Core\Env::get('FINCARAIZ_QUOTA', '50') ?: 50));
$fincaraizQuotaUsed = (int) ($fincaraizQueue['quota_used'] ?? $fincaraizQueue['published'] ?? 0);
$statsTotal = (int) ($queue['total'] ?? $total);
$statsPublished = (int) ($queue['published'] ?? 0);
$statsFincaraizPublished = (int) ($fincaraizQueue['published'] ?? 0);
$statsFincaraizPaused = (int) ($fincaraizQueue['paused'] ?? 0);
$statsPending = (int) ($queue['pending'] ?? 0) + (int) ($queue['processing'] ?? 0) + (int) ($fincaraizQueue['pending'] ?? 0) + (int) ($fincaraizQueue['processing'] ?? 0);
$statsUnpublished = max(0, $statsTotal - $statsPublished);
$statsErrors = (int) ($queue['failed'] ?? 0) + (int) ($queue['remote_errors'] ?? 0) + (int) ($fincaraizQueue['failed'] ?? 0) + (int) ($fincaraizQueue['remote_errors'] ?? 0);
$logs = $operation['logs'] ?? [];
$fincaraizLogs = $portalOps['fincaraiz']['logs'] ?? [];
$combinedLogs = array_merge(
  array_map(fn ($log) => $log + ['portal' => 'proppit', 'portal_label' => 'Proppit'], $logs),
  array_map(fn ($log) => $log + ['portal' => 'fincaraiz', 'portal_label' => 'Finca Raiz'], $fincaraizLogs)
);
usort($combinedLogs, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$activePage = $activePage ?? 'inmuebles';
$listPages = ['inmuebles', 'publicados', 'eliminados', 'errores'];
$isListPage = in_array($activePage, $listPages, true);
$pageTitles = [
  'inmuebles' => 'Inmuebles disponibles',
  'cola-cron' => 'Monitor de portales',
  'logs' => 'Logs recientes',
  'automatizacion' => 'Cron automatico',
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
$actionPortal = $portalFilter === 'fincaraiz' ? 'fincaraiz' : 'proppit';
$actionPortalLabel = $actionPortal === 'fincaraiz' ? 'Finca Raiz' : 'Proppit';
$from = $pagination['total'] > 0 ? (($pagination['page'] - 1) * $pagination['per_page']) + 1 : 0;
$to = min($pagination['total'], $pagination['page'] * $pagination['per_page']);
?>
  <main class="portal-layout">
    <aside class="portal-sidebar">
      <nav class="side-menu" aria-label="Menu del panel">
        <div class="side-brand">
          <span class="brand-logo"><img src="https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png" alt="Go Cartagena"></span>
          <strong>Portales Go</strong>
          <span>Control de portales</span>
        </div>
        <p class="side-label">General</p>
        <a<?= $activeClass('inmuebles') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles')) ?>">Panel de propiedades</a>
        <a<?= $activeClass('cola-cron') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/cola-cron')) ?>">Monitor de portales</a>
        <a<?= $activeClass('estados') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/estados')) ?>">Estados y errores</a>
        <p class="side-label">Sistema</p>
        <a<?= $activeClass('logs') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/logs')) ?>">Logs recientes</a>
        <a<?= $activeClass('automatizacion') ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/automatizacion')) ?>">Cron automatico</a>
      </nav>
      <section class="sidebar-status">
        <strong>Colas activas</strong>
        <span>Proppit: <?= (int) ($queue['pending'] ?? 0) ?> pendientes · <?= (int) ($queue['failed'] ?? 0) ?> fallidos</span>
        <span>Finca Raiz: <?= (int) ($fincaraizQueue['pending'] ?? 0) ?> pendientes · <?= $statsFincaraizPublished ?>/<?= $fincaraizQuota ?> activos</span>
        <span><?= $statsFincaraizPaused ?> desactivados · <?= $fincaraizQuotaUsed ?> marcados para cupo</span>
      </section>
    </aside>

    <section class="portal-content">
      <header class="portal-header">
        <div class="top-title">
          <span>Propiedades</span>
          <strong><?= htmlspecialchars($pageTitle) ?></strong>
        </div>
        <div class="top-actions">
          <a class="queue-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/cola-cron')) ?>">Monitor</a>
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
            <p>Mostrando <?= $from ?>-<?= $to ?> de <?= (int) $pagination['total'] ?> · <?= $published ?> marcados en portal · <?= $pending ?> pendientes · <?= $failed ?> con error</p>
          <?php else: ?>
            <p>Resumen operativo de Proppit y Finca Raiz.</p>
          <?php endif; ?>
        </div>
      </div>

      <section class="stats-grid" aria-label="Resumen de portales">
        <article>
          <span>Propiedades publicas</span>
          <strong><?= $statsTotal ?></strong>
        </article>
        <article>
          <span>Publicados Proppit</span>
          <strong><?= $statsPublished ?></strong>
        </article>
        <article>
          <span>Activos Finca Raiz</span>
          <strong><?= $statsFincaraizPublished ?>/<?= $fincaraizQuota ?></strong>
          <small><?= $fincaraizQuotaUsed ?> marcados · <?= $statsFincaraizPaused ?> desactivados</small>
        </article>
        <article>
          <span>Sin publicar</span>
          <strong><?= $statsUnpublished ?></strong>
        </article>
        <article>
          <span>Portales en proceso</span>
          <strong><?= $statsPending ?></strong>
        </article>
        <article>
          <span>Errores en portales</span>
          <strong><?= $statsErrors ?></strong>
        </article>
      </section>

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
            <span>Orden</span>
            <select name="orden">
              <option value="">Mas recientes</option>
            </select>
          </label>
          <button class="filter-submit" type="submit">Buscar</button>
          <a class="refresh-button" href="<?= htmlspecialchars($currentUrl) ?>" aria-label="Actualizar">Actualizar</a>
        </div>
        <div class="filter-grid">
          <label>
            <span>Portal de accion</span>
            <select name="portal">
              <?php if ($activePage === 'inmuebles'): ?>
                <option value=""<?= $selected('portal', '') ?>>Todos los portales</option>
              <?php endif; ?>
              <option value="proppit"<?= $portalFilter === 'proppit' ? ' selected' : '' ?>>Proppit</option>
              <option value="fincaraiz"<?= $portalFilter === 'fincaraiz' ? ' selected' : '' ?>>Finca Raiz</option>
            </select>
          </label>
          <label>
            <span>Marcado en portal</span>
            <select name="marcado">
              <option value="">Cualquier estado</option>
              <option value="si"<?= $selected('marcado', 'si') ?>>Si</option>
              <option value="no"<?= $selected('marcado', 'no') ?>>No</option>
            </select>
          </label>
          <label>
            <span>Estado Proppit</span>
            <select name="proppit_estado">
              <option value="">Cualquier estado registrado</option>
              <?php foreach ($options['proppit_estados'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('proppit_estado', $option) ?>><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
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
          <label>
            <span>Estado Finca Raiz</span>
            <select name="fincaraiz_estado">
              <option value="">Todos</option>
              <?php foreach ($options['fincaraiz_estados'] as $option): ?>
                <option value="<?= htmlspecialchars($option) ?>"<?= $selected('fincaraiz_estado', $option) ?>><?= htmlspecialchars($option) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <a class="clear-filters" href="<?= htmlspecialchars(\App\Core\Url::to($listRoute)) ?>">Limpiar filtros</a>
        </div>
      </form>

      <section class="bulk-actions-panel">
        <div>
          <strong>Acciones masivas: <?= htmlspecialchars($actionPortalLabel) ?></strong>
          <span>Procesa los pendientes del portal que estas viendo.</span>
        </div>
        <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to($actionPortal === 'fincaraiz' ? '/panel/fincaraiz/procesar-cola' : '/panel/proppit/procesar-cola')) ?>" data-ajax-queue data-action-label="<?= htmlspecialchars($actionPortal === 'fincaraiz' ? 'procesar finca raiz' : 'procesar cola') ?>">
          <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
          <input type="hidden" name="limit" value="20">
          <select aria-label="Portal"><option><?= htmlspecialchars($actionPortalLabel) ?></option></select>
          <select aria-label="Accion"><option>Procesar cola</option></select>
          <select aria-label="Aplicar a"><option>Pendientes de <?= htmlspecialchars($actionPortalLabel) ?></option></select>
          <button type="submit">Ejecutar accion</button>
        </form>
        <div class="selection-bar">
          <button type="button" data-select-page>Seleccionar pagina</button>
          <span><strong data-selected-count>0</strong> seleccionados</span>
          <a href="<?= htmlspecialchars(\App\Core\Url::to('/panel/automatizacion')) ?>">Ver automatizacion</a>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($activePage === 'cola-cron'): ?>
      <section class="ops-board" id="cola-cron">
        <div class="ops-heading">
          <div>
            <h2>Proppit</h2>
            <p>Publicar, actualizar y despublicar.</p>
          </div>
          <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/proppit/procesar-cola')) ?>" data-ajax-queue data-action-label="procesar cola">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
            <input type="hidden" name="limit" value="10">
            <button type="submit">PROCESAR COLA</button>
          </form>
          <span>Ultima sync: <strong data-queue-last-sync><?= htmlspecialchars((string) ($queue['last_synced_at'] ?? 'sin registros')) ?></strong></span>
        </div>
        <div class="ops-grid">
          <article><strong data-queue-metric="pending"><?= (int) ($queue['pending'] ?? 0) ?></strong><span>Pendientes</span></article>
          <article><strong data-queue-metric="processing"><?= (int) ($queue['processing'] ?? 0) ?></strong><span>Procesando</span></article>
          <article><strong data-queue-metric="synced"><?= (int) ($queue['synced'] ?? 0) ?></strong><span>Sincronizados</span></article>
          <article><strong data-queue-metric="failed"><?= (int) ($queue['failed'] ?? 0) ?></strong><span>Fallidos</span></article>
          <article><strong data-queue-metric="publish_actions"><?= (int) ($queue['publish_actions'] ?? 0) ?></strong><span>Publicar</span></article>
          <article><strong data-queue-metric="update_actions"><?= (int) ($queue['update_actions'] ?? 0) ?></strong><span>Actualizar</span></article>
          <article><strong data-queue-metric="delete_actions"><?= (int) ($queue['delete_actions'] ?? 0) ?></strong><span>Despublicar</span></article>
          <article><strong data-queue-metric="remote_errors"><?= (int) ($queue['remote_errors'] ?? 0) ?></strong><span>Errores Proppit</span></article>
        </div>
      </section>
      <section class="ops-board">
        <div class="ops-heading">
          <div>
            <h2>Finca Raiz</h2>
            <p>Publicar, actualizar, pausar y verificar tareas.</p>
          </div>
          <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/fincaraiz/procesar-cola')) ?>" data-ajax-queue data-action-label="procesar finca raiz">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
            <input type="hidden" name="limit" value="10">
            <button type="submit">PROCESAR FINCA RAIZ</button>
          </form>
          <span>Ultima sync: <strong data-portal-queue-last-sync="fincaraiz"><?= htmlspecialchars((string) ($fincaraizQueue['last_synced_at'] ?? 'sin registros')) ?></strong></span>
        </div>
        <div class="ops-grid">
          <article><strong data-portal-queue-metric="fincaraiz:pending"><?= (int) ($fincaraizQueue['pending'] ?? 0) ?></strong><span>Pendientes</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:processing"><?= (int) ($fincaraizQueue['processing'] ?? 0) ?></strong><span>Procesando</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:synced"><?= (int) ($fincaraizQueue['synced'] ?? 0) ?></strong><span>Sincronizados</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:failed"><?= (int) ($fincaraizQueue['failed'] ?? 0) ?></strong><span>Fallidos</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:publish_actions"><?= (int) ($fincaraizQueue['publish_actions'] ?? 0) ?></strong><span>Publicar</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:update_actions"><?= (int) ($fincaraizQueue['update_actions'] ?? 0) ?></strong><span>Actualizar</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:pause_actions"><?= (int) ($fincaraizQueue['pause_actions'] ?? 0) ?></strong><span>Despublicar</span></article>
          <article><strong data-portal-queue-metric="fincaraiz:verify_actions"><?= (int) ($fincaraizQueue['verify_actions'] ?? 0) ?></strong><span>Verificar</span></article>
        </div>
      </section>
      <section class="queue-panel">
        <div class="ops-heading">
          <div>
            <h2>Inmuebles en cola</h2>
            <p>Lo que el cron va a procesar, separado por portal y accion.</p>
          </div>
        </div>
        <div class="panel-filter-strip" data-live-filter="queue">
          <label>
            <span>Buscar inmueble</span>
            <input type="search" data-filter-search placeholder="Codigo, titulo, barrio...">
          </label>
          <label>
            <span>Portal</span>
            <select data-filter-field="portal">
              <option value="">Todos</option>
              <option value="proppit">Proppit</option>
              <option value="fincaraiz">Finca Raiz</option>
            </select>
          </label>
          <label>
            <span>Accion</span>
            <select data-filter-field="action">
              <option value="">Todas</option>
              <option value="publish">Publicar</option>
              <option value="update">Actualizar</option>
              <option value="delete">Despublicar Proppit</option>
              <option value="pause">Despublicar Finca</option>
              <option value="activate">Activar Finca</option>
              <option value="verify">Verificar Finca</option>
            </select>
          </label>
          <label>
            <span>Estado cola</span>
            <select data-filter-field="status">
              <option value="">Todos</option>
              <option value="pending">Pendiente</option>
              <option value="processing">Procesando</option>
              <option value="failed">Fallido</option>
            </select>
          </label>
          <strong><span data-filter-count><?= count($queueItems) ?></span> visibles</strong>
        </div>
        <?php if ($queueItems): ?>
          <div class="queue-list">
            <?php foreach ($queueItems as $item): ?>
              <?php
                $image = $item['portada_url'] ?: 'https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png';
                $portalClass = (string) ($item['portal'] ?? '') === 'fincaraiz' ? 'is-fincaraiz' : 'is-proppit';
              ?>
              <article class="queue-item" data-filter-item data-filter-portal="<?= htmlspecialchars((string) ($item['portal'] ?? '')) ?>" data-filter-action="<?= htmlspecialchars((string) ($item['desired_action'] ?? '')) ?>" data-filter-status="<?= htmlspecialchars((string) ($item['sync_status'] ?? '')) ?>" data-filter-text="<?= htmlspecialchars(strtolower((string) ($item['reference_id'] ?? '') . ' ' . (string) ($item['titulo'] ?? '') . ' ' . (string) ($item['barrio'] ?? '') . ' ' . (string) ($item['direccion'] ?? ''))) ?>">
                <img src="<?= htmlspecialchars((string) $image) ?>" alt="<?= htmlspecialchars((string) ($item['titulo'] ?? 'Inmueble')) ?>" loading="lazy" decoding="async">
                <div>
                  <strong><?= htmlspecialchars((string) ($item['titulo'] ?? 'Inmueble sin titulo')) ?></strong>
                  <span>ID <?= htmlspecialchars((string) ($item['reference_id'] ?? '')) ?> · <?= htmlspecialchars((string) ($item['barrio'] ?: 'Sin barrio')) ?></span>
                  <small><?= htmlspecialchars((string) ($item['direccion'] ?: 'Sin direccion')) ?></small>
                </div>
                <div class="queue-meta">
                  <span class="portal-badge <?= $portalClass ?>"><?= htmlspecialchars((string) ($item['portal_label'] ?? 'Portal')) ?></span>
                  <strong><?= htmlspecialchars((string) ($item['action_label'] ?? 'Sin accion')) ?></strong>
                </div>
                <div class="queue-meta">
                  <span class="table-pill"><?= htmlspecialchars((string) ($item['sync_status'] ?? 'pending')) ?></span>
                  <span class="table-pill table-pill-dark"><?= htmlspecialchars((string) ($item['remote_status'] ?? 'not_sent')) ?></span>
                </div>
                <div class="queue-meta queue-price">
                  <strong><?= htmlspecialchars((string) ($item['price_label'] ?? 'Sin precio')) ?></strong>
                  <span><?= htmlspecialchars((string) ($item['specs_label'] ?? '')) ?></span>
                </div>
                <?php if (!empty($item['last_error'])): ?>
                  <p class="queue-error"><?= htmlspecialchars((string) $item['last_error']) ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="portal-empty compact-empty">
            <strong>No hay inmuebles pendientes en cola.</strong>
            <span>Cuando publiques, actualices o despubliques, apareceran aqui.</span>
          </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($activePage === 'automatizacion'): ?>
      <section class="ops-board">
        <div class="ops-heading">
          <div>
            <h2>Automatizacion</h2>
            <p>Estado operativo de las colas y cron configurados.</p>
          </div>
        </div>
        <div class="automation-grid">
          <article>
            <strong>Proppit</strong>
            <span><?= (int) ($queue['pending'] ?? 0) ?> pendientes · <?= (int) ($queue['failed'] ?? 0) ?> fallidos</span>
            <span>Cron web: /cron/proppit-sync</span>
            <span>Script: cron/proppit-sync.php</span>
          </article>
          <article>
            <strong>Finca Raiz</strong>
            <span><?= (int) ($fincaraizQueue['pending'] ?? 0) ?> pendientes · <?= (int) ($fincaraizQueue['failed'] ?? 0) ?> fallidos</span>
            <span>Cupo: <?= $fincaraizQuotaUsed ?>/<?= $fincaraizQuota ?> inmuebles</span>
            <span>Cliente: <?= htmlspecialchars((string) getenv('FINCARAIZ_CLIENT_ID') ?: '7750b42d-a577-11f1-8d89-06ecc7fea243') ?></span>
            <span>Cron web: /cron/fincaraiz-sync</span>
          </article>
          <article>
            <strong>Todos</strong>
            <span><?= (int) ($queue['pending'] ?? 0) + (int) ($fincaraizQueue['pending'] ?? 0) ?> pendientes totales</span>
            <span>Cron web: /cron/portales-sync</span>
            <span>API header: X-Api-Key</span>
          </article>
        </div>
        <div class="automation-flow">
          <div class="state-card-head">
            <strong>Movimiento pendiente del cron</strong>
            <span><?= count($queueItems) ?> acciones en cola</span>
          </div>
          <div class="panel-filter-strip compact-controls" data-live-filter="automation">
            <label>
              <span>Buscar</span>
              <input type="search" data-filter-search placeholder="Codigo o titulo">
            </label>
            <label>
              <span>Portal</span>
              <select data-filter-field="portal">
                <option value="">Todos</option>
                <option value="proppit">Proppit</option>
                <option value="fincaraiz">Finca Raiz</option>
              </select>
            </label>
            <label>
              <span>Accion</span>
              <select data-filter-field="action">
                <option value="">Todas</option>
                <option value="publish">Publicar</option>
                <option value="update">Actualizar</option>
                <option value="delete">Despublicar</option>
                <option value="pause">Pausar</option>
                <option value="activate">Activar</option>
                <option value="verify">Verificar</option>
              </select>
            </label>
            <strong><span data-filter-count><?= count($queueItems) ?></span> visibles</strong>
          </div>
          <?php if ($queueItems): ?>
            <div class="queue-list compact-queue">
              <?php foreach ($queueItems as $item): ?>
                <?php $portalClass = (string) ($item['portal'] ?? '') === 'fincaraiz' ? 'is-fincaraiz' : 'is-proppit'; ?>
                <article class="queue-item compact-row" data-filter-item data-filter-portal="<?= htmlspecialchars((string) ($item['portal'] ?? '')) ?>" data-filter-action="<?= htmlspecialchars((string) ($item['desired_action'] ?? '')) ?>" data-filter-status="<?= htmlspecialchars((string) ($item['sync_status'] ?? '')) ?>" data-filter-text="<?= htmlspecialchars(strtolower((string) ($item['reference_id'] ?? '') . ' ' . (string) ($item['titulo'] ?? '') . ' ' . (string) ($item['barrio'] ?? ''))) ?>">
                  <div>
                    <strong><?= htmlspecialchars((string) ($item['titulo'] ?? 'Inmueble sin titulo')) ?></strong>
                    <span>ID <?= htmlspecialchars((string) ($item['reference_id'] ?? '')) ?> · <?= htmlspecialchars((string) ($item['barrio'] ?: 'Sin barrio')) ?></span>
                  </div>
                  <span class="portal-badge <?= $portalClass ?>"><?= htmlspecialchars((string) ($item['portal_label'] ?? 'Portal')) ?></span>
                  <strong><?= htmlspecialchars((string) ($item['action_label'] ?? 'Sin accion')) ?></strong>
                  <span class="table-pill"><?= htmlspecialchars((string) ($item['sync_status'] ?? 'pending')) ?></span>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="portal-empty compact-empty">
              <strong>No hay acciones pendientes.</strong>
              <span>El cron no tiene nada por publicar, actualizar o despublicar ahora mismo.</span>
            </div>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($activePage === 'estados'): ?>
      <section class="states-board">
        <div class="ops-heading">
          <div>
            <h2>Estados por portal</h2>
            <p>Publicados, eliminados y errores en una sola vista.</p>
          </div>
        </div>
        <div class="panel-filter-strip" data-live-filter="states">
          <label>
            <span>Buscar inmueble</span>
            <input type="search" data-filter-search placeholder="Codigo, titulo, barrio...">
          </label>
          <label>
            <span>Grupo</span>
            <select data-filter-field="group">
              <option value="">Todos</option>
              <option value="publicados">Publicados</option>
              <option value="eliminados">Eliminados</option>
              <option value="errores">Errores</option>
            </select>
          </label>
          <label>
            <span>Portal</span>
            <select data-filter-field="portal">
              <option value="">Todos</option>
              <option value="proppit">Proppit</option>
              <option value="fincaraiz">Finca Raiz</option>
            </select>
          </label>
          <label>
            <span>Estado remoto</span>
            <select data-filter-field="remote">
              <option value="">Todos</option>
              <option value="published">published</option>
              <option value="active">active</option>
              <option value="deleted">deleted</option>
              <option value="disabled">disabled</option>
              <option value="error">error</option>
            </select>
          </label>
          <strong><span data-filter-count><?= array_sum(array_map('count', $stateSummary)) ?></span> visibles</strong>
        </div>
        <div class="state-columns">
          <?php foreach (['publicados' => 'Publicados', 'eliminados' => 'Eliminados', 'errores' => 'Errores'] as $key => $label): ?>
            <article class="state-card">
              <div class="state-card-head">
                <strong><?= htmlspecialchars($label) ?></strong>
                <span><?= count($stateSummary[$key] ?? []) ?> recientes</span>
              </div>
              <?php if (!empty($stateSummary[$key])): ?>
                <div class="state-list">
                  <?php foreach ($stateSummary[$key] as $item): ?>
                    <?php
                      $image = $item['portada_url'] ?: 'https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png';
                      $portalClass = (string) ($item['portal'] ?? '') === 'fincaraiz' ? 'is-fincaraiz' : 'is-proppit';
                    ?>
                    <div class="state-row" data-filter-item data-filter-group="<?= htmlspecialchars($key) ?>" data-filter-portal="<?= htmlspecialchars((string) ($item['portal'] ?? '')) ?>" data-filter-remote="<?= htmlspecialchars((string) ($item['remote_status'] ?? '')) ?>" data-filter-status="<?= htmlspecialchars((string) ($item['sync_status'] ?? '')) ?>" data-filter-text="<?= htmlspecialchars(strtolower((string) ($item['reference_id'] ?? '') . ' ' . (string) ($item['titulo'] ?? '') . ' ' . (string) ($item['barrio'] ?? '') . ' ' . (string) ($item['last_error'] ?? ''))) ?>">
                      <img src="<?= htmlspecialchars((string) $image) ?>" alt="<?= htmlspecialchars((string) ($item['titulo'] ?? 'Inmueble')) ?>" loading="lazy" decoding="async">
                      <div>
                        <strong><?= htmlspecialchars((string) ($item['titulo'] ?? 'Inmueble sin titulo')) ?></strong>
                        <span>ID <?= htmlspecialchars((string) ($item['reference_id'] ?? '')) ?> · <?= htmlspecialchars((string) ($item['barrio'] ?: 'Sin barrio')) ?></span>
                        <small><?= htmlspecialchars((string) ($item['remote_status'] ?? '')) ?> · <?= htmlspecialchars((string) ($item['sync_status'] ?? '')) ?></small>
                        <?php if (!empty($item['last_error'])): ?>
                          <p><?= htmlspecialchars((string) $item['last_error']) ?></p>
                        <?php endif; ?>
                      </div>
                      <span class="portal-badge <?= $portalClass ?>"><?= htmlspecialchars((string) ($item['portal_label'] ?? 'Portal')) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="portal-empty compact-empty">
                  <strong>Sin registros.</strong>
                  <span>No hay inmuebles recientes en este estado.</span>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
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
                <th><input type="checkbox" data-master-check aria-label="Seleccionar todos"></th>
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
              $isMarked = (int) ($row['publicar_proppit'] ?? 0) === 1;
              $isFincaraizMarked = (int) ($row['publicar_fincaraiz'] ?? 0) === 1;
              $isUnavailable = (string) ($row['estado'] ?? '') === 'no_disponible';
              $isBoosted = (int) ($row['is_boosted'] ?? 0) === 1;
              $isExclusive = (int) ($row['is_exclusive'] ?? 0) === 1;
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
                'fincaraizSyncStatus' => (string) ($row['fincaraiz_sync_status'] ?? 'pending'),
                'fincaraizRemoteStatus' => (string) ($row['fincaraiz_remote_status'] ?? 'not_sent'),
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
                'canManage' => !$isUnavailable,
                'publishUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/publicar'),
                'updateUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/actualizar'),
                'unpublishUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/despublicar'),
                'boostedUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/destacado'),
                'exclusiveUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/exclusivo'),
                'fincaraizPublishUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/publicar'),
                'fincaraizUpdateUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/actualizar'),
                'fincaraizUnpublishUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/despublicar'),
                'fincaraizVerifyUrl' => \App\Core\Url::to('/panel/inmuebles/' . $row['id'] . '/fincaraiz/verificar'),
              ];
            ?>
              <tr data-property-id="<?= (int) $row['id'] ?>" data-row-search="<?= htmlspecialchars(strtolower((string) $row['reference_id'] . ' ' . (string) $row['titulo'] . ' ' . (string) ($row['barrio'] ?? '') . ' ' . (string) ($row['direccion'] ?? '') . ' ' . (string) ($row['tipo_inmueble'] ?? ''))) ?>">
                <td class="check-cell"><input type="checkbox" data-row-check aria-label="Seleccionar inmueble <?= htmlspecialchars((string) $row['reference_id']) ?>"></td>
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
                  <span class="table-pill" data-card-sync><?= htmlspecialchars((string) ($row['sync_status'] ?? 'pending')) ?></span>
                  <span class="table-mini"><?= $isUnavailable ? 'No disponible' : 'Disponible' ?></span>
                </td>
                <td class="portal-chip-cell">
                  <span class="table-pill table-pill-dark" data-card-remote>Proppit: <?= htmlspecialchars((string) ($row['remote_status'] ?? 'not_sent')) ?></span>
                  <span class="table-pill table-pill-fr" data-card-fr-remote><?= htmlspecialchars((string) ($row['fincaraiz_remote_status'] ?? 'not_sent')) ?></span>
                  <span class="table-mini" data-card-fr-sync><?= htmlspecialchars((string) ($row['fincaraiz_sync_status'] ?? 'pending')) ?></span>
                  <div class="property-error table-error" data-card-error <?= $row['last_error'] ? '' : 'hidden' ?>><?= htmlspecialchars((string) ($row['last_error'] ?? '')) ?></div>
                  <div class="property-error table-error" data-card-fr-error <?= $row['fincaraiz_last_error'] ? '' : 'hidden' ?>><?= htmlspecialchars((string) ($row['fincaraiz_last_error'] ?? '')) ?></div>
                </td>
                <td>
                  <span data-card-boosted><?= $isBoosted ? 'Destacado: activo' : 'Destacado: inactivo' ?></span><br>
                  <span data-card-exclusive><?= $isExclusive ? 'Exclusivo: activo' : 'Exclusivo: inactivo' ?></span>
                </td>
                <td>
                  <div class="property-actions table-actions">
                  <?php if ($isUnavailable): ?>
                    <div class="unavailable-note">No disponible</div>
                  <?php else: ?>
                    <span class="action-portal-label"><?= htmlspecialchars($rowPortalLabel) ?></span>
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
                  <?php endif; ?>
                  </div>
                </td>
              </tr>
          <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php endif; ?>

      <?php if ($activePage === 'logs'): ?>
        <section class="logs-panel" id="logs">
          <div class="ops-heading">
            <div>
              <h2>Logs recientes</h2>
              <p>Respuestas del cron y de los botones del panel, mezcladas por hora.</p>
            </div>
          </div>
          <div class="panel-filter-strip" data-live-filter="logs">
            <label>
              <span>Buscar</span>
              <input type="search" data-filter-search placeholder="Codigo, titulo, accion o error...">
            </label>
            <label>
              <span>Portal</span>
              <select data-filter-field="portal">
                <option value="">Todos</option>
                <option value="proppit">Proppit</option>
                <option value="fincaraiz">Finca Raiz</option>
              </select>
            </label>
            <label>
              <span>Resultado</span>
              <select data-filter-field="result">
                <option value="">Todos</option>
                <option value="ok">OK</option>
                <option value="error">Error</option>
              </select>
            </label>
            <label>
              <span>HTTP</span>
              <select data-filter-field="http">
                <option value="">Todos</option>
                <option value="200">200</option>
                <option value="201">201</option>
                <option value="202">202</option>
                <option value="204">204</option>
                <option value="400">400</option>
                <option value="422">422</option>
                <option value="500">500</option>
              </select>
            </label>
            <strong><span data-filter-count><?= count($combinedLogs) ?></span> visibles</strong>
          </div>
          <div class="logs-list unified-logs">
            <?php foreach ($combinedLogs as $log): ?>
              <?php
                $ok = (int) ($log['success'] ?? 0) === 1;
                $portalClass = (string) ($log['portal'] ?? '') === 'fincaraiz' ? 'is-fincaraiz' : 'is-proppit';
              ?>
              <article data-filter-item data-filter-portal="<?= htmlspecialchars((string) ($log['portal'] ?? '')) ?>" data-filter-result="<?= $ok ? 'ok' : 'error' ?>" data-filter-http="<?= htmlspecialchars((string) ($log['http_status'] ?? '')) ?>" data-filter-text="<?= htmlspecialchars(strtolower((string) ($log['reference_id'] ?? '') . ' ' . (string) ($log['titulo'] ?? '') . ' ' . (string) ($log['action'] ?? '') . ' ' . (string) ($log['error_message'] ?? ''))) ?>">
                <div class="log-main">
                  <span class="portal-badge <?= $portalClass ?>"><?= htmlspecialchars((string) ($log['portal_label'] ?? 'Portal')) ?></span>
                  <strong><?= htmlspecialchars((string) ($log['titulo'] ?: 'Inmueble sin titulo')) ?></strong>
                  <small>ID <?= htmlspecialchars((string) ($log['reference_id'] ?? 'sin codigo')) ?> · <?= htmlspecialchars((string) ($log['action'] ?? 'sync')) ?></small>
                </div>
                <div class="log-status <?= $ok ? 'is-ok' : 'is-error' ?>">
                  <strong><?= $ok ? 'OK' : 'ERROR' ?></strong>
                  <span>HTTP <?= htmlspecialchars((string) ($log['http_status'] ?? '')) ?></span>
                  <small><?= htmlspecialchars((string) ($log['created_at'] ?? '')) ?></small>
                </div>
                <?php if (!empty($log['error_message'])): ?>
                  <p><?= htmlspecialchars((string) $log['error_message']) ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
            <?php if (!$combinedLogs): ?>
              <article><strong>Sin logs todavia</strong><span>Cuando el cron procese acciones apareceran aqui.</span></article>
            <?php endif; ?>
          </div>
        </section>
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
  <script src="<?= htmlspecialchars(\App\Core\Url::to('/panel.js')) ?>?v=20260915-1"></script>
</body>
</html>
