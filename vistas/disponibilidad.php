<?php
/**
 * Parking Tech - Disponibilidad de lugares
 * Muestra el estado de las 18 plazas del estacionamiento.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario = requiere_usuario();
$raiz    = raiz_web();

$espacios = disponibilidad_espacios();

$total   = count($espacios);
$conteo  = ['libre' => 0, 'reservado' => 0, 'ocupado' => 0, 'mantenimiento' => 0];
foreach ($espacios as $e) {
    $conteo[$e['estado']] = ($conteo[$e['estado']] ?? 0) + 1;
}

$iconoEstado = [
    'libre'         => 'bi-check-lg',
    'reservado'     => 'bi-clock-fill',
    'ocupado'       => 'bi-car-front-fill',
    'mantenimiento' => 'bi-tools',
];
$etiquetaEstado = [
    'libre'         => 'Libre',
    'reservado'     => 'Reservado',
    'ocupado'       => 'Ocupado',
    'mantenimiento' => 'Mantenimiento',
];

$titulo = 'Disponibilidad';
$activo = 'disponibilidad';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back-link" href="<?= htmlspecialchars($raiz) ?>/vistas/dashboard.php" title="Volver al panel" aria-label="Volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h1 class="page-title">Disponibilidad de lugares</h1>
      <p class="page-sub">Estado en vivo de las <?= (int) $total ?> plazas del estacionamiento.</p>
    </div>
  </div>
  <a class="btn primario" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">
    <i class="bi bi-plus-lg"></i> Reservar lugar
  </a>
</div>

<section class="stats">
  <div class="stat">
    <span class="stat-icon sky"><i class="bi bi-grid-3x3-gap-fill"></i></span>
    <div>
      <span class="stat-label">Plazas totales</span>
      <strong class="stat-value"><?= (int) $total ?></strong>
    </div>
  </div>
  <div class="stat">
    <span class="stat-icon blue"><i class="bi bi-check-circle"></i></span>
    <div>
      <span class="stat-label">Libres</span>
      <strong class="stat-value"><?= (int) $conteo['libre'] ?></strong>
    </div>
  </div>
  <div class="stat">
    <span class="stat-icon amber"><i class="bi bi-clock-history"></i></span>
    <div>
      <span class="stat-label">Reservadas</span>
      <strong class="stat-value"><?= (int) $conteo['reservado'] ?></strong>
    </div>
  </div>
  <div class="stat">
    <span class="stat-icon rojo"><i class="bi bi-car-front"></i></span>
    <div>
      <span class="stat-label">Ocupadas</span>
      <strong class="stat-value"><?= (int) $conteo['ocupado'] ?></strong>
    </div>
  </div>
</section>

<section class="card">
  <div class="leyenda">
    <span><i style="background:var(--libre)"></i> Libre</span>
    <span><i style="background:var(--reservado)"></i> Reservado</span>
    <span><i style="background:var(--ocupado)"></i> Ocupado</span>
    <span><i style="background:var(--manten)"></i> Mantenimiento</span>
  </div>

  <div class="filtros">
    <button type="button" class="chip activo" data-filtro="todos">Todas</button>
    <button type="button" class="chip" data-filtro="auto">Autos</button>
    <button type="button" class="chip" data-filtro="moto">Motos</button>
  </div>

  <div class="plazas-grid" id="plazas">
    <?php foreach ($espacios as $e):
        $estado = $e['estado'];
        $libreDesde = $e['libre_desde'] ?? null;
    ?>
      <div class="plaza <?= htmlspecialchars($estado) ?>"
           data-tipo="<?= htmlspecialchars($e['tipo']) ?>"
           data-estado="<?= htmlspecialchars($estado) ?>">
        <span class="p-estado" title="<?= htmlspecialchars($etiquetaEstado[$estado] ?? $estado) ?>">
          <i class="bi <?= htmlspecialchars($iconoEstado[$estado] ?? 'bi-circle') ?>"></i>
        </span>
        <div class="p-codigo"><?= htmlspecialchars($e['codigo']) ?></div>
        <div class="p-meta"><?= htmlspecialchars($e['ubicacion'] ?? '') ?></div>
        <div class="p-precio">$<?= number_format((float) $e['precio_hora'], 2, '.', ',') ?> /h</div>
        <?php if ($estado !== 'libre' && $libreDesde): ?>
          <div class="p-meta">Libre a las <?= htmlspecialchars(date('H:i', strtotime($libreDesde))) ?> hs</div>
        <?php elseif ($estado === 'libre'): ?>
          <div class="p-meta">Disponible ahora</div>
        <?php endif; ?>
        <span class="p-tipo">
          <i class="bi <?= $e['tipo'] === 'moto' ? 'bi-bicycle' : 'bi-car-front-fill' ?>"></i>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php
$scripts = '<script src="' . htmlspecialchars(asset('assets/js/disponibilidad.js')) . '"></script>';
require __DIR__ . '/partials/footer.php';
