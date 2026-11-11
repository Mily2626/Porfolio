/**
 * Parking Tech - Control asincrono de barrera
 * -----------------------------------------------------------------------------
 * Consulta periodicamente el estado de la reserva y del ESP32, y envia la
 * accion de entrada/salida sin recargar la pagina.
 */
(function () {
  'use strict';

  var app = document.getElementById('app');
  if (!app) return;

  var API  = app.dataset.api;
  var CSRF = app.dataset.csrf;

  var elDispPunto = document.getElementById('disp-punto');
  var elDispTexto = document.getElementById('disp-texto');
  var elTitulo    = document.getElementById('titulo-modo');
  var elDetalle   = document.getElementById('detalle');
  var elIcono     = document.getElementById('icono');
  var elDatos     = document.getElementById('datos');
  var elCodigo    = document.getElementById('d-codigo');
  var elEspacio   = document.getElementById('d-espacio');
  var elVehiculo  = document.getElementById('d-vehiculo');
  var elHorario   = document.getElementById('d-horario');
  var elBoton     = document.getElementById('accion');
  var elMensaje   = document.getElementById('mensaje');

  var POLL_MS = 3000;
  var modoActual = null;
  var enviando = false;

  function fmtHorario(ini, fin) {
    if (!ini || !fin) return '-';
    return ini.substring(0, 16).replace('T', ' ') + '  ->  ' + fin.substring(5, 16).replace('T', ' ');
  }

  function pintar(data) {
    var disp = data.dispositivo || {};
    var enLinea = !!disp.en_linea;

    elDispPunto.className = 'punto ' + (enLinea ? 'online' : 'offline');
    elDispTexto.textContent = enLinea
      ? 'Barrera en linea · ' + (disp.estado || 'desconocido')
      : 'Barrera fuera de linea';

    var r = data.reserva;
    modoActual = data.modo;

    if (r) {
      elDatos.hidden = false;
      elCodigo.textContent   = r.codigo || '-';
      elEspacio.textContent  = r.espacio_codigo || '-';
      elVehiculo.textContent = r.matricula || '-';
      elHorario.textContent  = fmtHorario(r.inicio, r.fin);
    } else {
      elDatos.hidden = true;
    }

    elBoton.classList.remove('entrada', 'salida');

    switch (data.modo) {
      case 'entrada':
        elIcono.textContent = '\u2B06\uFE0F';
        elTitulo.textContent = 'Acceso de ENTRADA';
        elDetalle.textContent = 'Tu reserva esta vigente. Abri la barrera de ingreso.';
        elBoton.textContent = 'Abrir barrera de entrada';
        elBoton.className = 'btn barrera-btn entrada';
        elBoton.disabled = enviando;
        break;

      case 'salida':
        elIcono.textContent = '\u2B07\uFE0F';
        elTitulo.textContent = 'Acceso de SALIDA';
        elDetalle.textContent = 'Tu vehiculo esta adentro. Abri la barrera de egreso.';
        elBoton.textContent = 'Abrir barrera de salida';
        elBoton.className = 'btn barrera-btn salida';
        elBoton.disabled = enviando;
        break;

      case 'espera':
        elIcono.textContent = '\u23F3';
        elTitulo.textContent = 'Reserva programada';
        elDetalle.textContent = 'Aun no llego la hora. La barrera se habilitara automaticamente.';
        elBoton.textContent = 'Disponible ' + (r ? String(r.inicio).substring(11, 16) : '') + ' hs';
        elBoton.className = 'btn barrera-btn';
        elBoton.disabled = true;
        break;

      default:
        elIcono.textContent = '\u{1F6D1}';
        elTitulo.textContent = 'Sin reserva activa';
        elDetalle.textContent = 'No tenes una reserva habilitada en este momento.';
        elBoton.textContent = 'Sin acciones disponibles';
        elBoton.className = 'btn barrera-btn';
        elBoton.disabled = true;
    }
  }

  function cargarEstado() {
    return fetch(API + '?accion=estado', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) pintar(data);
      })
      .catch(function () {
        elDispTexto.textContent = 'Sin conexion con el servidor';
        elDispPunto.className = 'punto offline';
      });
  }

  function accionar() {
    if (enviando || (modoActual !== 'entrada' && modoActual !== 'salida')) return;

    enviando = true;
    elBoton.disabled = true;
    elMensaje.className = 'mensaje';
    elMensaje.textContent = 'Procesando...';

    var cuerpo = new URLSearchParams();
    cuerpo.append('accion', modoActual);
    cuerpo.append('csrf', CSRF);

    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: cuerpo.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          elMensaje.className = 'mensaje ok';
          elMensaje.textContent = data.mensaje || 'Barrera accionada.';
        } else {
          elMensaje.className = 'mensaje error';
          elMensaje.textContent = (data && data.error) || 'No se pudo accionar la barrera.';
        }
      })
      .catch(function () {
        elMensaje.className = 'mensaje error';
        elMensaje.textContent = 'Error de red al accionar la barrera.';
      })
      .then(function () {
        setTimeout(function () {
          enviando = false;
          cargarEstado();
        }, 1500);
      });
  }

  elBoton.addEventListener('click', accionar);

  cargarEstado();
  setInterval(cargarEstado, POLL_MS);
})();