<?php
/**
 * Parking Tech - Pantalla de control de barrera
 * -----------------------------------------------------------------------------
 * Detecta automaticamente si al usuario le corresponde ENTRADA o SALIDA
 * segun su reserva activa. Toda la interaccion es asincrona (sin recargas).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario  = requiere_usuario();
$raiz     = raiz_web();
$apiBase  = $raiz . '/api/abrir_barrera.php';

$titulo = 'Abrir barrera';
$activo = 'barrera';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back-link" href="<?= htmlspecialchars($raiz) ?>/vistas/dashboard.php" title="Volver al panel" aria-label="Volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h1 class="page-title">Abrir barrera</h1>
      <p class="page-sub">Habilita tu entrada o salida.</p>
    </div>
  </div>
</div>

<section class="barrera"
         id="app"
         data-api="<?= htmlspecialchars($apiBase) ?>"
         data-csrf="<?= htmlspecialchars(csrf_token()) ?>">

  <div class="estado-dispositivo" id="dispositivo">
    <span class="punto" id="disp-punto"></span>
    <span id="disp-texto">Consultando dispositivo...</span>
  </div>

  <div class="barrera-card" id="tarjeta">
    <div class="icono" id="icono">&#128663;</div>
    <h1 id="titulo-modo">Cargando...</h1>
    <p id="detalle"></p>

    <div class="datos" id="datos" hidden>
      <div><span>Reserva</span><strong id="d-codigo">-</strong></div>
      <div><span>Espacio</span><strong id="d-espacio">-</strong></div>
      <div><span>Vehiculo</span><strong id="d-vehiculo">-</strong></div>
      <div><span>Horario</span><strong id="d-horario">-</strong></div>
    </div>

    <button class="btn barrera-btn" id="accion" disabled>Esperando...</button>
    <div class="mensaje" id="mensaje"></div>
  </div>
</section>

<script src="<?= htmlspecialchars(asset('assets/js/barrera.js')) ?>"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>