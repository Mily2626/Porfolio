/**
 * Parking Tech - Interacciones de interfaz (navbar, dropdown, etc.)
 */
(function () {
  'use strict';

  var header = document.querySelector('.site-header');
  if (!header) return;

  // Menu de navegacion en movil.
  var toggle = header.querySelector('.nav-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      header.classList.toggle('nav-open');
    });
  }

  // Dropdown de usuario.
  var userMenu = header.querySelector('.user-menu');
  var trigger  = header.querySelector('.user-trigger');
  if (userMenu && trigger) {
    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      userMenu.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (!userMenu.contains(e.target)) userMenu.classList.remove('open');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') userMenu.classList.remove('open');
    });
  }
})();
