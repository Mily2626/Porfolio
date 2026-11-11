/**
 * Parking Tech - Filtro de plazas por tipo.
 */
(function () {
  'use strict';

  var chips = document.querySelectorAll('[data-filtro]');
  var plazas = document.querySelectorAll('.plaza');
  if (!chips.length || !plazas.length) return;

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      chips.forEach(function (c) { c.classList.remove('activo'); });
      chip.classList.add('activo');

      var filtro = chip.getAttribute('data-filtro');
      plazas.forEach(function (p) {
        p.style.display = (filtro === 'todos' || p.getAttribute('data-tipo') === filtro) ? '' : 'none';
      });
    });
  });
})();
