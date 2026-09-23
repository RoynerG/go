<section class="queue-panel" data-queue-monitor>
  <div class="section-heading"><h2>Trabajos pendientes y errores</h2><a class="refresh-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/logs')) ?>">Ver historial</a></div>
  <div class="panel-filter-strip" data-live-filter="queue">
    <label><span>Buscar inmueble</span><input type="search" data-filter-search placeholder="Codigo, titulo o barrio"></label>
    <label><span>Portal</span><select data-filter-field="portal"><option value="">Todos</option><option value="proppit">Proppit</option><option value="fincaraiz">Finca Raiz</option><option value="mercadolibre">Mercado Libre</option></select></label>
    <label><span>Accion</span><select data-filter-field="action"><option value="">Todas</option><option value="publish">Publicar</option><option value="update">Actualizar</option><option value="delete">Eliminar</option><option value="pause">Pausar</option><option value="activate">Activar</option><option value="verify">Verificar</option></select></label>
    <label><span>Estado</span><select data-filter-field="status"><option value="">Todos</option><option value="pending">En espera</option><option value="processing">Procesando</option><option value="failed">Con error</option></select></label>
    <strong><span data-filter-count><?= count($queueItems) ?></span> recientes visibles</strong>
  </div>
  <div class="property-table-wrap"><table class="operations-table queue-table"><thead><tr><th>Inmueble</th><th>Portal</th><th>Operacion</th><th>Estado</th><th>Detalle</th></tr></thead><tbody data-operation-items>
    <?php foreach ($queueItems as $item): ?>
    <tr data-filter-item data-filter-portal="<?= htmlspecialchars($item['portal']) ?>" data-filter-action="<?= htmlspecialchars($item['desired_action']) ?>" data-filter-status="<?= htmlspecialchars($item['sync_status']) ?>" data-filter-text="<?= htmlspecialchars(strtolower($item['reference_id'] . ' ' . ($item['titulo'] ?? '') . ' ' . ($item['barrio'] ?? ''))) ?>">
      <td><a class="refresh-button property-reference" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles') . '?' . http_build_query(['codigo'=>$item['reference_id'],'portal'=>$item['portal']])) ?>"><?= htmlspecialchars($item['titulo'] ?? 'Inmueble retirado') ?></a><small>ID <?= htmlspecialchars($item['reference_id']) ?> · <?= htmlspecialchars($item['barrio'] ?? '') ?></small></td>
      <td><?= htmlspecialchars($item['portal_label']) ?></td><td><?= htmlspecialchars($item['action_label']) ?></td>
      <td><span class="table-pill" data-state="<?= htmlspecialchars($item['sync_status']) ?>"><?= htmlspecialchars($stateLabel($item['sync_status'])) ?></span></td>
      <td class="operation-detail"><?= htmlspecialchars($item['last_error'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <p class="queue-empty" data-operation-empty <?= $queueItems ? 'hidden' : '' ?>>Sin trabajos pendientes ni errores registrados.</p>
</section>
