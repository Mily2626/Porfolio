<?php
/**
 * Parking Tech - Agregar vehiculo
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$usuario = requiere_usuario();
$pdo     = db();
$raiz    = raiz_web();

$error = null;
$ok    = null;
$errorVeh = null;
$okVeh    = null;
$datos = ['matricula' => '', 'marca' => '', 'modelo' => '', 'color' => ''];

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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($datos) as $campo) {
        $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    // Normalizar patente: sin espacios y en mayusculas.
    $datos['matricula'] = strtoupper((string) preg_replace('/\s+/', '', $datos['matricula']));

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad invalido. Volve a intentar.';
    } elseif ($datos['matricula'] === '') {
        $error = 'La patente / matricula es obligatoria.';
    } elseif (strlen($datos['matricula']) > 15) {
        $error = 'La patente es demasiado larga.';
    } else {
        try {
            $ins = $pdo->prepare(
                "INSERT INTO vehiculos (usuario_id, matricula, marca, modelo, color)
                 VALUES (:u, :m, :ma, :mo, :c)"
            );
            $ins->execute([
                'u'  => $usuario['id'],
                'm'  => $datos['matricula'],
                'ma' => $datos['marca'] ?: null,
                'mo' => $datos['modelo'] ?: null,
                'c'  => $datos['color'] ?: null,
            ]);
            $ok    = 'Vehiculo ' . $datos['matricula'] . ' agregado correctamente.';
            $datos = ['matricula' => '', 'marca' => '', 'modelo' => '', 'color' => ''];
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000'
                ? 'Esa patente ya esta registrada.'
                : 'No se pudo guardar el vehiculo. Intenta de nuevo.';
        }
    }
}

// Vehiculos actuales del usuario.
$stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE usuario_id = :uid ORDER BY id DESC');
$stmt->execute(['uid' => $usuario['id']]);
$vehiculos = $stmt->fetchAll();

$titulo = 'Agregar vehiculo';
$activo = '';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <a class="back-link" href="<?= htmlspecialchars($raiz) ?>/vistas/dashboard.php" title="Volver al panel" aria-label="Volver">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h1 class="page-title">Agregar vehiculo</h1>
      <p class="page-sub">Suma un vehiculo para poder reservar tu lugar.</p>
    </div>
  </div>
</div>

<div class="tablero">
  <section class="card">
    <div class="card-head">
      <div>
        <h2>Datos del vehiculo</h2>
        <p class="card-sub">La patente es obligatoria; el resto es opcional</p>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alerta error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
      <div class="alerta ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($ok) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

      <label>Patente / matricula <span class="req">*</span>
        <input type="text" name="matricula" value="<?= htmlspecialchars($datos['matricula']) ?>"
               placeholder="AB123CD" maxlength="15" required autofocus
               style="text-transform:uppercase">
      </label>

      <div class="grid2">
        <label>Marca
          <input type="text" name="marca" value="<?= htmlspecialchars($datos['marca']) ?>" placeholder="Toyota">
        </label>
        <label>Modelo
          <input type="text" name="modelo" value="<?= htmlspecialchars($datos['modelo']) ?>" placeholder="Corolla">
        </label>
      </div>

      <label>Color
        <input type="text" name="color" value="<?= htmlspecialchars($datos['color']) ?>" placeholder="Gris">
      </label>

      <div class="form-actions">
        <button type="submit" class="btn primario">
          <i class="bi bi-check-lg"></i> Guardar vehiculo
        </button>
      </div>
    </form>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Mis vehiculos</h2>
        <p class="card-sub"><?= count($vehiculos) ?> registrado<?= count($vehiculos) === 1 ? '' : 's' ?></p>
      </div>
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
        <p>Todavia no tenes vehiculos.</p>
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
