<section class="portal-overview" aria-label="Actividad por portal">
  <div class="section-heading"><h2>Ahora en los portales</h2><span class="live-status" data-operation-freshness role="status">Consultando actividad...</span></div>
  <?php if (!$mlReady || \App\Services\MercadolibrePayloadBuilder::contactIssues()): ?>
    <div class="configuration-alert"><strong>Mercado Libre: <?= !$mlReady ? (empty($operation['mercadolibre_connected']) ? 'cuenta pendiente' : 'sincronizacion desactivada o configuracion incompleta') : 'falta el contacto comercial' ?></strong><a class="refresh-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/automatizacion')) ?>">Revisar conexion</a></div>
  <?php endif; ?>
  <div class="property-table-wrap">
    <table class="operations-table"><thead><tr><th>Portal</th><th>Publicados</th><th>En espera</th><th>Procesando</th><th>Con error</th><th>Ultima confirmacion</th><th>Acciones</th></tr></thead><tbody>
      <?php foreach (['proppit'=>'Proppit', 'fincaraiz'=>'Finca Raiz', 'mercadolibre'=>'Mercado Libre'] as $portal => $label): ?>
      <?php $metrics = $portalOps[$portal]['queue'] ?? []; $blocked = $portal === 'mercadolibre' && (!\App\Services\MercadolibreClient::configured() || empty($operation['mercadolibre_connected']) || !\App\Core\Env::bool('MERCADOLIBRE_ENABLED')); ?>
      <tr>
        <td><strong><?= $label ?></strong><?php if ($blocked): ?><small class="status-warning">Conexion pendiente o desactivada</small><?php endif; ?></td>
        <td><strong data-operation-metric="<?= $portal ?>:published"><?= (int) ($metrics['published'] ?? 0) ?></strong><?php if ($portal === 'fincaraiz'): ?><small>Cupo contratado: <?= $fincaraizQuota ?></small><?php endif; ?></td>
        <?php foreach (['pending','processing','errors'] as $metric): ?><td><span class="metric-value" data-operation-metric="<?= $portal ?>:<?= $metric ?>"><?= (int) ($metrics[$metric] ?? ($metric === 'errors' ? ($metrics['failed'] ?? 0) : 0)) ?></span></td><?php endforeach; ?>
        <td data-operation-metric="<?= $portal ?>:last_synced_at"><?= htmlspecialchars($metrics['last_synced_at'] ?? 'Sin confirmaciones') ?></td>
        <td><form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/' . $portal . '/procesar-cola')) ?>" data-ajax-queue data-action-label="<?= htmlspecialchars('procesar ' . $label) ?>">
          <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="limit" value="10">
          <button type="submit" <?= $blocked ? 'disabled' : '' ?>>Procesar pendientes</button>
        </form></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
</section>
