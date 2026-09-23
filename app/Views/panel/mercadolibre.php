<?php
$mlConnected = !empty($operation['mercadolibre_connected']);
$mlConfigured = \App\Services\MercadolibreClient::configured();
$mlEnabled = \App\Core\Env::bool('MERCADOLIBRE_ENABLED');
$mlIssues = \App\Services\MercadolibreClient::configurationIssues();
?>
<section class="ops-board ml-board" data-ml-board data-status-url="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/estado')) ?>">
  <div class="ops-heading">
    <div><h2>Conexion de Mercado Libre</h2><p><?= !$mlConfigured ? 'Configuracion incompleta · No esta publicando' : (!$mlConnected ? 'Falta autorizar la cuenta · No esta publicando' : ($mlEnabled ? 'Cuenta conectada · Sincronizacion habilitada' : 'Cuenta conectada · Sincronizacion desactivada')) ?></p></div>
    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/conectar')) ?>">
      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" <?= !$mlConfigured ? 'disabled' : '' ?>><?= $mlConnected ? 'Reconectar cuenta' : 'Conectar cuenta' ?></button>
    </form>
    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/paquetes')) ?>" data-ml-packs>
      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" <?= !$mlConnected || !$mlConfigured ? 'disabled' : '' ?>>Consultar cupos</button>
    </form>
    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/procesar-cola')) ?>" data-ajax-queue data-action-label="procesar mercado libre">
      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" <?= !$mlConnected || !$mlEnabled || !$mlConfigured ? 'disabled' : '' ?>>Procesar cola</button>
    </form>
  </div>
  <?php if ($mlIssues): ?>
    <div class="configuration-alert" role="status">
      <strong>Pendiente en portales-go/.env</strong>
      <ul><?php foreach ($mlIssues as $name => $reason): ?><li><code><?= htmlspecialchars($name) ?></code><span><?= htmlspecialchars($reason) ?></span></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <div class="ops-grid">
    <?php foreach (['published' => 'Publicados','pending' => 'En espera','processing' => 'Procesando','failed' => 'Con error'] as $key => $label): ?>
      <article><strong data-ml-metric="<?= $key ?>"><?= (int) ($mlQueue[$key] ?? 0) ?></strong><span><?= $label ?></span></article>
    <?php endforeach; ?>
  </div>
  <p>Ultima confirmacion: <strong data-ml-last-sync><?= htmlspecialchars($mlQueue['last_synced_at'] ?? 'Sin ejecuciones confirmadas') ?></strong></p>
  <div data-ml-packs-result aria-live="polite"></div>
  <details><summary>Actividad reciente de Mercado Libre</summary>
    <div class="property-table-wrap"><table class="ml-activity-table"><thead><tr><th>Inmueble</th><th>Accion</th><th>Cola</th><th>En Mercado Libre</th><th>Detalle</th></tr></thead>
      <tbody data-ml-activity>
        <?php foreach ($portalOps['mercadolibre']['items'] ?? [] as $item): ?>
          <tr><td><?= htmlspecialchars($item['reference_id'] . ' · ' . ($item['titulo'] ?? 'Inmueble retirado')) ?></td><td><?= htmlspecialchars(['publish'=>'Publicar','update'=>'Actualizar','pause'=>'Pausar','delete'=>'Eliminar'][$item['desired_action']] ?? $item['desired_action']) ?></td><td><?= htmlspecialchars($stateLabel($item['sync_status'])) ?></td><td><?= htmlspecialchars($stateLabel($item['remote_status'])) ?></td><td><?= htmlspecialchars($item['last_error'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        <?php if (empty($portalOps['mercadolibre']['items'])): ?><tr><td colspan="5">Sin operaciones registradas en Mercado Libre.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </details>
</section>
