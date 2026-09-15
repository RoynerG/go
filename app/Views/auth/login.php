<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acceso | Portales Go</title>
  <link rel="icon" href="https://gocartagenarealestate.com/wp-content/uploads/2025/01/cropped-favicon_1.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Url::to('/assets.css')) ?>?v=20260909-2">
</head>
<?php
$bg = \App\Core\Env::get('PANEL_LOGIN_BACKGROUND_URL', '');
$style = $bg ? " style=\"--login-bg: url('" . htmlspecialchars($bg) . "')\"" : '';
?>
<body class="auth-page"<?= $style ?>>
  <main class="login-hero">
    <section class="login-form-panel">
      <h1>Ingresa a tu cuenta</h1>

      <?php if ($error): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/login')) ?>">
        <input type="hidden" name="_token" value="<?= htmlspecialchars(\App\Core\Auth::csrf()) ?>">

        <label>
          <span>Usuario</span>
          <input type="email" name="email" autocomplete="username" required>
        </label>

        <label>
          <span>Contrasena</span>
          <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <label class="remember-line">
          <input type="checkbox" name="remember" value="1">
          <span>Recordar datos</span>
        </label>

        <button type="submit">INGRESAR</button>
      </form>
    </section>
  </main>
</body>
</html>
