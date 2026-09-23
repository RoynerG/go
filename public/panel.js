(function () {
  function stateLabel(value) {
    return ({pending:'En espera',processing:'Procesando',synced:'Confirmado',failed:'Con error',error:'Con error',published:'Publicado',active:'Publicado',disabled:'Despublicado',deleted:'Eliminado',paused:'Pausado',closed:'Finalizado',not_sent:'Sin publicar'})[value] || value || 'Sin actividad';
  }
  var modal = document.getElementById('property-modal');
  var currentDetail = null;

  function text(selector, value) {
    var node = modal.querySelector(selector);
    if (node) node.textContent = value || '';
  }

  function list(selector, values, emptyText) {
    var node = modal.querySelector(selector);
    if (!node) return;
    node.innerHTML = '';
    if (!values || !values.length) {
      var empty = document.createElement('span');
      empty.textContent = emptyText;
      node.appendChild(empty);
      return;
    }
    values.forEach(function (value) {
      var item = document.createElement('span');
      item.textContent = value;
      node.appendChild(item);
    });
  }

  function contacts(values) {
    var node = modal.querySelector('[data-modal-contacts]');
    if (!node) return;
    node.innerHTML = '';
    if (!values || !values.length) {
      node.textContent = 'Sin contactos cargados.';
      return;
    }
    values.forEach(function (contact) {
      var p = document.createElement('p');
      p.innerHTML = '<strong>' + escapeHtml(contact.tipo || '') + ':</strong> ' +
        escapeHtml(contact.nombre || '') + ' · ' + escapeHtml(contact.celular || '');
      node.appendChild(p);
    });
  }

  function thumbs(values) {
    var node = modal.querySelector('[data-modal-thumbs]');
    if (!node) return;
    node.innerHTML = '';
    if (!values || values.length <= 1) return;
    values.slice(0, 6).forEach(function (url) {
      var item = document.createElement('div');
      item.style.backgroundImage = "url('" + String(url).replace(/'/g, "\\'") + "')";
      node.appendChild(item);
    });
  }

  function specs(detail) {
    list('[data-modal-specs]', [
      (detail.habitaciones || 0) + ' Hab',
      (detail.banos || 0) + ' Ba',
      (detail.area || 0) + ' m²',
      (detail.parqueaderos || 0) + ' Parq',
      detail.estrato ? 'Estrato ' + detail.estrato : ''
    ].filter(Boolean), '');
  }

  function openWithDetail(detail) {
    if (!modal) return;
    currentDetail = detail || null;
    modal.dataset.propertyId = detail && detail.id ? String(detail.id) : '';
    modal.querySelector('.modal-media').style.backgroundImage = "url('" + String(detail.image || '').replace(/'/g, "\\'") + "')";
    text('[data-modal-marked]', detail.marked);
    text('[data-modal-title]', detail.title);
    text('[data-modal-subtitle]', 'Codigo ' + (detail.code || '') + ' · ' + (detail.barrio || 'Sin barrio'));
    text('[data-modal-price]', detail.price);
    text('[data-modal-address]', detail.direccion || 'Sin direccion');
    specs(detail);
    list('[data-modal-features]', detail.features, 'Sin caracteristicas cargadas');
    contacts(detail.contacts);
    thumbs(detail.media);
    var full = modal.querySelector('[data-modal-full]');
    if (full) full.href = detail.fullUrl || '#';
    var syncActions = modal.querySelector('[data-modal-sync-actions]');
    if (syncActions) syncActions.hidden = detail.canManage === false;
    var publishForm = modal.querySelector('[data-modal-publish-form]');
    if (publishForm) publishForm.action = detail.publishUrl || '#';
    var updateForm = modal.querySelector('[data-modal-update-form]');
    if (updateForm) updateForm.action = detail.updateUrl || '#';
    var unpublishForm = modal.querySelector('[data-modal-unpublish-form]');
    if (unpublishForm) unpublishForm.action = detail.unpublishUrl || '#';
    var boostedForm = modal.querySelector('[data-modal-boosted-form]');
    if (boostedForm) boostedForm.action = detail.boostedUrl || '#';
    var boostedLabel = modal.querySelector('[data-modal-boosted-label]');
    if (boostedLabel) boostedLabel.textContent = detail.boosted || 'Destacado';
    var exclusiveForm = modal.querySelector('[data-modal-exclusive-form]');
    if (exclusiveForm) exclusiveForm.action = detail.exclusiveUrl || '#';
    var exclusiveLabel = modal.querySelector('[data-modal-exclusive-label]');
    if (exclusiveLabel) exclusiveLabel.textContent = detail.exclusive || 'Exclusivo';
    var frPublishForm = modal.querySelector('[data-modal-fr-publish-form]');
    if (frPublishForm) frPublishForm.action = detail.fincaraizPublishUrl || '#';
    var frUpdateForm = modal.querySelector('[data-modal-fr-update-form]');
    if (frUpdateForm) frUpdateForm.action = detail.fincaraizUpdateUrl || '#';
    var frUnpublishForm = modal.querySelector('[data-modal-fr-unpublish-form]');
    if (frUnpublishForm) frUnpublishForm.action = detail.fincaraizUnpublishUrl || '#';
    [frPublishForm, frUpdateForm, frUnpublishForm].forEach(function (form) { if (form) form.hidden = !!detail.actionPortal; });
    [boostedForm, exclusiveForm].forEach(function (form) { if (form) form.hidden = detail.actionPortal && detail.actionPortal !== 'proppit'; });
    [publishForm, updateForm, unpublishForm].forEach(function (form, index) {
      if (!form) return;
      var label = ['Publicar', 'Actualizar', 'Despublicar'][index];
      var button = form.querySelector('button');
      if (button) button.textContent = label + ' ' + (detail.actionPortalLabel || 'Proppit');
      form.dataset.actionLabel = label.toLowerCase() + (detail.actionPortal === 'mercadolibre' ? ' mercado libre' : '');
    });
    modal.hidden = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
  }

  function closeModal() {
    if (!modal) return;
    currentDetail = null;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function swalAvailable() {
    return typeof window.Swal !== 'undefined' && window.Swal && typeof window.Swal.fire === 'function';
  }

  function actionTitle(label) {
    var titles = {
      publicar: 'Publicando inmueble',
      actualizar: 'Actualizando inmueble',
      despublicar: 'Despublicando inmueble',
      'publicar finca raiz': 'Publicando en Finca Raiz',
      'actualizar finca raiz': 'Actualizando Finca Raiz',
      'despublicar finca raiz': 'Despublicando Finca Raiz',
      'verificar finca raiz': 'Verificando Finca Raiz',
      destacado: 'Actualizando destacado',
      exclusivo: 'Actualizando exclusivo',
      'procesar cola': 'Procesando cola',
      'procesar finca raiz': 'Procesando Finca Raiz'
    };
    return titles[label] || 'Procesando accion';
  }

  function successTitle(label) {
    var titles = {
      publicar: 'Publicado',
      actualizar: 'Actualizado',
      despublicar: 'Despublicado',
      'publicar finca raiz': 'Publicado en Finca Raiz',
      'actualizar finca raiz': 'Actualizado en Finca Raiz',
      'despublicar finca raiz': 'Despublicado en Finca Raiz',
      'verificar finca raiz': 'Verificado en Finca Raiz',
      destacado: 'Destacado actualizado',
      exclusivo: 'Exclusivo actualizado',
      'procesar cola': 'Cola procesada',
      'procesar finca raiz': 'Finca Raiz procesado'
    };
    return titles[label] || 'Listo';
  }

  function confirmAction(form) {
    if (!form.hasAttribute('data-confirm')) return Promise.resolve(true);
    var permanent = form.getAttribute('data-confirm') === 'delete';
    if (!swalAvailable()) return Promise.resolve(window.confirm(permanent ? 'Eliminar definitivamente de Mercado Libre. No podras reactivar este anuncio. ¿Continuar?' : '¿Seguro que deseas continuar?'));

    return window.Swal.fire({
      title: permanent ? '¿Eliminar definitivamente?' : '¿Despublicar inmueble?',
      text: permanent ? 'El anuncio se cerrara y eliminara de Mercado Libre. No podras reactivarlo.' : 'La accion se enviara al portal y la tarjeta se actualizara sin recargar.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: permanent ? 'Eliminar definitivamente' : 'Si, despublicar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#ffcc00',
      cancelButtonColor: '#1f2937',
      reverseButtons: true
    }).then(function (result) {
      return result.isConfirmed;
    });
  }

  function showLoading(label) {
    if (!swalAvailable()) return;
    window.Swal.fire({
      title: actionTitle(label),
      text: 'Espera un momento...',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: function () {
        window.Swal.showLoading();
      }
    });
  }

  function showResult(type, title, message) {
    if (!swalAvailable()) {
      window.alert(message || title);
      return;
    }
    window.Swal.fire({
      icon: type || 'success',
      title: title,
      text: message || '',
      timer: type === 'success' ? 2200 : undefined,
      timerProgressBar: type === 'success',
      confirmButtonText: 'Aceptar',
      confirmButtonColor: '#ffcc00'
    });
  }

  function setButtonBusy(form, busy) {
    var button = form.querySelector('button[type="submit"]');
    if (!button) return;
    button.disabled = busy;
  }

  function updateText(root, selector, value) {
    var node = root ? root.querySelector(selector) : null;
    if (/data-card-(?:ml-|fr-|proppit-)?(?:sync|remote)/.test(selector)) {
      if (node) node.dataset.state = value || '';
      value = stateLabel(value);
    }
    if (node) node.textContent = value;
  }

  function portalActionText(action, syncStatus) {
    var labels = {
      publish: 'Publicar',
      update: 'Actualizar',
      delete: 'Despublicar',
      pause: 'Despublicar',
      activate: 'Activar',
      verify: 'Verificar'
    };
    var actionKey = String(action || '').trim();
    var syncKey = String(syncStatus || '').trim();
    var label = labels[actionKey] || (actionKey ? actionKey.charAt(0).toUpperCase() + actionKey.slice(1) : 'Sin accion');
    if (syncKey === 'pending') return 'En cola: ' + label;
    if (syncKey === 'processing') return 'Procesando: ' + label;
    if (syncKey === 'failed') return 'Fallo: ' + label;
    return actionKey ? 'Ultima: ' + label : 'Sin cola';
  }

  function updateCard(card) {
    if (!card || !card.id) return;
    if (card.mercadolibre) {
      updateMercadolibreCard(card.id, card.mercadolibre);
      return;
    }
    updateDetailPage(card);
    var article = document.querySelector('[data-property-id="' + String(card.id) + '"]');
    if (!article) return;

    updateText(article, '[data-card-sync]', card.sync_status || 'pending');
    updateText(article, '[data-card-remote]', card.remote_status || 'not_sent');
    updateText(article, '[data-card-proppit-sync]', card.sync_status || 'pending');
    updateText(article, '[data-card-proppit-action]', portalActionText(card.desired_action, card.sync_status));
    updateText(article, '[data-card-fr-sync]', card.fincaraiz_sync_status || 'pending');
    updateText(article, '[data-card-fr-remote]', card.fincaraiz_remote_status || 'not_sent');
    updateText(article, '[data-card-fr-action]', portalActionText(card.fincaraiz_desired_action, card.fincaraiz_sync_status));
    updateText(article, '[data-card-boosted]', card.is_boosted ? 'Destacado: activo' : 'Destacado: inactivo');
    updateText(article, '[data-card-exclusive]', card.is_exclusive ? 'Exclusivo: activo' : 'Exclusivo: inactivo');

    var error = article.querySelector('[data-card-error]');
    if (error) {
      error.textContent = card.last_error || '';
      error.hidden = !card.last_error;
    }

    var frError = article.querySelector('[data-card-fr-error]');
    if (frError) {
      frError.textContent = card.fincaraiz_last_error || '';
      frError.hidden = !card.fincaraiz_last_error;
    }

    var boostedButton = article.querySelector('[data-card-boosted-button]');
    if (boostedButton) {
      boostedButton.textContent = card.boosted_button || (card.is_boosted ? 'DESTACADO ON' : 'DESTACADO OFF');
      boostedButton.classList.toggle('flag-on', !!card.is_boosted);
    }

    var exclusiveButton = article.querySelector('[data-card-exclusive-button]');
    if (exclusiveButton) {
      exclusiveButton.textContent = card.exclusive_button || (card.is_exclusive ? 'EXCLUSIVO ON' : 'EXCLUSIVO OFF');
      exclusiveButton.classList.toggle('flag-on', !!card.is_exclusive);
    }

    var detailButton = article.querySelector('[data-detail]');
    if (detailButton) {
      try {
        var detail = JSON.parse(detailButton.getAttribute('data-detail'));
        detail.marked = card.marked || detail.marked;
        detail.proppitAction = card.desired_action || detail.proppitAction;
        detail.proppitActionText = portalActionText(card.desired_action, card.sync_status);
        detail.fincaraizAction = card.fincaraiz_desired_action || detail.fincaraizAction;
        detail.fincaraizActionText = portalActionText(card.fincaraiz_desired_action, card.fincaraiz_sync_status);
        detail.fincaraizMarked = card.fincaraiz_remote_status || detail.fincaraizMarked;
        detail.fincaraizSyncStatus = card.fincaraiz_sync_status || detail.fincaraizSyncStatus;
        detail.fincaraizRemoteStatus = card.fincaraiz_remote_status || detail.fincaraizRemoteStatus;
        detail.boosted = card.boosted || detail.boosted;
        detail.exclusive = card.exclusive || detail.exclusive;
        detailButton.setAttribute('data-detail', JSON.stringify(detail));
        if (currentDetail && String(currentDetail.id) === String(card.id)) {
          currentDetail = detail;
        }
      } catch (error) {
        console.error('No se pudo actualizar data-detail', error);
      }
    }

    if (modal && modal.dataset.propertyId === String(card.id)) {
      text('[data-modal-marked]', card.marked || '');
      var boostedLabel = modal.querySelector('[data-modal-boosted-label]');
      if (boostedLabel) boostedLabel.textContent = card.boosted || 'Destacado';
      var exclusiveLabel = modal.querySelector('[data-modal-exclusive-label]');
      if (exclusiveLabel) exclusiveLabel.textContent = card.exclusive || 'Exclusivo';
    }
  }

  function updateDetailPage(card) {
    updateText(document, '[data-detail-marked]', card.marked || '');
    updateText(document, '[data-detail-remote]', card.remote_status || 'not_sent');
    updateText(document, '[data-detail-sync]', card.sync_status || 'pending');
    updateText(document, '[data-detail-fr-remote]', card.fincaraiz_remote_status || 'not_sent');
    updateText(document, '[data-detail-fr-sync]', card.fincaraiz_sync_status || 'pending');
    updateText(document, '[data-detail-boosted]', card.is_boosted ? 'Si' : 'No');
    updateText(document, '[data-detail-exclusive]', card.is_exclusive ? 'Si' : 'No');

    var detailError = document.querySelector('main.detail-page [data-card-error]');
    if (detailError) {
      detailError.textContent = card.last_error || '';
      detailError.hidden = !card.last_error;
    }

    var detailFrError = document.querySelector('main.detail-page [data-card-fr-error]');
    if (detailFrError) {
      detailFrError.textContent = card.fincaraiz_last_error || '';
      detailFrError.hidden = !card.fincaraiz_last_error;
    }

    var boostedButton = document.querySelector('main.detail-page [data-card-boosted-button]');
    if (boostedButton) {
      boostedButton.textContent = card.boosted_button || (card.is_boosted ? 'DESTACADO ON' : 'DESTACADO OFF');
      boostedButton.classList.toggle('flag-on', !!card.is_boosted);
    }

    var exclusiveButton = document.querySelector('main.detail-page [data-card-exclusive-button]');
    if (exclusiveButton) {
      exclusiveButton.textContent = card.exclusive_button || (card.is_exclusive ? 'EXCLUSIVO ON' : 'EXCLUSIVO OFF');
      exclusiveButton.classList.toggle('flag-on', !!card.is_exclusive);
    }
  }

  function updateQueueBoard(operation) {
    if (!operation || !operation.queue) return;
    Object.keys(operation.queue).forEach(function (key) {
      var node = document.querySelector('[data-queue-metric="' + key + '"]');
      if (node) node.textContent = operation.queue[key] == null ? '0' : String(operation.queue[key]);
    });

    if (operation.portals && operation.portals.fincaraiz && operation.portals.fincaraiz.queue) {
      Object.keys(operation.portals.fincaraiz.queue).forEach(function (key) {
        var node = document.querySelector('[data-portal-queue-metric="fincaraiz:' + key + '"]');
        if (node) node.textContent = operation.portals.fincaraiz.queue[key] == null ? '0' : String(operation.portals.fincaraiz.queue[key]);
      });
      var frLastSync = document.querySelector('[data-portal-queue-last-sync="fincaraiz"]');
      if (frLastSync) frLastSync.textContent = operation.portals.fincaraiz.queue.last_synced_at || 'sin registros';
    }

    var lastSync = document.querySelector('[data-queue-last-sync]');
    if (lastSync) lastSync.textContent = operation.queue.last_synced_at || 'sin registros';
  }

  function submitQueueForm(form) {
    var label = form.getAttribute('data-action-label') || 'procesar cola';
    setButtonBusy(form, true);
    showLoading(label);

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return response.json().catch(function () {
          return {
            ok: false,
            type: 'error',
            message: 'El servidor no devolvio JSON. Revisa la sesion o el error PHP.'
          };
        });
      })
      .then(function (payload) {
        updateQueueBoard(payload.operation);
        refreshMercadolibre();
        refreshOperation();
        if (payload.cards && payload.cards.length) {
          payload.cards.forEach(updateCard);
        }
        var detail = '';
        if (payload.sync) {
          detail = 'Procesados: ' + (payload.sync.processed || 0) + ' · OK: ' + (payload.sync.synced || 0) + ' · Fallidos: ' + (payload.sync.failed || 0);
        }
        showResult(payload.type || (payload.ok ? 'success' : 'error'), payload.type === 'success' ? successTitle(label) : 'Atencion', payload.message || detail);
      })
      .catch(function (error) {
        showResult('error', 'No se pudo completar', error && error.message ? error.message : 'Revisa la conexion.');
      })
      .finally(function () {
        setButtonBusy(form, false);
      });
  }

  function submitAjaxForm(form) {
    var label = form.getAttribute('data-action-label') || 'accion';
    confirmAction(form).then(function (confirmed) {
      if (!confirmed) return;

      setButtonBusy(form, true);
      showLoading(label);

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          return response.json().catch(function () {
            return {
              ok: false,
              type: 'error',
              message: 'El servidor no devolvio JSON. Revisa la sesion o el error PHP.'
            };
          }).then(function (payload) {
            payload.httpStatus = response.status;
            return payload;
          });
        })
        .then(function (payload) {
          if (payload.card) updateCard(payload.card);
          refreshOperation();
          showResult(payload.type || (payload.ok ? 'success' : 'error'), payload.type === 'success' ? successTitle(label) : 'Atencion', payload.message || '');
        })
        .catch(function (error) {
          showResult('error', 'No se pudo completar', error && error.message ? error.message : 'Revisa la conexion.');
        })
        .finally(function () {
          setButtonBusy(form, false);
        });
    });
  }

  var panelNavigation = 0;
  function loadPanelUrl(url, pushState) {
    var navigation = ++panelNavigation;
    document.body.classList.add('panel-loading');
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'text/html',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('No se pudo cargar la vista.');
        return response.text();
      })
      .then(function (html) {
        if (navigation !== panelNavigation) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nextLayout = doc.querySelector('.portal-layout');
        var currentLayout = document.querySelector('.portal-layout');
        if (!nextLayout || !currentLayout) {
          window.location.href = url;
          return;
        }
        currentLayout.replaceWith(nextLayout);

        var nextModal = doc.getElementById('property-modal');
        var currentModal = document.getElementById('property-modal');
        if (nextModal && currentModal) {
          currentModal.replaceWith(nextModal);
          modal = document.getElementById('property-modal');
        }

        document.title = doc.title || document.title;
        if (pushState) window.history.pushState({ pgPanel: true }, '', url);
        closeModal();
        initPanelInteractions();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      })
      .catch(function (error) {
        if (navigation !== panelNavigation) return;
        showResult('error', 'No se pudo cargar', error && error.message ? error.message : 'Revisa la conexion.');
      })
      .finally(function () {
        if (navigation !== panelNavigation) return;
        document.body.classList.remove('panel-loading');
      });
  }

  function buildUrlFromForm(form) {
    var url = new URL(form.getAttribute('action') || window.location.href, window.location.origin);
    var params = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
      var textValue = String(value || '').trim();
      if (textValue !== '') params.set(key, textValue);
    });
    params.delete('orden');
    params.delete('page');
    url.search = params.toString();
    return url.toString();
  }

  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-detail]');
    if (opener) {
      try {
        openWithDetail(JSON.parse(opener.getAttribute('data-detail')));
      } catch (error) {
        console.error('No se pudo abrir la ficha', error);
      }
      return;
    }

    if (event.target.closest('[data-close-modal]') || event.target === modal) {
      closeModal();
      return;
    }

    var ajaxLink = event.target.closest('.side-menu a, .pagination a, .clear-filters, .refresh-button, .portal-tabs a, .queue-button');
    if (ajaxLink && ajaxLink.href && ajaxLink.origin === window.location.origin) {
      event.preventDefault();
      loadPanelUrl(ajaxLink.href, true);
    }
  });

  document.addEventListener('submit', function (event) {
    var filterForm = event.target.closest('form.admin-filter-panel, form[data-activity-form]');
    if (filterForm) {
      event.preventDefault();
      loadPanelUrl(buildUrlFromForm(filterForm), true);
      return;
    }

    var queueForm = event.target.closest('form[data-ajax-queue]');
    if (queueForm) {
      event.preventDefault();
      submitQueueForm(queueForm);
      return;
    }

    var form = event.target.closest('form[data-ajax-action]');
    if (!form) return;
    event.preventDefault();
    submitAjaxForm(form);
  });

  function updateSelectedCount() {
    var checked = document.querySelectorAll('[data-row-check]:checked').length;
    var node = document.querySelector('[data-selected-count]');
    if (node) node.textContent = String(checked);
  }

  function initSelection() {
    var master = document.querySelector('[data-master-check]');
    if (master && !master.dataset.bound) {
      master.dataset.bound = '1';
      master.addEventListener('change', function () {
        document.querySelectorAll('[data-row-check]').forEach(function (box) {
          var row = box.closest('tr');
          if (row && row.hidden) return;
          box.checked = master.checked;
        });
        updateSelectedCount();
      });
    }

    var selectPage = document.querySelector('[data-select-page]');
    if (selectPage && !selectPage.dataset.bound) {
      selectPage.dataset.bound = '1';
      selectPage.addEventListener('click', function () {
        document.querySelectorAll('[data-row-check]').forEach(function (box) {
          var row = box.closest('tr');
          if (row && !row.hidden) box.checked = true;
        });
        if (master) master.checked = true;
        updateSelectedCount();
      });
    }

  }

  function initLiveSearch() {
    var input = document.querySelector('[data-table-search]');
    if (!input) return;
    if (input.dataset.bound) return;
    input.dataset.bound = '1';
    input.addEventListener('input', function () {
      var value = input.value.trim().toLowerCase();
      document.querySelectorAll('[data-row-search]').forEach(function (row) {
        row.hidden = value !== '' && row.getAttribute('data-row-search').indexOf(value) === -1;
      });
      updateSelectedCount();
    });
  }

  function initThemeToggle() {
    var toggle = document.querySelector('[data-theme-toggle]');
    var saved = window.localStorage ? window.localStorage.getItem('pg-theme') : '';
    if (saved === 'dark') document.documentElement.classList.add('theme-dark');
    if (!toggle) return;
    if (toggle.dataset.bound) return;
    toggle.dataset.bound = '1';
    toggle.addEventListener('click', function () {
      document.documentElement.classList.toggle('theme-dark');
      if (window.localStorage) {
        window.localStorage.setItem('pg-theme', document.documentElement.classList.contains('theme-dark') ? 'dark' : 'light');
      }
    });
  }

  function initLiveFilters() {
    document.querySelectorAll('[data-live-filter]').forEach(function (panel) {
      if (panel.dataset.bound) return;
      panel.dataset.bound = '1';
      var scope = panel.parentElement || document;
      var fields = Array.prototype.slice.call(panel.querySelectorAll('[data-filter-field]'));
      var search = panel.querySelector('[data-filter-search]');
      var count = panel.querySelector('[data-filter-count]');

      function apply() {
        var query = search ? search.value.trim().toLowerCase() : '';
        var visible = 0;
        scope.querySelectorAll('[data-filter-item]').forEach(function (item) {
          var ok = true;
          if (query && String(item.getAttribute('data-filter-text') || '').indexOf(query) === -1) {
            ok = false;
          }
          fields.forEach(function (field) {
            if (!ok) return;
            var key = field.getAttribute('data-filter-field');
            var value = String(field.value || '').trim();
            if (value && String(item.getAttribute('data-filter-' + key) || '') !== value) {
              ok = false;
            }
          });
          item.hidden = !ok;
          if (ok) visible++;
        });
        if (count) count.textContent = String(visible);
        var activeFilter = !!query || fields.some(function (field) { return !!field.value; });
        scope.querySelectorAll('.state-card').forEach(function (group) {
          group.hidden = activeFilter && !Array.from(group.querySelectorAll('[data-filter-item]')).some(function (item) { return !item.hidden; });
        });
        var empty = scope.querySelector('[data-operation-empty]');
        if (empty) { empty.hidden = visible > 0; empty.textContent = scope.querySelector('[data-filter-item]') ? 'No hay trabajos que coincidan con estos filtros.' : 'Sin trabajos pendientes ni errores registrados.'; }
      }

      if (search) search.addEventListener('input', apply);
      fields.forEach(function (field) {
        field.addEventListener('change', apply);
      });
      apply();
    });
  }

  function initPanelInteractions() {
    initSelection();
    initLiveSearch();
    initThemeToggle();
    initLiveFilters();
    updateSelectedCount();
    refreshMercadolibre();
    refreshOperation();
  }

  function updateMercadolibreCard(id, ad) {
    var row = document.querySelector('[data-property-id="' + String(id) + '"]');
    if (!row) return;
    updateText(row, '[data-card-ml-remote]', ad.remote_status || 'Sin publicar');
    row.querySelectorAll('[data-card-ml-sync]').forEach(function (node) { node.textContent = stateLabel(ad.sync_status); node.dataset.state = ad.sync_status || ''; });
    updateText(row, '[data-card-ml-type]', ({silver:'Plata',gold:'Oro',gold_premium:'Oro Premium'})[ad.listing_type_id] || ad.listing_type_id || 'Sin asignar');
    updateText(row, '[data-card-ml-action]', portalActionText(ad.desired_action, ad.sync_status));
    var error = row.querySelector('[data-card-ml-error]');
    if (error) { error.textContent = ad.last_error || ''; error.hidden = !ad.last_error; }
  }

  var mlPollBusy = false;
  function refreshMercadolibre() {
    var board = document.querySelector('[data-ml-board]');
    if (!board || mlPollBusy || document.hidden) return;
    mlPollBusy = true;
    var ids = Array.from(document.querySelectorAll('[data-property-id]')).map(function (row) { return row.dataset.propertyId; }).filter(Boolean).slice(0, 20);
    fetch(board.dataset.statusUrl + '?ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) { if (!response.ok) throw new Error('status'); return response.json(); })
      .then(function (data) {
        if (!data.ok || !board.isConnected) return;
        Object.keys(data.queue || {}).forEach(function (key) {
          document.querySelectorAll('[data-ml-metric="' + key + '"]').forEach(function (node) { node.textContent = data.queue[key] || '0'; });
        });
        updateText(board, '[data-ml-last-sync]', data.queue.last_synced_at || 'Sin ejecuciones confirmadas');
        (data.cards || []).forEach(function (ad) { updateMercadolibreCard(ad.inmueble_id, ad); });
        var body = board.querySelector('[data-ml-activity]');
        if (body) {
          body.replaceChildren();
          (data.items || []).forEach(function (ad) {
            var row = document.createElement('tr');
            [ad.reference_id + ' · ' + (ad.titulo || 'Inmueble retirado'), portalActionText(ad.desired_action, ad.sync_status), stateLabel(ad.sync_status), stateLabel(ad.remote_status), ad.last_error || ''].forEach(function (value) {
              var cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell);
            });
            body.appendChild(row);
          });
          if (!(data.items || []).length) {
            var emptyRow = document.createElement('tr'); var emptyCell = document.createElement('td');
            emptyCell.colSpan = 5; emptyCell.textContent = 'Sin operaciones registradas en Mercado Libre.';
            emptyRow.appendChild(emptyCell); body.appendChild(emptyRow);
          }
        }
      })
      .catch(function () { updateText(board, '[data-ml-last-sync]', 'No se pudo consultar el estado; reintentando'); })
      .finally(function () { mlPollBusy = false; });
  }
  window.setInterval(refreshMercadolibre, 15000);

  var operationBusy = false;
  function refreshOperation() {
    var root = document.querySelector('[data-operation-url]');
    if (!root || operationBusy || document.hidden) return;
    operationBusy = true;
    fetch(root.dataset.operationUrl, {credentials:'same-origin', headers:{Accept:'application/json'}})
      .then(function (response) { if (!response.ok) throw new Error('status'); return response.json(); })
      .then(function (data) {
        if (!data.ok || !root.isConnected) return;
        var operation = data.operation; var pending = 0; var errors = 0;
        Object.keys(operation.portals || {}).forEach(function (portal) {
          var metrics = operation.portals[portal].queue || {};
          pending += Number(metrics.pending || 0) + Number(metrics.processing || 0);
          errors += Number(metrics.errors == null ? metrics.failed || 0 : metrics.errors);
          root.querySelectorAll('[data-operation-metric]').forEach(function (node) {
            var key = node.dataset.operationMetric.split(':');
            if (key[0] !== portal) return;
            var value = metrics[key[1]];
            if (key[1] === 'errors' && value == null) value = metrics.failed;
            node.textContent = value == null ? (key[1] === 'last_synced_at' ? 'Sin confirmaciones' : '0') : String(value);
          });
        });
        updateText(root, '[data-operation-pending]', pending);
        updateText(root, '[data-operation-errors]', errors);
        updateText(root, '[data-inventory-available]', (operation.inventory || {}).available || 0);
        updateText(root, '[data-operation-freshness]', 'Actualizado ' + new Date().toLocaleTimeString('es-CO'));
        var activity = root.querySelector('[data-activity-results]');
        if (activity && typeof data.activity_html === 'string' && !activity.contains(document.activeElement)) {
          var scroll = activity.querySelector('.property-table-wrap');
          var left = scroll ? scroll.scrollLeft : 0;
          activity.innerHTML = data.activity_html;
          var nextScroll = activity.querySelector('.property-table-wrap');
          if (nextScroll) nextScroll.scrollLeft = left;
        }
        var body = root.querySelector('[data-operation-items]');
        if (body) {
          body.replaceChildren();
          (operation.queue_items || []).forEach(function (item) {
            var row = document.createElement('tr'); row.dataset.filterItem = '';
            row.dataset.filterPortal = item.portal; row.dataset.filterAction = item.desired_action; row.dataset.filterStatus = item.sync_status;
            row.dataset.filterText = [item.reference_id,item.titulo,item.barrio].join(' ').toLowerCase();
            var property = document.createElement('td'); var link = document.createElement('a');
            link.className = 'refresh-button property-reference'; link.textContent = item.titulo || 'Inmueble retirado';
            link.href = root.dataset.propertyListUrl + '?' + new URLSearchParams({codigo:item.reference_id,portal:item.portal});
            property.appendChild(link); var code = document.createElement('small'); code.textContent = 'ID ' + item.reference_id + ' · ' + (item.barrio || ''); property.appendChild(code); row.appendChild(property);
            [item.portal_label,item.action_label,stateLabel(item.sync_status),item.last_error || ''].forEach(function (value, index) {
              var cell = document.createElement('td');
              if (index === 2) { var badge = document.createElement('span'); badge.className='table-pill'; badge.dataset.state=item.sync_status; badge.textContent=value; cell.appendChild(badge); }
              else { cell.textContent=value; if (index===3) cell.className='operation-detail'; }
              row.appendChild(cell);
            });
            body.appendChild(row);
          });
          var empty = root.querySelector('[data-operation-empty]'); if (empty) empty.hidden = !!(operation.queue_items || []).length;
          var filter = root.querySelector('[data-queue-monitor] [data-filter-search]'); if (filter) filter.dispatchEvent(new Event('input'));
        }
      })
      .catch(function () { if (root.isConnected) updateText(root, '[data-operation-freshness]', 'Sin conexion · Datos anteriores'); })
      .finally(function () { operationBusy = false; });
  }
  window.setInterval(refreshOperation, 15000);

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-ml-packs]');
    if (!form) return;
    event.preventDefault();
    setButtonBusy(form, true);
    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.message || 'No se pudieron consultar los cupos.');
        var node = document.querySelector('[data-ml-packs-result]');
        if (!node) return;
        node.replaceChildren();
        (data.packs || []).forEach(function (pack) {
          var p = document.createElement('p');
          p.textContent = (pack.description || 'Paquete') + ' · Vence: ' + (pack.date_expires || 'Sin fecha') + ' · ' + (pack.listing_details || []).map(function (d) { return d.listing_type_id + ': ' + d.remaining_listings + ' disponibles'; }).join(' · ');
          node.appendChild(p);
        });
        if (!data.packs.length) node.textContent = 'La cuenta no devuelve paquetes activos.';
      })
      .catch(function (error) { showResult('error', 'Mercado Libre', error.message); })
      .finally(function () { setButtonBusy(form, false); });
  });

  document.addEventListener('change', function (event) {
    var activityForm = event.target.closest('[data-activity-form]');
    if (activityForm && event.target.tagName === 'SELECT') {
      loadPanelUrl(buildUrlFromForm(activityForm), true);
      return;
    }
    if (event.target && event.target.matches('[data-portal-select]')) {
      var form = event.target.form;
      ['proppit_estado','fincaraiz_estado','mercadolibre_estado','mercadolibre_cola','marcado'].forEach(function (name) { if (form.elements[name]) form.elements[name].value = ''; });
      loadPanelUrl(buildUrlFromForm(form), true);
    }
    if (event.target && event.target.matches('[data-row-check]')) updateSelectedCount();
  });

  window.addEventListener('popstate', function () {
    loadPanelUrl(window.location.href, false);
  });

  initPanelInteractions();

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
  });
})();
