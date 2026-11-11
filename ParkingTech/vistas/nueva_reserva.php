<?php
/**
 * Parking Tech - Nueva reserva
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario = requiere_usuario();
$pdo     = db();
$raiz    = raiz_web();

$vehiculos = $pdo->prepare('SELECT * FROM vehiculos WHERE usuario_id = :uid ORDER BY id DESC');
$vehiculos->execute(['uid' => $usuario['id']]);
$vehiculos = $vehiculos->fetchAll();

$espacios = $pdo->query("
    SELECT e.*, t.precio_hora
      FROM espacios e
      LEFT JOIN tarifas t ON t.activo = 1 AND t.nombre = CONCAT('Hora ', e.tipo)
     WHERE e.estado = 'libre'
     ORDER BY e.codigo
")->fetchAll();

// Precio de referencia para el aviso de monto minimo (1 hora).
$precioMinHora = (float) $pdo->query('SELECT MIN(precio_hora) FROM tarifas WHERE activo = 1')->fetchColumn();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

                // Espacio libre y sin conflictos de horario.
                $esp = $pdo->prepare('SELECT * FROM espacios WHERE id = :id');
                $esp->execute(['id' => $espacioId]);
                $espacio = $esp->fetch();

                $choque = $pdo->prepare(
                    "SELECT id FROM reservas
                      WHERE espacio_id = :eid
                        AND estado IN ('pendiente','ingresado')
                        AND inicio < :fin AND fin > :inicio
                      LIMIT 1"
                );
                $choque->execute([
                    'eid'    => $espacioId,
                    'fin'    => $fin->format('Y-m-d H:i:s'),
                    'inicio' => $inicio->format('Y-m-d H:i:s'),
                ]);

                if (!$espacio || $espacio['estado'] !== 'libre') {
                    $error = 'El espacio ya no esta disponible.';
                } elseif ($choque->fetch()) {
                    $error = 'El espacio ya tiene una reserva en ese horario.';
                } elseif (!in_array($vehiculoId, array_column($vehiculos, 'id'), true)) {
                    $error = 'Vehiculo invalido.';
                } else {
                    $tarifa   = $pdo->prepare('SELECT precio_hora FROM tarifas WHERE nombre = :n AND activo = 1');
                    $tarifa->execute(['n' => 'Hora ' . $espacio['tipo']]);
                    $precioHora = (float) (($tarifa->fetch()['precio_hora'] ?? 0) ?: 0);

                    // Minimo facturable: una hora.
                    $horasFacturables = max($horas, 1.0);
                    $monto            = round($precioHora * $horasFacturables, 2);
                    $codigo           = generar_codigo_reserva();

                    $ins = $pdo->prepare(
                        "INSERT INTO reservas
                           (codigo, usuario_id, vehiculo_id, espacio_id, inicio, fin,
                            estado, monto, pagada, pagado_en)
                         VALUES
                           (:cod, :uid, :vid, :eid, :ini, :fin,
                            'pendiente', :monto, 1, NOW())"
                    );
                    $ins->execute([
                        'cod'    => $codigo,
                        'uid'    => $usuario['id'],
                        'vid'    => $vehiculoId,
                        'eid'    => $espacioId,
                        'ini'    => $inicio->format('Y-m-d H:i:s'),
                        'fin'    => $fin->format('Y-m-d H:i:s'),
                        'monto'  => $monto,
                    ]);

                    $pdo->prepare("UPDATE espacios SET estado = 'reservado' WHERE id = :id")
                        ->execute(['id' => $espacioId]);

                    $pdo->commit();

                    // Pago simulado: se crea la reserva pagada y se vuelve al panel.
                    flash_set('ok', 'Reserva ' . $codigo . ' creada y pagada. Espacio ' . $espacio['codigo'] . '.');
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
            $error = 'No se pudo crear la reserva. Intenta de nuevo.';
        }
    }
}

$titulo = 'Nueva reserva';
$activo = 'nueva';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back-link" href="<?= htmlspecialchars($raiz) ?>/vistas/dashboard.php" title="Volver al panel" aria-label="Volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h1 class="page-title">Nueva reserva</h1>
      <p class="page-sub">Elegi el espacio y el horario para tu estadia.</p>
    </div>
  </div>
</div>

<section class="tarjeta estrecha">
  <h2>Reservar lugar</h2>
  <p class="muted">Elegi el espacio y el horario. El pago se procesa de forma simulada al confirmar.</p>
  <?php if ($error): ?>
    <div class="alerta error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$vehiculos): ?>
    <div class="alerta error">
      Primero carga un vehiculo para poder reservar.
      <a href="<?= htmlspecialchars($raiz) ?>/vistas/nuevo_vehiculo.php">Agregar vehiculo</a>
    </div>
  <?php elseif (!$espacios): ?>
    <div class="alerta error">No hay espacios libres disponibles en este momento.</div>
  <?php else: ?>

  <form method="post" data-pago>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <label>Vehiculo
      <select name="vehiculo_id" required>
        <option value="">Seleccionar...</option>
        <?php foreach ($vehiculos as $v): ?>
          <option value="<?= (int) $v['id'] ?>">
            <?= htmlspecialchars($v['matricula'] . ($v['marca'] ? ' · ' . $v['marca'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Espacio
      <select name="espacio_id" required>
        <option value="">Seleccionar...</option>
        <?php foreach ($espacios as $e): ?>
          <option value="<?= (int) $e['id'] ?>" data-precio="<?= htmlspecialchars((string) (float) $e['precio_hora']) ?>">
            <?= htmlspecialchars($e['codigo'] . ' · ' . $e['tipo'] . ' · $' . number_format((float) $e['precio_hora'], 2, '.', ',') . '/h') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="grid2">
      <label>Fecha
        <input type="date" name="fecha" required min="<?= date('Y-m-d') ?>">
      </label>
      <label>Hora de inicio
        <input type="time" name="hora_inicio" required>
      </label>
    </div>

    <label>Cuantas horas vas a estar
      <div class="grid2">
        <div>
          <input type="number" name="horas_h" id="horas_h" min="0" max="24" value="1" required>
          <span class="duracion-suf">horas</span>
        </div>
        <div>
          <input type="number" name="horas_m" id="horas_m" min="0" max="59" value="0" required>
          <span class="duracion-suf">minutos</span>
        </div>
      </div>
    </label>

    <div class="nota-minimo">
      <i class="bi bi-info-circle"></i>
      El monto minimo de pago es el de <strong>1 hora</strong> de estacionamiento
      (desde $<?= number_format($precioMinHora, 2, '.', ',') ?>). Si tu estadia es menor a 1 hora, se cobra 1 hora.
    </div>

    <button type="submit" class="btn primario">Reservar y pagar</button>
  </form>

  <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/modales.php'; ?>

<?php
$scripts = '<script src="' . htmlspecialchars(asset('assets/js/reservas.js')) . '"></script>';
require __DIR__ . '/partials/footer.php';
