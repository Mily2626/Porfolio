<?php
/**
 * Parking Tech - Modificar reserva
 * Solo se pueden modificar reservas en estado "pendiente".
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario = requiere_usuario();
$pdo     = db();
$raiz    = raiz_web();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT r.*, e.codigo AS espacio_codigo
       FROM reservas r
       JOIN espacios e ON e.id = r.espacio_id
      WHERE r.id = :id AND r.usuario_id = :uid"
);
$stmt->execute(['id' => $id, 'uid' => $usuario['id']]);
$reserva = $stmt->fetch();

if (!$reserva) {
    header('Location: ' . $raiz . '/vistas/mis_reservas.php');
    exit;
}

$bloqueada = $reserva['estado'] !== 'pendiente';

$vehiculos = $pdo->prepare('SELECT * FROM vehiculos WHERE usuario_id = :uid ORDER BY id DESC');
$vehiculos->execute(['uid' => $usuario['id']]);
$vehiculos = $vehiculos->fetchAll();

function cargar_espacios(PDO $pdo, int $actual): array
{
    $sql = "SELECT e.*, t.precio_hora
              FROM espacios e
              LEFT JOIN tarifas t ON t.activo = 1 AND t.nombre = CONCAT('Hora ', e.tipo)
             WHERE e.estado = 'libre' OR e.id = :actual
             ORDER BY e.codigo";
    $st = $pdo->prepare($sql);
    $st->execute(['actual' => $actual]);
    return $st->fetchAll();
}

$espacios = cargar_espacios($pdo, (int) $reserva['espacio_id']);

$error = null;

if (!$bloqueada && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehiculoId = (int) ($_POST['vehiculo_id'] ?? 0);
    $espacioId  = (int) ($_POST['espacio_id'] ?? 0);
    $fecha      = trim((string) ($_POST['fecha'] ?? ''));
    $horaInicio = trim((string) ($_POST['hora_inicio'] ?? ''));
    $horas      = (int) ($_POST['horas_h'] ?? 0) + ((int) ($_POST['horas_m'] ?? 0) / 60);

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad invalido. Volve a intentar.';
    } elseif (!$vehiculoId || !$espacioId) {
        $error = 'Selecciona un vehiculo y un espacio.';
    } elseif ($fecha === '' || $horaInicio === '') {
        $error = 'Ingresa fecha y hora de inicio.';
    } elseif ($horas < 0.5 || $horas > 24) {
        $error = 'Ingresa entre 0.5 y 24 horas de estadia.';
    } else {
        try {
            $inicio = new DateTimeImmutable($fecha . ' ' . $horaInicio);
            $fin    = $inicio->add(new DateInterval('PT' . (int) round($horas * 60) . 'M'));
            $ahora  = new DateTimeImmutable('now');

            if ($inicio < $ahora) {
                $error = 'La reserva debe iniciar en el futuro.';
            } else {
                $pdo->beginTransaction();

                $esp = $pdo->prepare('SELECT * FROM espacios WHERE id = :id');
                $esp->execute(['id' => $espacioId]);
                $espacio = $esp->fetch();

                $esMismo = (int) $reserva['espacio_id'] === $espacioId;

                $choque = $pdo->prepare(
                    "SELECT id FROM reservas
                      WHERE espacio_id = :eid
                        AND id <> :self
                        AND estado IN ('pendiente','ingresado')
                        AND inicio < :fin AND fin > :inicio
                      LIMIT 1"
                );
                $choque->execute([
                    'eid'    => $espacioId,
                    'self'   => (int) $reserva['id'],
                    'fin'    => $fin->format('Y-m-d H:i:s'),
                    'inicio' => $inicio->format('Y-m-d H:i:s'),
                ]);

                if (!$espacio || (!$esMismo && $espacio['estado'] !== 'libre')) {
                    $error = 'El espacio ya no esta disponible.';
                } elseif ($choque->fetch()) {
                    $error = 'El espacio ya tiene una reserva en ese horario.';
                } elseif (!in_array($vehiculoId, array_column($vehiculos, 'id'), true)) {
                    $error = 'Vehiculo invalido.';
                } else {
                    $tarifa = $pdo->prepare('SELECT precio_hora FROM tarifas WHERE nombre = :n AND activo = 1');
                    $tarifa->execute(['n' => 'Hora ' . $espacio['tipo']]);
                    $precioHora = (float) (($tarifa->fetch()['precio_hora'] ?? 0) ?: 0);

                    // Minimo facturable: una hora.
                    $horasFacturables = max($horas, 1.0);
                    $monto            = round($precioHora * $horasFacturables, 2);

                    $upd = $pdo->prepare(
                        "UPDATE reservas
                            SET vehiculo_id = :vid,
                                espacio_id  = :eid,
                                inicio      = :ini,
                                fin         = :fin,
                                monto       = :monto,
                                pagada      = 1,
                                pagado_en   = NOW()
                          WHERE id = :id"
                    );
                    $upd->execute([
                        'vid'   => $vehiculoId,
                        'eid'   => $espacioId,
                        'ini'   => $inicio->format('Y-m-d H:i:s'),
                        'fin'   => $fin->format('Y-m-d H:i:s'),
                        'monto' => $monto,
                        'id'    => (int) $reserva['id'],
                    ]);

                    // Reordenar estados de espacios si cambio de espacio.
                    if (!$esMismo) {
                        $pdo->prepare("UPDATE espacios SET estado = 'libre' WHERE id = :id AND estado = 'reservado'")
                            ->execute(['id' => (int) $reserva['espacio_id']]);
                        $pdo->prepare("UPDATE espacios SET estado = 'reservado' WHERE id = :id")
                            ->execute(['id' => $espacioId]);
                    }

                    $pdo->commit();

                    flash_set('ok', 'Reserva ' . $reserva['codigo'] . ' actualizada. Pago simulado del nuevo total.');
                    header('Location: ' . $raiz . '/vistas/dashboard.php');
                    exit;
                }
            }

            if ($error !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'No se pudo actualizar la reserva. Intenta de nuevo.';
        }
    }
}

$titulo = 'Modificar reserva';
$activo = 'reservas';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back-link" href="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php" title="Volver a mis reservas" aria-label="Volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h1 class="page-title">Modificar reserva</h1>
      <p class="page-sub">Reserva <span class="mono"><?= htmlspecialchars($reserva['codigo']) ?></span></p>
    </div>
  </div>
</div>

<section class="tarjeta estrecha">
  <?php if ($error): ?>
    <div class="alerta error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($bloqueada): ?>
    <div class="alerta error">
      Esta reserva esta en estado <strong><?= htmlspecialchars($reserva['estado']) ?></strong> y ya no se puede modificar.
    </div>
    <a class="btn" href="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php"><i class="bi bi-arrow-left"></i> Volver a mis reservas</a>
  <?php else: ?>

  <form method="post" data-pago>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $reserva['id'] ?>">

    <label>Vehiculo
      <select name="vehiculo_id" required>
        <?php foreach ($vehiculos as $v): ?>
          <option value="<?= (int) $v['id'] ?>" <?= (int) $v['id'] === (int) $reserva['vehiculo_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($v['matricula'] . ($v['marca'] ? ' · ' . $v['marca'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Espacio
      <select name="espacio_id" required>
        <?php foreach ($espacios as $e): ?>
          <option value="<?= (int) $e['id'] ?>"
                  data-precio="<?= htmlspecialchars((string) (float) $e['precio_hora']) ?>"
                  <?= (int) $e['id'] === (int) $reserva['espacio_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($e['codigo'] . ' · ' . $e['tipo'] . ' · $' . number_format((float) $e['precio_hora'], 2, '.', ',') . '/h') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php
      $inicioVal = new DateTimeImmutable($reserva['inicio']);
      $finVal    = new DateTimeImmutable($reserva['fin']);
      $horasActual = round(($finVal->getTimestamp() - $inicioVal->getTimestamp()) / 3600, 2);
      $horasH = (int) floor($horasActual);
      $horasM = (int) round(($horasActual - $horasH) * 60);
      if ($horasM === 60) { $horasH++; $horasM = 0; }
    ?>

    <div class="grid2">
      <label>Fecha
        <input type="date" name="fecha" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($inicioVal->format('Y-m-d')) ?>">
      </label>
      <label>Hora de inicio
        <input type="time" name="hora_inicio" required value="<?= htmlspecialchars($inicioVal->format('H:i')) ?>">
      </label>
    </div>

    <label>Cuantas horas vas a estar
      <div class="grid2">
        <div>
          <input type="number" name="horas_h" id="horas_h" min="0" max="24" value="<?= (int) $horasH ?>" required>
          <span class="duracion-suf">horas</span>
        </div>
        <div>
          <input type="number" name="horas_m" id="horas_m" min="0" max="59" value="<?= (int) $horasM ?>" required>
          <span class="duracion-suf">minutos</span>
        </div>
      </div>
    </label>

    <div class="nota-minimo">
      <i class="bi bi-info-circle"></i>
      El monto minimo de pago es el de <strong>1 hora</strong> de estacionamiento.
      Si tu estadia es menor a 1 hora, se cobra 1 hora.
    </div>

    <div class="form-actions" style="display:flex;gap:.6rem;flex-wrap:wrap;">
      <button type="submit" class="btn primario"><i class="bi bi-check-lg"></i> Guardar y pagar</button>
      <a class="btn" href="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php">Cancelar</a>
    </div>
  </form>

  <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/modales.php'; ?>

<?php
$scripts = '<script src="' . htmlspecialchars(asset('assets/js/reservas.js')) . '"></script>';
require __DIR__ . '/partials/footer.php';
