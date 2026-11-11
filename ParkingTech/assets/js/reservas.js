/**
 * Parking Tech - Simulacion de pago + confirmaciones de eliminacion.
 */
(function () {
  'use strict';

  function fmt(n) {
    return '$' + (Number(n) || 0).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function parseHorasFormulario(f) {
    var hEl = f.querySelector('[name="horas_h"]');
    var mEl = f.querySelector('[name="horas_m"]');
    if (!hEl && !mEl) return NaN;
    var h = hEl ? (parseInt(hEl.value, 10) || 0) : 0;
    var m = mEl ? (parseInt(mEl.value, 10) || 0) : 0;
    return h + (m / 60);
  }

  function abrir(modal) {
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function cerrar(modal) {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  }

  document.addEventListener('click', function (e) {
    var close = e.target.closest('[data-close]');
    if (!close) return;
    var m = close.closest('.modal');
    if (m && m.dataset.procesando !== '1') {
      cerrar(m);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal.open').forEach(function (m) {
      if (m.dataset.procesando !== '1') {
        cerrar(m);
      }
    });
  });

  /* =====================================================================
     SIMULACION DE PAGO
     ===================================================================== */
  var form = document.querySelector('form[data-pago]');
  var modalPago = document.getElementById('modal-pago');
  var btnPagar = document.getElementById('pago-confirmar');
  var btnContinuar = document.getElementById('pago-continuar');
  var btnCancelar = document.querySelector('[data-cancelar-pago]');
  var montoEl = document.getElementById('pago-monto');
  var errorEl = document.getElementById('pago-error');
  var numEl = document.getElementById('pago-num');
  var expEl = document.getElementById('pago-exp');
  var cvvEl = document.getElementById('pago-cvv');
  var titularEl = document.getElementById('pago-titular');
  var marcaEl = document.getElementById('pago-marca');

  function calcularMonto() {
    if (!form) return 0;
    var sel = form.querySelector('[name="espacio_id"]');
    if (!sel) return 0;
    var opt = sel.options[sel.selectedIndex];
    var precio = opt ? parseFloat(opt.getAttribute('data-precio') || '0') : 0;
    var horas = parseHorasFormulario(form);
    if (isNaN(horas) || horas <= 0) horas = 1;
    var facturables = Math.max(horas, 1);
    return Math.round(precio * facturables * 100) / 100;
  }

  function mostrarVista(vista) {
    if (!modalPago) return;
    modalPago.querySelectorAll('.pago-vista').forEach(function (s) {
      s.hidden = s.getAttribute('data-vista') !== vista;
    });
    if (btnPagar) btnPagar.hidden = vista !== 'form';
    if (btnContinuar) btnContinuar.hidden = vista !== 'ok';
    if (btnCancelar) btnCancelar.hidden = vista === 'proceso';
    if (vista === 'proceso') modalPago.dataset.procesando = '1';
    else delete modalPago.dataset.procesando;
  }

  function marcaDe(numero) {
    if (/^4/.test(numero)) return 'Visa';
    if (/^(5[1-5]|2[2-7])/.test(numero)) return 'Mastercard';
    if (/^3[47]/.test(numero)) return 'Amex';
    return '';
  }

  function actualizarMarca() {
    if (!marcaEl || !numEl) return;
    var brand = marcaDe(numEl.value.replace(/\D/g, ''));
    marcaEl.textContent = brand;
    marcaEl.classList.toggle('visible', brand !== '');
  }

  if (numEl) {
    numEl.addEventListener('input', function () {
      var d = numEl.value.replace(/\D/g, '').slice(0, 19);
      numEl.value = d.replace(/(.{4})/g, '$1 ').trim();
      actualizarMarca();
    });
    actualizarMarca();
  }

  if (expEl) {
    expEl.addEventListener('input', function () {
      var d = expEl.value.replace(/\D/g, '').slice(0, 4);
      expEl.value = d.length >= 3 ? d.slice(0, 2) + '/' + d.slice(2) : d;
    });
  }

  if (cvvEl) {
    cvvEl.addEventListener('input', function () {
      cvvEl.value = cvvEl.value.replace(/\D/g, '').slice(0, 4);
    });
  }

  function validarPago() {
    var d = numEl.value.replace(/\D/g, '');
    if (d.length < 15 || d.length > 16) return 'Ingresa un numero de tarjeta valido.';

    var m = /^(\d{2})\/(\d{2})$/.exec(expEl.value);
    if (!m) return 'Ingresa el vencimiento con formato MM/AA.';
    var mm = parseInt(m[1], 10);
    var yy = parseInt(m[2], 10);
    if (mm < 1 || mm > 12) return 'El mes de vencimiento es invalido.';
    if (new Date(2000 + yy, mm, 1) <= new Date()) return 'La tarjeta esta vencida.';

    if (cvvEl.value.replace(/\D/g, '').length < 3) return 'El codigo de seguridad es invalido.';
    if (!titularEl.value.trim()) return 'Ingresa el nombre del titular.';

    return null;
  }

  var pasos = [
    ['Verificando tarjeta', 700, 35],
    ['Autorizando pago', 900, 72],
    ['Confirmando operacion', 600, 100]
  ];

  function procesarPago(monto) {
    var pasoEl = document.getElementById('pago-paso');
    var barraEl = document.getElementById('pago-barra-fill');
    var i = 0;

    if (barraEl) barraEl.style.width = '8%';
    if (pasoEl) pasoEl.textContent = 'Conectando con el banco...';

    function siguiente() {
      if (i >= pasos.length) {
        finalizarPago(monto);
        return;
      }
      var p = pasos[i++];
      if (pasoEl) pasoEl.textContent = p[0] + '...';
      if (barraEl) barraEl.style.width = p[2] + '%';
      setTimeout(siguiente, p[1]);
    }

    setTimeout(siguiente, 450);
  }

  function finalizarPago(monto) {
    var d = numEl.value.replace(/\D/g, '');
    var brand = marcaDe(d) || 'Tarjeta';
    var now = new Date();
    var fecha = now.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })
      + ' ' + now.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }) + ' hs';

    var set = function (id, txt) {
      var el = document.getElementById(id);
      if (el) el.textContent = txt;
    };
    set('pago-monto-ok', fmt(monto));
    set('pago-comprobante', 'APR-' + String(Math.floor(100000 + Math.random() * 900000)));
    set('pago-tarjeta-ok', brand + ' *** ' + d.slice(-4));
    set('pago-fecha-ok', fecha);

    mostrarVista('ok');
  }

  if (form && modalPago) {
    form.addEventListener('submit', function (e) {
      if (form.getAttribute('data-confirmado') === '1') {
        return;
      }
      e.preventDefault();

      var monto = calcularMonto();
      if (montoEl) montoEl.textContent = fmt(monto);

      var horasVal = parseHorasFormulario(form);
      if (isNaN(horasVal)) horasVal = 1;
      var minimoEl = document.getElementById('pago-minimo');
      if (minimoEl) minimoEl.hidden = !(horasVal > 0 && horasVal < 1);

      if (errorEl) {
        errorEl.hidden = true;
        errorEl.textContent = '';
      }
      mostrarVista('form');
      abrir(modalPago);
    });

    if (btnPagar) {
      btnPagar.addEventListener('click', function () {
        var error = validarPago();
        if (error) {
          if (errorEl) {
            errorEl.textContent = error;
            errorEl.hidden = false;
          }
          var card = modalPago.querySelector('.modal-card');
          if (card) {
            card.classList.remove('shake');
            void card.offsetWidth;
            card.classList.add('shake');
          }
          return;
        }
        mostrarVista('proceso');
        procesarPago(calcularMonto());
      });
    }

    if (btnContinuar) {
      btnContinuar.addEventListener('click', function () {
        btnContinuar.disabled = true;
        form.setAttribute('data-confirmado', '1');
        cerrar(modalPago);
        form.submit();
      });
    }
  }

  /* =====================================================================
     ELIMINAR RESERVA
     ===================================================================== */
  var modalElim = document.getElementById('modal-eliminar');
  var formElim = document.getElementById('form-eliminar');
  var eliminando = false;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-eliminar]');
    if (!btn || !modalElim || !formElim) return;
    var codigo = document.getElementById('eliminar-codigo');
    if (codigo) codigo.textContent = btn.getAttribute('data-codigo') || '-';
    formElim.querySelector('[name="id"]').value = btn.getAttribute('data-eliminar');
    document.getElementById('eliminar-confirmar').disabled = false;
    eliminando = false;
    abrir(modalElim);
  });

  var btnElim = document.getElementById('eliminar-confirmar');
  if (btnElim && formElim) {
    btnElim.addEventListener('click', function () {
      if (eliminando) return;
      eliminando = true;
      btnElim.disabled = true;
      formElim.submit();
    });
  }

  /* =====================================================================
     ELIMINAR VEHICULO
     ===================================================================== */
  var modalVeh = document.getElementById('modal-vehiculo');
  var formVeh = document.getElementById('form-eliminar-vehiculo');
  var vehEliminando = false;

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-eliminar-vehiculo]');
    if (!btn || !modalVeh || !formVeh) return;
    var mat = document.getElementById('veh-matricula');
    if (mat) mat.textContent = btn.getAttribute('data-matricula') || '-';
    formVeh.querySelector('[name="vehiculo_id"]').value = btn.getAttribute('data-eliminar-vehiculo');
    document.getElementById('veh-confirmar').disabled = false;
    vehEliminando = false;
    abrir(modalVeh);
  });

  var btnVeh = document.getElementById('veh-confirmar');
  if (btnVeh && formVeh) {
    btnVeh.addEventListener('click', function () {
      if (vehEliminando) return;
      vehEliminando = true;
      btnVeh.disabled = true;
      formVeh.submit();
    });
  }
})();
