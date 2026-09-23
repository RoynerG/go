<?php $af = $activity['filters']; ?>
<section class="activity-workspace" aria-label="Operaciones de los portales">
  <nav class="activity-tabs" aria-label="Tipo de actividad">
    <?php foreach (['queue'=>'Cola actual','errors'=>'Errores pendientes','history'=>'Historial'] as $value=>$label): ?>
      <a class="refresh-button<?= $af['view'] === $value ? ' is-active' : '' ?>" <?= $af['view'] === $value ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars(\App\Core\Url::to('/panel/operaciones') . '?' . http_build_query(array_merge($af,['view'=>$value,'status'=>'']))) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="activity-filters" data-activity-form method="get" action="<?= htmlspecialchars(\App\Core\Url::to('/panel/operaciones')) ?>">
    <input type="hidden" name="view" value="<?= htmlspecialchars($af['view']) ?>">
    <label><span>Buscar inmueble o error</span><input name="q" type="search" value="<?= htmlspecialchars($af['q']) ?>" placeholder="Codigo, titulo, barrio o error"></label>
    <label><span>Portal</span><select name="portal"><?php foreach ([''=>'Todos los portales','proppit'=>'Proppit','fincaraiz'=>'Finca Raiz','mercadolibre'=>'Mercado Libre'] as $value=>$label): ?><option value="<?= $value ?>"<?= $af['portal'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <label><span>Accion</span><select name="action"><?php foreach ([''=>'Todas las acciones','publish'=>'Publicar','update'=>'Actualizar','pause'=>'Despublicar / pausar','delete'=>'Eliminar / retirar','activate'=>'Activar','verify'=>'Verificar','sync_error'=>'Validacion del inmueble'] as $value=>$label): ?><option value="<?= $value ?>"<?= $af['action'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
    <?php if ($af['view'] !== 'errors'): ?><label><span><?= $af['view'] === 'history' ? 'Resultado' : 'Estado de cola' ?></span><select name="status"><?php foreach (($af['view'] === 'history' ? [''=>'Todos','synced'=>'Confirmado','failed'=>'Con error'] : [''=>'Todos','pending'=>'En espera','processing'=>'Procesando','failed'=>'Con error']) as $value=>$label): ?><option value="<?= $value ?>"<?= $af['status'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <button type="submit">Buscar</button>
  </form>
  <div data-activity-results><?php require __DIR__ . '/activity-results.php'; ?></div>
</section>
