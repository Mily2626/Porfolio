/**
 * Parking Tech - Panel de administracion
 * - Pestanas (tabs) con soporte de hash
 * - Confirmacion generica de acciones destructivas
 * - Refresco en vivo del estado de la barrera
 */
(function () {
  'use strict';

  // -------------------------------------------------------------------------
  // Pestanas
  // -------------------------------------------------------------------------
  var tabs   = Array.prototype.slice.call(document.querySelectorAll('.ad-tab'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.ad-panel'));

  function activar(id) {
    if (!document.querySelector('.ad-tab[data-tab="' + id + '"]')) id = 'resumen';
    tabs.forEach(function (t) {
      t.classList.toggle('is-active', t.getAttribute('data-tab') === id);
    });
    panels.forEach(function (p) {
      p.hidden = p.getAttribute('data-panel') !== id;
    });
    if (window.location.hash.slice(1) !== id) {
      history.replaceState(null, '', '#' + id);
    }
  }

  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      activar(t.getAttribute('data-tab'));
    });
  });

  window.addEventListener('hashchange', function () {
    activar(window.location.hash.replace('#', ''));
  });

  activar(window.location.hash.replace('#', '') || 'resumen');

  // -------------------------------------------------------------------------
  // Confirmacion generica
  // -------------------------------------------------------------------------
  var modal   = document.getElementById('modal-admin');
  var msgEl   = document.getElementById('admin-confirm-msg');
  var okBtn   = document.getElementById('admin-confirm-ok');
  var pendiente = null;

  function abrirModal() { if (modal) modal.classList.add('open'); }
  function cerrarModal() { if (modal) modal.classList.remove('open'); }

  Array.prototype.slice.call(document.querySelectorAll('form[data-confirm]')).forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.getAttribute('data-confirmado') === '1') return;
      ev.preventDefault();
      pendiente = f;
      if (msgEl) msgEl.textContent = f.getAttribute('data-confirm') || '¿Estas seguro?';
      abrirModal();
    });
  });

  if (modal) {
    modal.querySelectorAll('[data-close]').forEach(function (el) {
      el.addEventListener('click', cerrarModal);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') cerrarModal();
    });
  }

  if (okBtn) {
    okBtn.addEventListener('click', function () {
      if (!pendiente) return;
      pendiente.setAttribute('data-confirmado', '1');
      cerrarModal();
      pendiente.submit();
    });
  }

  // -------------------------------------------------------------------------
  // Refresco en vivo del estado de la barrera
  // -------------------------------------------------------------------------
  var live = document.getElementById('ad-live');
  if (!live) return;

  var api = live.getAttribute('data-api');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function renderComandos(lista) {
    var tb = document.getElementById('ad-comandos');
    if (!tb || !lista) return;
    tb.innerHTML = lista.map(function (c) {
      var icono = c.tipo === 'entrada' ? 'bi-box-arrow-in-right' : 'bi-box-arrow-right';
      var origen = c.manual || !c.codigo ? 'Manual' : c.codigo;
      return '<tr>' +
        '<td class="mono">' + esc(c.id) + '</td>' +
        '<td><i class="bi ' + icono + '"></i> ' + (c.tipo === 'entrada' ? 'Entrada' : 'Salida') + '</td>' +
        '<td class="mono muted">' + esc(origen) + '</td>' +
        '<td><span class="badge badge-' + esc(c.estado) + '">' + esc(c.estado.charAt(0).toUpperCase() + c.estado.slice(1)) + '</span></td>' +
        '<td class="muted">' + esc(c.creado_en) + '</td>' +
        '</tr>';
    }).join('');
  }

  function setTexto(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  function tick() {
    fetch(api, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;

        var punto = document.getElementById('ad-disp-punto');
        if (punto) {
          punto.className = 'punto ' + (d.dispositivo.en_linea ? 'online' : 'offline');
        }
        setTexto('ad-disp-texto',
          (d.dispositivo.en_linea ? 'En linea' : 'Fuera de linea') +
          ' · IP ' + (d.dispositivo.ip || 'sin datos'));
        setTexto('ad-disp-estado', d.dispositivo.estado.charAt(0).toUpperCase() + d.dispositivo.estado.slice(1));
        setTexto('ad-disp-latido', d.dispositivo.ultimo_latido || '—');
        setTexto('ad-disp-dentro', d.dentro);
        setTexto('ad-disp-pend', d.pendientes);
        setTexto('ad-comandos-hora', d.ahora);
        renderComandos(d.comandos);
      })
      .catch(function () { /* silencioso */ });
  }

  tick();
  setInterval(tick, 10000);
})();
