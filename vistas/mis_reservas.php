<?php
/**
 * Parking Tech - Mis reservas
 * Listado con acciones para modificar o eliminar reservas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario = requiere_usuario();
$pdo     = db();
$raiz    = raiz_web();

$error = null;
$ok    = null;

// ---------------------------------------------------------------------------
// Eliminar reserva (con confirmacion en el navegador; el pago no se devuelve)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad invalido. Volve a intentar.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM reservas WHERE id = :id AND usuario_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $usuario['id']]);
        $reserva = $stmt->fetch();

        if (!$reserva) {
            $error = 'No encontramos esa reserva.';
        } elseif ($reserva['estado'] === 'ingresado') {
            $error = 'No podes eliminar una reserva con el vehiculo adentro. Primero habilita la salida.';
        } else {
            try {
                $pdo->beginTransaction();

                // Si estaba pendiente, el espacio queda reservado por esta reserva: se libera.
                if ($reserva['estado'] === 'pendiente') {
                    $pdo->prepare("UPDATE espacios SET estado = 'libre' WHERE id = :id AND estado = 'reservado'")
                        ->execute(['id' => $reserva['espacio_id']]);
                }

                $pdo->prepare('DELETE FROM reservas WHERE id = :id')->execute(['id' => $id]);
                $pdo->commit();

                $ok = 'Reserva ' . $reserva['codigo'] . ' eliminada. El pago no fue devuelto.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'No se pudo eliminar la reserva. Intenta de nuevo.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Listado
// ---------------------------------------------------------------------------
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

$titulo = 'Mis reservas';
$activo = 'reservas';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back-link" href="<?= htmlspecialchars($raiz) ?>/vistas/dashboard.php" title="Volver al panel" aria-label="Volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h1 class="page-title">Mis reservas</h1>
      <p class="page-sub">Modificar o eliminar tus reservas.</p>
    </div>
  </div>
  <a class="btn primario" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">
    <i class="bi bi-plus-lg"></i> Nueva reserva
  </a>
</div>

<?php if ($error): ?>
  <div class="alerta error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
  <div class="alerta ok"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>

<section class="card">
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
            <th>Estado</th>
            <th class="num">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reservas as $r): ?>
            <?php $pendiente = $r['estado'] === 'pendiente'; ?>
            <tr>
              <td class="hide-sm mono muted"><?= htmlspecialchars($r['codigo']) ?></td>
              <td><span class="space-tag"><i class="bi bi-p-square"></i> <?= htmlspecialchars($r['espacio_codigo']) ?></span></td>
              <td class="mono"><?= htmlspecialchars($r['matricula']) ?></td>
              <td><?= htmlspecialchars(fecha_amigable($r['inicio'])) ?></td>
              <td class="hide-sm muted"><?= htmlspecialchars(fecha_amigable($r['fin'])) ?></td>
              <td class="num monto">$<?= number_format((float) $r['monto'], 2, '.', ',') ?></td>
              <td>
                <span class="badge badge-<?= htmlspecialchars($r['estado']) ?>"><?= htmlspecialchars(ucfirst($r['estado'])) ?></span>
              </td>
              <td class="num">
                <span class="row-actions">
                  <?php if ($pendiente): ?>
                    <a class="btn-icon editar"
                       href="<?= htmlspecialchars($raiz) ?>/vistas/editar_reserva.php?id=<?= (int) $r['id'] ?>"
                       title="Modificar reserva" aria-label="Modificar reserva">
                      <i class="bi bi-pencil"></i>
                    </a>
                  <?php endif; ?>

                  <?php if ($r['estado'] !== 'ingresado'): ?>
                    <button type="button" class="btn-icon eliminar"
                            data-eliminar="<?= (int) $r['id'] ?>"
                            data-codigo="<?= htmlspecialchars($r['codigo']) ?>"
                            title="Eliminar reserva" aria-label="Eliminar reserva">
                      <i class="bi bi-trash"></i>
                    </button>
                  <?php else: ?>
                    <span class="muted" style="font-size:.8rem;">En curso</span>
                  <?php endif; ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="font-size:.82rem;margin:.9rem 0 0;">
      <i class="bi bi-info-circle"></i> Solo se pueden modificar las reservas pendientes. El pago no se devuelve al eliminar.
    </p>
  <?php endif; ?>
</section>

<form id="form-eliminar" method="post" action="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php" hidden>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
  <input type="hidden" name="accion" value="eliminar">
  <input type="hidden" name="id" value="">
</form>

<?php require __DIR__ . '/partials/modales.php'; ?>

<?php
$scripts = '<script src="' . htmlspecialchars(asset('assets/js/reservas.js')) . '"></script>';
require __DIR__ . '/partials/footer.php';
