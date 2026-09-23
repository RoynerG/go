<?php
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$history = $activity['filters']['view'] === 'history';
$activityUrl = static fn (int $page): string => \App\Core\Url::to('/panel/operaciones') . '?' . http_build_query(array_merge($activity['filters'], ['page'=>$page]));
?>
<div class="activity-count"><strong><?= $activity['from'] ?>-<?= $activity['to'] ?> de <?= $activity['total'] ?></strong><span><?= $history ? 'operaciones registradas' : 'trabajos' ?> · 20 por pagina</span></div>
<div class="property-table-wrap"><table class="operations-table activity-table">
  <thead><tr><th>Inmueble</th><th>Portal / accion</th><th><?= $history ? 'Resultado' : 'Cola' ?></th><th>Detalle</th><th><?= $history ? 'Fecha' : 'Seguimiento' ?></th></tr></thead>
  <tbody>
  <?php foreach ($activity['items'] as $item): ?>
    <tr>
      <td><?php if ($item['reference_id'] !== null): ?><a class="refresh-button property-reference" href="<?= $escape(\App\Core\Url::to('/panel/inmuebles') . '?' . http_build_query(['codigo'=>$item['reference_id'],'portal'=>$item['portal']])) ?>"><?= $escape($item['titulo'] ?: 'Sin titulo') ?></a><?php else: ?><strong>Inmueble retirado</strong><?php endif; ?><small>ID <?= $escape($item['reference_id'] ?? $item['inmueble_id']) ?> · <?= $escape($item['barrio'] ?? '') ?></small></td>
      <td><span class="portal-badge is-<?= $escape($item['portal']) ?>"><?= $escape($item['portal_label']) ?></span><small><?= $escape($item['action_label']) ?></small></td>
      <td><span class="table-pill" data-state="<?= $escape($item['sync_status']) ?>"><?= $escape(\App\Core\PortalDisplay::state($item['sync_status'])) ?></span><?php if (!$history): ?><small>En portal: <?= $escape(\App\Core\PortalDisplay::state($item['remote_status'])) ?></small><?php endif; ?></td>
      <td class="operation-detail"><?php if ($item['last_error']): ?><span class="<?= $item['sync_status'] === 'failed' ? 'activity-error' : 'activity-note' ?>"><?= $escape($item['last_error']) ?></span><?php else: ?><?= $item['sync_status'] === 'processing' ? 'Enviando o verificando respuesta.' : ($history ? 'Operacion confirmada.' : 'Pendiente del siguiente ciclo.') ?><?php endif; ?><?php if ($history): ?><small><?= (int) $item['http_status'] > 0 ? 'HTTP ' . (int) $item['http_status'] : 'Validacion local' ?></small><?php endif; ?></td>
      <td><time><?= $escape($item['updated_at']) ?></time><?php if (!$history && $item['attempts']): ?><small><?= (int) $item['attempts'] ?> intentos fallidos</small><?php endif; ?><?php if (!$history && $item['portal'] === 'mercadolibre' && (int) $item['attempts'] >= 5): ?><small>Requiere revision manual</small><?php elseif (!$history && $item['next_attempt_at']): ?><small>Proximo intento: <?= $escape($item['next_attempt_at']) ?></small><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if (!$activity['items']): ?><p class="queue-empty"><?= $activity['filters']['view'] === 'errors' ? 'No hay errores con estos filtros.' : ($history ? 'No hay operaciones registradas con estos filtros.' : 'No hay trabajos en cola con estos filtros.') ?></p><?php endif; ?>
<nav class="pagination" aria-label="Paginacion de operaciones">
  <?php if ($activity['page'] > 1): ?><a href="<?= $escape($activityUrl($activity['page']-1)) ?>">Anterior</a><?php else: ?><span>Anterior</span><?php endif; ?>
  <span>Pagina <?= $activity['page'] ?> de <?= $activity['pages'] ?></span>
  <?php if ($activity['page'] < $activity['pages']): ?><a href="<?= $escape($activityUrl($activity['page']+1)) ?>">Siguiente</a><?php else: ?><span>Siguiente</span><?php endif; ?>
</nav>
