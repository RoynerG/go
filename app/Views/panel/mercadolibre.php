<?php
$mlConnected = !empty($operation['mercadolibre_connected']);
$mlConfigured = \App\Services\MercadolibreClient::configured();
$mlEnabled = \App\Core\Env::bool('MERCADOLIBRE_ENABLED');
?>
<section class="ops-board ml-board" data-ml-board data-status-url="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/estado')) ?>">
  <div class="ops-heading">
    <div><h2>Mercado Libre</h2><p><?= !$mlConfigured ? 'Pendiente de credenciales en el servidor' : (!$mlConnected ? 'Pendiente de autorizacion de la cuenta' : ($mlEnabled ? 'Cuenta conectada · Sincronizacion habilitada' : 'Cuenta conectada · Sincronizacion desactivada')) ?></p></div>
    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/conectar')) ?>">
      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" <?= !$mlConfigured ? 'disabled' : '' ?>><?= $mlConnected ? 'Reconectar cuenta' : 'Conectar cuenta' ?></button>
    </form>
    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/paquetes')) ?>" data-ml-packs>
      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" <?= !$mlConnected ? 'disabled' : '' ?>>Consultar cupos</button>
    </form>
    <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/mercadolibre/procesar-cola')) ?>" data-ajax-queue data-action-label="procesar mercado libre">
      <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" <?= !$mlConnected || !$mlEnabled ? 'disabled' : '' ?>>Procesar cola</button>
    </form>
  </div>
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
          <tr><td><?= htmlspecialchars($item['reference_id'] . ' · ' . ($item['titulo'] ?? 'Inmueble retirado')) ?></td><td><?= htmlspecialchars($item['desired_action']) ?></td><td><?= htmlspecialchars($item['sync_status']) ?></td><td><?= htmlspecialchars($item['remote_status']) ?></td><td><?= htmlspecialchars($item['last_error'] ?? '') ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
</section>
