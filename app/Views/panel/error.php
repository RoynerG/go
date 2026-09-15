<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Error del panel</title>
  <link rel="icon" href="https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png">
  <link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Url::to('/assets.css')) ?>?v=20260716-1">
</head>
<body>
  <main class="shell">
    <div class="topbar">
      <div>
        <h1>Error del panel</h1>
        <div class="muted">La app PHP esta respondiendo, pero falta completar la configuracion.</div>
      </div>
    </div>

    <section class="panel">
      <div class="empty">
        <?= htmlspecialchars($message) ?>
      </div>
    </section>
  </main>
</body>
</html>
