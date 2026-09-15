<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ficha <?= htmlspecialchars((string) $inmueble['reference_id']) ?></title>
  <link rel="icon" href="https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Url::to('/assets.css')) ?>?v=20260914-1">
</head>
<body class="portal-page">
<?php
$csrf = \App\Core\Auth::csrf();
$ubicacion = $inmueble['ubicacion'] ?? [];
$proppit = $inmueble['proppit'] ?? [];
$fincaraiz = $inmueble['fincaraiz'] ?? [];
$media = $inmueble['multimedia'] ?? [];
$cover = $media[0]['url'] ?? 'https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png';
$price = (float) ($inmueble['precio_venta'] ?: $inmueble['precio_arriendo'] ?: 0);
$isUnavailable = (string) ($inmueble['estado'] ?? '') === 'no_disponible';
$isBoosted = (int) ($inmueble['is_boosted'] ?? 0) === 1;
$isExclusive = (int) ($inmueble['is_exclusive'] ?? 0) === 1;
$fincaraizPublishText = (string) ($fincaraiz['remote_status'] ?? '') === 'disabled' ? 'ACTIVAR FR' : 'PUBLICAR FR';
$currentUrl = (string) ($_SERVER['REQUEST_URI'] ?? \App\Core\Url::to('/panel/inmuebles/' . $inmueble['id']));
$flash = $flash ?? null;
?>
  <main class="detail-page">
    <header class="detail-header">
      <a class="back-button" href="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles')) ?>">REGRESAR</a>
      <div>
        <h1><?= htmlspecialchars((string) $inmueble['titulo']) ?></h1>
        <p>Codigo <?= htmlspecialchars((string) $inmueble['reference_id']) ?> · <?= htmlspecialchars((string) ($ubicacion['barrio'] ?? 'Sin barrio')) ?></p>
      </div>
    </header>

    <section class="detail-grid">
      <article class="detail-main">
        <?php if ($flash): ?>
          <div class="panel-flash panel-flash-<?= htmlspecialchars((string) ($flash['type'] ?? 'info')) ?>">
            <?= htmlspecialchars((string) ($flash['message'] ?? '')) ?>
          </div>
        <?php endif; ?>

        <div class="detail-cover" style="background-image:url('<?= htmlspecialchars((string) $cover) ?>')"></div>

        <?php if (count($media) > 1): ?>
          <div class="thumb-grid">
            <?php foreach (array_slice($media, 0, 8) as $item): ?>
              <div style="background-image:url('<?= htmlspecialchars((string) $item['url']) ?>')"></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <section class="detail-card">
          <h2>Descripcion</h2>
          <p><?= nl2br(htmlspecialchars((string) $inmueble['descripcion'])) ?></p>
        </section>

        <section class="detail-card">
          <h2>Caracteristicas</h2>
          <div class="feature-list">
            <?php foreach ($inmueble['caracteristicas'] ?? [] as $feature): ?>
              <span><?= htmlspecialchars((string) $feature['valor']) ?></span>
            <?php endforeach; ?>
            <?php if (empty($inmueble['caracteristicas'])): ?>
              <span>Sin caracteristicas cargadas</span>
            <?php endif; ?>
          </div>
        </section>
      </article>

      <aside class="detail-side">
        <section class="detail-card highlight-card">
          <span class="status-chip" data-detail-marked><?= ((int) $inmueble['publicar_proppit'] === 1) ? 'Marcado en portal' : 'No marcado en portal' ?></span>
          <strong class="detail-price"><?= $price > 0 ? '$' . number_format($price, 0, ',', '.') : 'Sin precio' ?></strong>
          <div class="detail-specs">
            <span><?= (int) $inmueble['habitaciones'] ?> Hab</span>
            <span><?= (int) $inmueble['banos'] ?> Ba</span>
            <span><?= number_format((float) ($inmueble['area_construida'] ?: $inmueble['area_privada'] ?: 0), 0, ',', '.') ?> m²</span>
            <?php if ($inmueble['estrato'] !== null && $inmueble['estrato'] !== ''): ?>
              <span>Estrato <?= (int) $inmueble['estrato'] ?></span>
            <?php endif; ?>
          </div>
          <?php if ($isUnavailable): ?>
            <div class="unavailable-note">No disponible</div>
          <?php else: ?>
            <div class="property-actions">
              <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/publicar')) ?>" data-ajax-action data-action-label="publicar">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                <button type="submit">PUBLICAR</button>
              </form>
              <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/actualizar')) ?>" data-ajax-action data-action-label="actualizar">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                <button type="submit" class="update-action">ACTUALIZAR</button>
              </form>
              <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/despublicar')) ?>" data-ajax-action data-action-label="despublicar" data-confirm="1">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                <button type="submit" class="secondary-action">DESPUBLICAR</button>
              </form>
              <div class="flag-actions">
                <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/destacado')) ?>" data-ajax-action data-action-label="destacado">
                  <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                  <button type="submit" data-card-boosted-button class="<?= $isBoosted ? 'flag-on' : '' ?>"><?= $isBoosted ? 'DESTACADO ON' : 'DESTACADO OFF' ?></button>
                </form>
                <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/exclusivo')) ?>" data-ajax-action data-action-label="exclusivo">
                  <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                  <button type="submit" data-card-exclusive-button class="<?= $isExclusive ? 'flag-on' : '' ?>"><?= $isExclusive ? 'EXCLUSIVO ON' : 'EXCLUSIVO OFF' ?></button>
                </form>
              </div>
              <div class="portal-action-group">
                <strong>Finca Raiz</strong>
                <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/fincaraiz/publicar')) ?>" data-ajax-action data-action-label="publicar finca raiz">
                  <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                  <button type="submit"><?= htmlspecialchars($fincaraizPublishText) ?></button>
                </form>
                <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/fincaraiz/actualizar')) ?>" data-ajax-action data-action-label="actualizar finca raiz">
                  <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                  <button type="submit" class="update-action">ACTUALIZAR FR</button>
                </form>
                <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/fincaraiz/despublicar')) ?>" data-ajax-action data-action-label="despublicar finca raiz" data-confirm="1">
                  <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                  <button type="submit" class="secondary-action">DESPUBLICAR FR</button>
                </form>
                <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/inmuebles/' . $inmueble['id'] . '/fincaraiz/verificar')) ?>" data-ajax-action data-action-label="verificar finca raiz">
                  <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="_redirect" value="<?= htmlspecialchars($currentUrl) ?>">
                  <button type="submit" class="update-action">VERIFICAR FR</button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </section>

        <section class="detail-card">
          <h2>Ubicacion</h2>
          <dl>
            <dt>Direccion</dt><dd><?= htmlspecialchars((string) ($ubicacion['direccion'] ?? '')) ?></dd>
            <dt>Barrio</dt><dd><?= htmlspecialchars((string) ($ubicacion['barrio'] ?? '')) ?></dd>
            <dt>Ciudad</dt><dd><?= htmlspecialchars((string) ($ubicacion['ciudad'] ?? 'Cartagena')) ?></dd>
            <dt>Departamento</dt><dd><?= htmlspecialchars((string) ($ubicacion['departamento'] ?? 'Bolivar')) ?></dd>
            <dt>Coordenadas</dt><dd><?= htmlspecialchars((string) ($ubicacion['latitud'] ?? '')) ?>, <?= htmlspecialchars((string) ($ubicacion['longitud'] ?? '')) ?></dd>
          </dl>
        </section>

        <section class="detail-card">
          <h2>Proppit</h2>
          <dl>
            <dt>Accion</dt><dd><?= htmlspecialchars((string) ($proppit['desired_action'] ?? 'sin cola')) ?></dd>
            <dt>Estado remoto</dt><dd data-detail-remote><?= htmlspecialchars((string) ($proppit['remote_status'] ?? 'not_sent')) ?></dd>
            <dt>Sync</dt><dd data-detail-sync><?= htmlspecialchars((string) ($proppit['sync_status'] ?? 'pending')) ?></dd>
            <dt>Destacado</dt><dd data-detail-boosted><?= $isBoosted ? 'Si' : 'No' ?></dd>
            <dt>Exclusivo</dt><dd data-detail-exclusive><?= $isExclusive ? 'Si' : 'No' ?></dd>
            <dt>Ultima sync</dt><dd><?= htmlspecialchars((string) ($proppit['last_synced_at'] ?? '')) ?></dd>
          </dl>
          <?php if (!empty($proppit['last_error'])): ?>
            <div class="property-error" data-card-error><?= htmlspecialchars((string) $proppit['last_error']) ?></div>
          <?php else: ?>
            <div class="property-error" data-card-error hidden></div>
          <?php endif; ?>
        </section>

        <section class="detail-card">
          <h2>Finca Raiz</h2>
          <dl>
            <dt>Accion</dt><dd><?= htmlspecialchars((string) ($fincaraiz['desired_action'] ?? 'sin cola')) ?></dd>
            <dt>Estado remoto</dt><dd data-detail-fr-remote><?= htmlspecialchars((string) ($fincaraiz['remote_status'] ?? 'not_sent')) ?></dd>
            <dt>Sync</dt><dd data-detail-fr-sync><?= htmlspecialchars((string) ($fincaraiz['sync_status'] ?? 'pending')) ?></dd>
            <dt>Listing ID</dt><dd><?= htmlspecialchars((string) ($fincaraiz['external_id'] ?? '')) ?></dd>
            <dt>FR Property</dt><dd><?= htmlspecialchars((string) ($fincaraiz['fr_property_id'] ?? '')) ?></dd>
            <dt>Ultima sync</dt><dd><?= htmlspecialchars((string) ($fincaraiz['last_synced_at'] ?? '')) ?></dd>
          </dl>
          <?php if (!empty($fincaraiz['external_url'])): ?>
            <p><a href="<?= htmlspecialchars((string) $fincaraiz['external_url']) ?>" target="_blank" rel="noopener">Abrir aviso</a></p>
          <?php endif; ?>
          <?php if (!empty($fincaraiz['last_error'])): ?>
            <div class="property-error" data-card-fr-error><?= htmlspecialchars((string) $fincaraiz['last_error']) ?></div>
          <?php else: ?>
            <div class="property-error" data-card-fr-error hidden></div>
          <?php endif; ?>
        </section>

        <section class="detail-card">
          <h2>Contactos</h2>
          <?php foreach ($inmueble['contactos'] ?? [] as $contact): ?>
            <p><strong><?= htmlspecialchars((string) $contact['tipo']) ?>:</strong> <?= htmlspecialchars((string) $contact['nombre']) ?><br><?= htmlspecialchars((string) $contact['celular']) ?><br><?= htmlspecialchars((string) $contact['correo']) ?></p>
          <?php endforeach; ?>
        </section>
      </aside>
    </section>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="<?= htmlspecialchars(\App\Core\Url::to('/panel.js')) ?>?v=20260903-1"></script>
</body>
</html>
