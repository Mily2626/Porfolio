<?php
/**
 * Parking Tech - Panel del usuario
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario = requiere_usuario();
$pdo     = db();
$raiz    = raiz_web();

$errorVeh = null;
$okVeh    = null;

// Eliminar vehiculo del panel.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_vehiculo') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $errorVeh = 'Token de seguridad invalido. Volve a intentar.';
    } else {
        [$borrado, $err] = eliminar_vehiculo($pdo, (int) $usuario['id'], (int) ($_POST['vehiculo_id'] ?? 0));
        if ($borrado) {
            $okVeh = 'Vehiculo eliminado correctamente.';
        } else {
            $errorVeh = $err;
        }
    }
}

// Vehiculos del usuario.
$stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE usuario_id = :uid ORDER BY id DESC');
$stmt->execute(['uid' => $usuario['id']]);
$vehiculos = $stmt->fetchAll();

// Reservas del usuario.
$stmt = $pdo->prepare(
    "SELECT r.*, e.codigo AS espacio_codigo, v.matricula
       FROM reservas r
       JOIN espacios e ON e.id = r.espacio_id
       JOIN vehiculos v ON v.id = r.vehiculo_id
      WHERE r.usuario_id = :uid
      ORDER BY r.inicio DESC"
);
$stmt->execute(['uid' => $usuario['id']]);
$reservas = $stmt->fetchAll();

// Metricas rapidas (calculadas en una sola pasada).
$activas = 0;
$minutos = 0;
foreach ($reservas as $r) {
    if (in_array($r['estado'], ['pendiente', 'ingresado'], true)) {
        $activas++;
    }
    $minutos += (strtotime($r['fin']) - strtotime($r['inicio'])) / 60;
}
$horas = round($minutos / 60, 1);

// Precio por hora vigente (tarifa de autos; si no existe, la primera activa).
$tarifas = $pdo->query(
    "SELECT nombre, precio_hora
       FROM tarifas
      WHERE activo = 1
      ORDER BY (nombre = 'Hora auto') DESC, precio_hora DESC"
)->fetchAll();
$precioHora = (float) ($tarifas[0]['precio_hora'] ?? 0);

// Contexto activo para la barrera.
$contexto = contexto_barrera((int) $usuario['id']);

$titulo = 'Mi panel';
$activo = 'panel';
$flash  = flash_get();
require __DIR__ . '/partials/header.php';

// Textos e iconos del aviso segun el modo.
$avisoMap = [
    'entrada' => ['titulo' => 'Tu reserva esta lista', 'icono' => 'bi-box-arrow-in-right', 'texto' => 'Ya podes ingresar al estacionamiento.'],
    'salida'  => ['titulo' => 'Tu auto esta estacionado', 'icono' => 'bi-box-arrow-right', 'texto' => 'Cuando te vayas, habilita la salida.'],
    'espera'  => ['titulo' => 'Tu reserva esta proxima', 'icono' => 'bi-hourglass-split', 'texto' => 'La barrera se habilitara automaticamente a horario.'],
];
$aviso = $avisoMap[$contexto['modo']] ?? null;
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Hola, <?= htmlspecialchars(explode(' ', $usuario['nombre'])[0]) ?></h1>
    <p class="page-sub">Este es el resumen de tu estacionamiento.</p>
  </div>
  <div class="row-actions">
    <a class="btn" href="<?= htmlspecialchars($raiz) ?>/vistas/disponibilidad.php">
      <i class="bi bi-grid-3x3-gap"></i> Disponibilidad
    </a>
    <a class="btn primario" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">
      <i class="bi bi-plus-lg"></i> Nueva reserva
    </a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alerta <?= $flash['tipo'] === 'ok' ? 'ok' : 'error' ?>">
    <i class="bi <?= $flash['tipo'] === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
    <?= htmlspecialchars($flash['mensaje']) ?>
  </div>
<?php endif; ?>

<section class="stats">
  <div class="stat">
    <span class="stat-icon blue"><i class="bi bi-calendar-check"></i></span>
    <div>
      <span class="stat-label">Reservas activas</span>
      <strong class="stat-value"><?= (int) $activas ?></strong>
    </div>
  </div>
  <div class="stat">
    <span class="stat-icon sky"><i class="bi bi-car-front"></i></span>
    <div>
      <span class="stat-label">Vehiculos registrados</span>
      <strong class="stat-value"><?= count($vehiculos) ?></strong>
    </div>
  </div>
  <div class="stat">
    <span class="stat-icon amber"><i class="bi bi-clock-history"></i></span>
    <div>
      <span class="stat-label">Horas reservadas</span>
      <strong class="stat-value"><?= sprintf('%d:%02d', intdiv((int) $minutos, 60), (int) $minutos % 60) ?><small> hs</small></strong>
    </div>
  </div>
  <div class="stat">
    <span class="stat-icon green"><i class="bi bi-tag-fill"></i></span>
    <div>
      <span class="stat-label">Precio por hora</span>
      <strong class="stat-value">$<?= number_format($precioHora, 2, '.', ',') ?><small>/h</small></strong>
    </div>
  </div>
</section>

<?php if ($aviso): ?>
  <section class="aviso">
    <span class="aviso-icon"><i class="bi <?= $aviso['icono'] ?>"></i></span>
    <div class="aviso-body">
      <h2><?= $aviso['titulo'] ?></h2>
      <p>
        <?= $aviso['texto'] ?>
        <?php if ($contexto['reserva']): ?>
          Reserva <strong><?= htmlspecialchars($contexto['reserva']['codigo']) ?></strong>
          · Espacio <?= htmlspecialchars($contexto['reserva']['espacio_codigo']) ?>
          · <?= htmlspecialchars($contexto['reserva']['matricula']) ?>
        <?php endif; ?>
      </p>
    </div>
    <a class="btn primario" href="<?= htmlspecialchars($raiz) ?>/vistas/abrir_barrera.php">
      Ir a la barrera <i class="bi bi-arrow-right"></i>
    </a>
  </section>
<?php endif; ?>

<div class="tablero">
  <section class="card">
    <div class="card-head">
      <div>
        <h2>Mis reservas</h2>
        <p class="card-sub">Historial y estado de tus reservas</p>
      </div>
      <div class="row-actions">
        <a class="btn ghost" href="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php">
          <i class="bi bi-journal-text"></i> Gestionar
        </a>
        <a class="btn ghost" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">
          <i class="bi bi-plus-lg"></i> Nueva
        </a>
      </div>
    </div>

    <?php if (!$reservas): ?>
      <div class="empty">
        <i class="bi bi-calendar-x"></i>
        <p>Todavia no tenes reservas.</p>
        <a class="btn primario" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">Crear la primera</a>
      </div>
    <?php else: ?>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead>
            <tr>
              <th class="hide-sm">Codigo</th>
              <th>Espacio</th>
              <th>Vehiculo</th>
              <th>Inicio</th>
              <th class="hide-sm">Fin</th>
              <th class="num">Monto</th>
              <th>Pago</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reservas as $r): ?>
              <tr>
                <td class="hide-sm mono muted"><?= htmlspecialchars($r['codigo']) ?></td>
                <td><span class="space-tag"><i class="bi bi-p-square"></i> <?= htmlspecialchars($r['espacio_codigo']) ?></span></td>
                <td class="mono"><?= htmlspecialchars($r['matricula']) ?></td>
                <td><?= htmlspecialchars(fecha_amigable($r['inicio'])) ?></td>
                <td class="hide-sm muted"><?= htmlspecialchars(fecha_amigable($r['fin'])) ?></td>
                <td class="num monto">$<?= number_format((float) $r['monto'], 2, '.', ',') ?></td>
                <td>
                  <?php if ((int) $r['pagada'] === 1): ?>
                    <span class="badge badge-success"><i class="bi bi-check-circle-fill"></i> Pagado</span>
                  <?php else: ?>
                    <span class="badge badge-muted">Sin pagar</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-<?= htmlspecialchars($r['estado']) ?>"><?= htmlspecialchars(ucfirst($r['estado'])) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Mis vehiculos</h2>
        <p class="card-sub"><?= count($vehiculos) ?> registrado<?= count($vehiculos) === 1 ? '' : 's' ?></p>
      </div>
      <a class="btn ghost" href="<?= htmlspecialchars($raiz) ?>/vistas/nuevo_vehiculo.php">
        <i class="bi bi-plus-lg"></i> Registrar
      </a>
    </div>

    <?php if ($errorVeh): ?>
      <div class="alerta error"><?= htmlspecialchars($errorVeh) ?></div>
    <?php endif; ?>
    <?php if ($okVeh): ?>
      <div class="alerta ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($okVeh) ?></div>
    <?php endif; ?>

    <?php if (!$vehiculos): ?>
      <div class="empty">
        <i class="bi bi-car-front"></i>
        <p>No tenes vehiculos cargados.</p>
        <a class="btn primario" href="<?= htmlspecialchars($raiz) ?>/vistas/nuevo_vehiculo.php">Agregar vehiculo</a>
      </div>
    <?php else: ?>
      <div class="vehicles">
        <?php foreach ($vehiculos as $v):
            $desc = trim(($v['marca'] ?? '') . ' ' . ($v['modelo'] ?? ''));
            if ($v['color']) {
                $desc = trim($desc . ' · ' . $v['color']);
            }
        ?>
          <div class="vehicle">
            <span class="vehicle-icon"><i class="bi bi-car-front-fill"></i></span>
            <div class="vehicle-info">
              <span class="plate"><?= htmlspecialchars($v['matricula']) ?></span>
              <span class="vehicle-desc"><?= $desc !== '' ? htmlspecialchars($desc) : 'Sin datos' ?></span>
            </div>
            <button type="button" class="vehicle-del"
                    data-eliminar-vehiculo="<?= (int) $v['id'] ?>"
                    data-matricula="<?= htmlspecialchars($v['matricula']) ?>"
                    title="Eliminar vehiculo" aria-label="Eliminar vehiculo">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<form id="form-eliminar-vehiculo" method="post" hidden>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
  <input type="hidden" name="accion" value="eliminar_vehiculo">
  <input type="hidden" name="vehiculo_id" value="">
</form>

<?php require __DIR__ . '/partials/modales.php'; ?>

<?php
$scripts = '<script src="' . htmlspecialchars(asset('assets/js/reservas.js')) . '"></script>';
require __DIR__ . '/partials/footer.php';
