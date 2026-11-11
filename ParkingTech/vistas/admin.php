<?php
/**
 * Parking Tech - Panel de administracion
 * -----------------------------------------------------------------------------
 * Acceso exclusivo para usuarios con rol 'admin'.
 * Reune:
 *   - Estadisticas del estacionamiento
 *   - Gestion de tarifas (autos y motos)
 *   - Control de apertura de barreras (manual + estado del dispositivo)
 *   - Control de ingresos y de pagos
 *   - Control de reservas y plazas
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/funciones.php';

$admin = requiere_admin();
$pdo   = db();
$raiz  = raiz_web();

// ---------------------------------------------------------------------------
// Acciones (POST -> redireccion, patron PRG)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $volver = $raiz . '/vistas/admin.php';

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        flash_set('error', 'Token de seguridad invalido. Volve a intentar.');
        header('Location: ' . $volver);
        exit;
    }

    switch ($accion) {
        case 'actualizar_tarifa':
            [$ok, $err] = tarifa_actualizar(
                (int) ($_POST['tarifa_id'] ?? 0),
                (float) ($_POST['precio_hora'] ?? 0),
                isset($_POST['activo'])
            );
            flash_set($ok ? 'ok' : 'error', $ok ? 'Tarifa actualizada correctamente.' : (string) $err);
            header('Location: ' . $volver . '#tarifas');
            exit;

        case 'abrir_barrera':
            $tipo = (string) ($_POST['tipo'] ?? '');
            [$ok, $res] = admin_abrir_barrera($tipo);
            flash_set(
                $ok ? 'ok' : 'error',
                $ok ? 'Apertura de ' . $tipo . ' encolada (comando #' . $res . ').' : (string) $res
            );
            header('Location: ' . $volver . '#barrera');
            exit;

        case 'forzar_ingreso':
            [$ok, $res] = admin_forzar_ingreso((int) ($_POST['reserva_id'] ?? 0));
            flash_set($ok ? 'ok' : 'error', $ok ? 'Ingreso registrado para la reserva ' . $res . '.' : (string) $res);
            header('Location: ' . $volver . '#reservas');
            exit;

        case 'forzar_salida':
            [$ok, $res] = admin_forzar_salida((int) ($_POST['reserva_id'] ?? 0));
            flash_set($ok ? 'ok' : 'error', $ok ? 'Salida registrada para la reserva ' . $res . '.' : (string) $res);
            header('Location: ' . $volver . '#reservas');
            exit;

        case 'cancelar_reserva':
            [$ok, $res] = admin_cancelar_reserva((int) ($_POST['reserva_id'] ?? 0));
            flash_set($ok ? 'ok' : 'error', $ok ? 'Reserva ' . $res . ' cancelada.' : (string) $res);
            header('Location: ' . $volver . '#reservas');
            exit;

        case 'espacio_estado':
            [$ok, $res] = admin_set_espacio_estado((int) ($_POST['espacio_id'] ?? 0), (string) ($_POST['estado'] ?? ''));
            flash_set($ok ? 'ok' : 'error', $ok ? 'Plaza ' . $res . ' actualizada.' : (string) $res);
            header('Location: ' . $volver . '#resumen');
            exit;
    }

    header('Location: ' . $volver);
    exit;
}

// ---------------------------------------------------------------------------
// Datos
// ---------------------------------------------------------------------------
$est        = admin_estadisticas();
$serie      = admin_serie_7d($est['ingresos_7d']);
$maxSerie   = 0.0;
foreach ($serie as $p) {
    $maxSerie = max($maxSerie, $p['total']);
}
if ($maxSerie <= 0) {
    $maxSerie = 1;
}

$tarifas   = tarifas_todas();
$espacios  = disponibilidad_espacios();
$reservas  = admin_reservas($_GET['estado'] ?? null);
$pagos     = admin_pagos(50);
$comandos  = admin_comandos(10);
$dentro    = admin_vehiculos_dentro();
$disp      = estado_dispositivo();
$flash     = flash_get();

$ingTipo = [];
foreach ($est['ingresos_tipo'] as $t) {
    $ingTipo[$t['tipo']] = $t;
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
$filtroEstado = (string) ($_GET['estado'] ?? '');
$csrf = csrf_token();

$titulo = 'Administracion';
$activo = 'admin';
require __DIR__ . '/partials/header.php';
?>

<div class="page-head">
  <div class="page-head-main">
    <span class="admin-badge"><i class="bi bi-shield-lock-fill"></i></span>
    <div>
      <h1 class="page-title">Administracion</h1>
      <p class="page-sub">Estadisticas, tarifas, barrera, ingresos y control del estacionamiento.</p>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alerta <?= $flash['tipo'] === 'ok' ? 'ok' : 'error' ?>">
    <i class="bi <?= $flash['tipo'] === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
    <?= htmlspecialchars($flash['mensaje']) ?>
  </div>
<?php endif; ?>

<nav class="ad-tabs">
  <button type="button" class="ad-tab is-active" data-tab="resumen"><i class="bi bi-bar-chart-fill"></i> Resumen</button>
  <button type="button" class="ad-tab" data-tab="tarifas"><i class="bi bi-tag-fill"></i> Tarifas</button>
  <button type="button" class="ad-tab" data-tab="barrera"><i class="bi bi-door-open-fill"></i> Barrera</button>
  <button type="button" class="ad-tab" data-tab="ingresos"><i class="bi bi-cash-coin"></i> Ingresos</button>
  <button type="button" class="ad-tab" data-tab="reservas"><i class="bi bi-journal-text"></i> Reservas</button>
</nav>

<!-- ============================= RESUMEN ============================= -->
<section class="ad-panel" data-panel="resumen">
  <section class="stats">
    <div class="stat">
      <span class="stat-icon green"><i class="bi bi-cash-stack"></i></span>
      <div>
        <span class="stat-label">Ingresos totales</span>
        <strong class="stat-value">$<?= number_format($est['ingresos_total'], 2, '.', ',') ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon blue"><i class="bi bi-calendar-day"></i></span>
      <div>
        <span class="stat-label">Ingresos de hoy</span>
        <strong class="stat-value">$<?= number_format($est['ingresos_hoy'], 2, '.', ',') ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon sky"><i class="bi bi-calendar3"></i></span>
      <div>
        <span class="stat-label">Ingresos del mes</span>
        <strong class="stat-value">$<?= number_format($est['ingresos_mes'], 2, '.', ',') ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon amber"><i class="bi bi-receipt"></i></span>
      <div>
        <span class="stat-label">Ticket promedio</span>
        <strong class="stat-value">$<?= number_format($est['ticket_promedio'], 2, '.', ',') ?></strong>
      </div>
    </div>
  </section>

  <section class="stats">
    <div class="stat">
      <span class="stat-icon blue"><i class="bi bi-grid-3x3-gap-fill"></i></span>
      <div>
        <span class="stat-label">Plazas totales</span>
        <strong class="stat-value"><?= (int) $est['espacios_total'] ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon rojo"><i class="bi bi-car-front"></i></span>
      <div>
        <span class="stat-label">Ocupacion</span>
        <strong class="stat-value"><?= number_format($est['ocupacion_pct'], 1, '.', ',') ?><small>%</small></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon sky"><i class="bi bi-people"></i></span>
      <div>
        <span class="stat-label">Usuarios</span>
        <strong class="stat-value"><?= (int) $est['usuarios'] ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon gris"><i class="bi bi-journal-check"></i></span>
      <div>
        <span class="stat-label">Reservas totales</span>
        <strong class="stat-value"><?= (int) $est['reservas_total'] ?></strong>
      </div>
    </div>
  </section>

  <div class="ad-grid">
    <section class="card">
      <div class="card-head">
        <div>
          <h2>Ingresos ultimos 7 dias</h2>
          <p class="card-sub">Pagos acreditados por dia</p>
        </div>
      </div>
      <div class="ad-chart">
        <?php foreach ($serie as $p):
            $h = $maxSerie > 0 ? round($p['total'] / $maxSerie * 100) : 0;
        ?>
          <div class="ad-bar" title="<?= htmlspecialchars($p['dia']) ?> · $<?= number_format($p['total'], 2, '.', ',') ?>">
            <span class="ad-bar-fill" style="height: <?= $h ?>%"></span>
            <span class="ad-bar-label"><?= htmlspecialchars($p['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2>Ocupacion por estado</h2>
          <p class="card-sub">Distribucion de las <?= (int) $est['espacios_total'] ?> plazas</p>
        </div>
      </div>
      <ul class="ad-bars">
        <?php
        $colores = ['libre' => 'var(--libre)', 'reservado' => 'var(--reservado)', 'ocupado' => 'var(--ocupado)', 'mantenimiento' => 'var(--manten)'];
        foreach ($est['espacios'] as $k => $n):
            $pct = $est['espacios_total'] > 0 ? round($n / $est['espacios_total'] * 100) : 0;
        ?>
          <li>
            <span class="ad-bars-label"><?= htmlspecialchars($etiquetaEstado[$k]) ?></span>
            <span class="ad-bars-track"><span class="ad-bars-fill" style="width: <?= $pct ?>%; background: <?= $colores[$k] ?>"></span></span>
            <span class="ad-bars-num"><?= (int) $n ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Plazas mas usadas</h2>
        <p class="card-sub">Top 5 por cantidad de reservas</p>
      </div>
    </div>
    <?php if (!$est['top_espacios']): ?>
      <div class="empty"><i class="bi bi-bar-chart"></i><p>Todavia no hay datos de uso.</p></div>
    <?php else: ?>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Plaza</th><th>Tipo</th><th class="num">Reservas</th><th class="num">Ingresos</th></tr></thead>
          <tbody>
            <?php foreach ($est['top_espacios'] as $t): ?>
              <tr>
                <td><span class="space-tag"><i class="bi bi-p-square"></i> <?= htmlspecialchars($t['codigo']) ?></span></td>
                <td><?= htmlspecialchars(ucfirst($t['tipo'])) ?></td>
                <td class="num"><?= (int) $t['c'] ?></td>
                <td class="num monto">$<?= number_format((float) $t['total'], 2, '.', ',') ?></td>
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
        <h2>Control de plazas</h2>
        <p class="card-sub">Habilita o pon una plaza en mantenimiento</p>
      </div>
    </div>
    <div class="plazas-grid">
      <?php foreach ($espacios as $e):
          $estado = $e['estado'];
      ?>
        <div class="plaza <?= htmlspecialchars($estado) ?>" data-estado="<?= htmlspecialchars($estado) ?>">
          <span class="p-estado"><i class="bi <?= htmlspecialchars($iconoEstado[$estado] ?? 'bi-circle') ?>"></i></span>
          <div class="p-codigo"><?= htmlspecialchars($e['codigo']) ?></div>
          <div class="p-meta"><?= htmlspecialchars($etiquetaEstado[$estado] ?? $estado) ?></div>
          <?php if ($estado === 'libre' || $estado === 'mantenimiento'): ?>
            <form method="post" class="p-accion">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="accion" value="espacio_estado">
              <input type="hidden" name="espacio_id" value="<?= (int) $e['id'] ?>">
              <input type="hidden" name="estado" value="<?= $estado === 'libre' ? 'mantenimiento' : 'libre' ?>">
              <button type="submit" class="p-btn">
                <?= $estado === 'libre' ? '<i class="bi bi-tools"></i> Mantenimiento' : '<i class="bi bi-check-lg"></i> Habilitar' ?>
              </button>
            </form>
          <?php else: ?>
            <div class="p-accion"><span class="p-btn disabled">En uso</span></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</section>

<!-- ============================= TARIFAS ============================= -->
<section class="ad-panel" data-panel="tarifas" hidden>
  <section class="card">
    <div class="card-head">
      <div>
        <h2>Tarifas de cobro</h2>
        <p class="card-sub">Precio por hora segun el tipo de vehiculo. Se aplica al reservar.</p>
      </div>
    </div>

    <div class="ad-grid">
      <?php foreach ($tarifas as $t):
          $esAuto = stripos($t['nombre'], 'auto') !== false;
          $esMoto = stripos($t['nombre'], 'moto') !== false;
          $ico    = $esMoto ? 'bi-bicycle' : ($esAuto ? 'bi-car-front-fill' : 'bi-tag-fill');
      ?>
        <form method="post" class="tarifa-card">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="accion" value="actualizar_tarifa">
          <input type="hidden" name="tarifa_id" value="<?= (int) $t['id'] ?>">

          <div class="tarifa-head">
            <span class="tarifa-icon"><i class="bi <?= $ico ?>"></i></span>
            <div>
              <strong><?= htmlspecialchars($t['nombre']) ?></strong>
              <small class="muted">Precio por hora</small>
            </div>
          </div>

          <label>Precio por hora ($)
            <input type="number" name="precio_hora" min="0" max="1000000" step="0.01"
                   value="<?= htmlspecialchars(number_format((float) $t['precio_hora'], 2, '.', '')) ?>" required>
          </label>

          <label class="switch">
            <input type="checkbox" name="activo" value="1" <?= (int) $t['activo'] === 1 ? 'checked' : '' ?>>
            <span>Tarifa activa</span>
          </label>

          <button type="submit" class="btn primario"><i class="bi bi-floppy"></i> Guardar</button>
        </form>
      <?php endforeach; ?>
    </div>
  </section>
</section>

<!-- ============================= BARRERA ============================= -->
<section class="ad-panel" data-panel="barrera" hidden>
  <div class="ad-grid" id="ad-live" data-api="<?= htmlspecialchars($raiz) ?>/api/admin_estado.php">
    <section class="card">
      <div class="card-head">
        <div>
          <h2>Dispositivo de barrera</h2>
          <p class="card-sub">Estado del ESP32 de la porteria</p>
        </div>
      </div>

      <div class="estado-dispositivo" style="justify-content:flex-start;">
        <span class="punto <?= $disp['en_linea'] ? 'online' : 'offline' ?>" id="ad-disp-punto"></span>
        <span id="ad-disp-texto">
          <?= $disp['en_linea'] ? 'En linea' : 'Fuera de linea' ?>
          · IP <?= htmlspecialchars($disp['ip'] ?? 'sin datos') ?>
        </span>
      </div>

      <div class="datos" style="grid-template-columns:1fr 1fr;">
        <div><span>Estado</span><strong id="ad-disp-estado"><?= htmlspecialchars(ucfirst($disp['estado'])) ?></strong></div>
        <div><span>Ultimo latido</span><strong id="ad-disp-latido"><?= htmlspecialchars(fecha_amigable($disp['ultimo_latido'])) ?></strong></div>
        <div><span>Vehiculos dentro</span><strong id="ad-disp-dentro"><?= count($dentro) ?></strong></div>
        <div><span>Reservas pendientes</span><strong id="ad-disp-pend"><?= (int) $est['reservas']['pendiente'] ?></strong></div>
      </div>

      <div class="ad-manual">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="accion" value="abrir_barrera">
          <input type="hidden" name="tipo" value="entrada">
          <button type="submit" class="btn primario barrera-btn entrada"><i class="bi bi-box-arrow-in-right"></i> Abrir entrada</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="accion" value="abrir_barrera">
          <input type="hidden" name="tipo" value="salida">
          <button type="submit" class="btn primario barrera-btn salida"><i class="bi bi-box-arrow-right"></i> Abrir salida</button>
        </form>
      </div>
      <p class="muted" style="font-size:.8rem;">La apertura manual no requiere una reserva y se encola para el dispositivo.</p>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2>Ultimos comandos</h2>
          <p class="card-sub">Actualizado <span id="ad-comandos-hora"><?= htmlspecialchars(date('H:i:s')) ?></span></p>
        </div>
      </div>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>#</th><th>Tipo</th><th>Origen</th><th>Estado</th><th>Creado</th></tr></thead>
          <tbody id="ad-comandos">
            <?php foreach ($comandos as $c): ?>
              <tr>
                <td class="mono"><?= (int) $c['id'] ?></td>
                <td><?= $c['tipo'] === 'entrada' ? '<i class="bi bi-box-arrow-in-right text-primary"></i> Entrada' : '<i class="bi bi-box-arrow-right"></i> Salida' ?></td>
                <td class="mono muted"><?= htmlspecialchars($c['reserva_codigo'] ?? 'Manual') ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($c['estado']) ?>"><?= htmlspecialchars(ucfirst($c['estado'])) ?></span></td>
                <td class="muted"><?= htmlspecialchars(fecha_amigable($c['creado_en'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</section>

<!-- ============================= INGRESOS ============================= -->
<section class="ad-panel" data-panel="ingresos" hidden>
  <section class="stats">
    <div class="stat">
      <span class="stat-icon green"><i class="bi bi-cash-stack"></i></span>
      <div>
        <span class="stat-label">Total recaudado</span>
        <strong class="stat-value">$<?= number_format($est['ingresos_total'], 2, '.', ',') ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon blue"><i class="bi bi-credit-card"></i></span>
      <div>
        <span class="stat-label">Pagos registrados</span>
        <strong class="stat-value"><?= (int) $est['pagos_count'] ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon sky"><i class="bi bi-car-front"></i></span>
      <div>
        <span class="stat-label">Autos</span>
        <strong class="stat-value">$<?= number_format((float) ($ingTipo['auto']['total'] ?? 0), 2, '.', ',') ?></strong>
      </div>
    </div>
    <div class="stat">
      <span class="stat-icon violet"><i class="bi bi-bicycle"></i></span>
      <div>
        <span class="stat-label">Motos</span>
        <strong class="stat-value">$<?= number_format((float) ($ingTipo['moto']['total'] ?? 0), 2, '.', ',') ?></strong>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>Pagos</h2>
        <p class="card-sub">Los pagos no se devuelven; incluye reservas canceladas</p>
      </div>
    </div>
    <?php if (!$pagos): ?>
      <div class="empty"><i class="bi bi-credit-card"></i><p>Todavia no hay pagos registrados.</p></div>
    <?php else: ?>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead>
            <tr><th class="hide-sm">Comprobante</th><th>Usuario</th><th>Vehiculo</th><th class="hide-sm">Plaza</th><th>Estado</th><th class="num">Monto</th><th class="hide-sm">Pagado</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pagos as $p): ?>
              <tr>
                <td class="hide-sm mono muted"><?= htmlspecialchars($p['codigo']) ?></td>
                <td><?= htmlspecialchars($p['usuario_nombre']) ?></td>
                <td class="mono"><?= htmlspecialchars($p['matricula']) ?></td>
                <td class="hide-sm"><span class="space-tag"><?= htmlspecialchars($p['espacio_codigo']) ?></span></td>
                <td><span class="badge badge-<?= htmlspecialchars($p['estado']) ?>"><?= htmlspecialchars(ucfirst($p['estado'])) ?></span></td>
                <td class="num monto">$<?= number_format((float) $p['monto'], 2, '.', ',') ?></td>
                <td class="hide-sm muted"><?= htmlspecialchars(fecha_amigable($p['pagado_en'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</section>

<!-- ============================= RESERVAS ============================= -->
<section class="ad-panel" data-panel="reservas" hidden>
  <section class="card">
    <div class="card-head">
      <div>
        <h2>Vehiculos dentro</h2>
        <p class="card-sub"><?= count($dentro) ?> en el estacionamiento ahora</p>
      </div>
    </div>
    <?php if (!$dentro): ?>
      <div class="empty"><i class="bi bi-p-circle"></i><p>No hay vehiculos dentro.</p></div>
    <?php else: ?>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Vehiculo</th><th>Usuario</th><th>Plaza</th><th class="hide-sm">Ingreso</th><th class="num">Accion</th></tr></thead>
          <tbody>
            <?php foreach ($dentro as $d): ?>
              <tr>
                <td class="mono"><?= htmlspecialchars($d['matricula']) ?></td>
                <td><?= htmlspecialchars($d['usuario_nombre']) ?></td>
                <td><span class="space-tag"><?= htmlspecialchars($d['espacio_codigo']) ?></span></td>
                <td class="hide-sm muted"><?= htmlspecialchars(fecha_amigable($d['ingreso_en'])) ?></td>
                <td class="num">
                  <form method="post" class="inline" data-confirm="¿Registrar la salida de <?= htmlspecialchars($d['matricula']) ?>? Se liberara la plaza <?= htmlspecialchars($d['espacio_codigo']) ?>.">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="accion" value="forzar_salida">
                    <input type="hidden" name="reserva_id" value="<?= (int) $d['id'] ?>">
                    <button type="submit" class="btn-icon eliminar" title="Registrar salida"><i class="bi bi-box-arrow-right"></i></button>
                  </form>
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
        <h2>Todas las reservas</h2>
        <p class="card-sub">Control de reservas de todos los usuarios</p>
      </div>
      <div class="filtros" style="margin:0;">
        <?php
        $opciones = ['' => 'Todas', 'pendiente' => 'Pendientes', 'ingresado' => 'Ingresadas', 'finalizado' => 'Finalizadas', 'cancelada' => 'Canceladas'];
        foreach ($opciones as $val => $txt):
        ?>
          <a class="chip <?= $filtroEstado === $val ? 'activo' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/admin.php?estado=<?= urlencode($val) ?>#reservas"><?= htmlspecialchars($txt) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!$reservas): ?>
      <div class="empty"><i class="bi bi-journal-x"></i><p>No hay reservas para este filtro.</p></div>
    <?php else: ?>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead>
            <tr>
              <th class="hide-sm">Codigo</th><th>Usuario</th><th>Vehiculo</th><th>Plaza</th>
              <th class="hide-sm">Inicio</th><th class="num">Monto</th><th>Estado</th><th class="num">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reservas as $r): ?>
              <tr>
                <td class="hide-sm mono muted"><?= htmlspecialchars($r['codigo']) ?></td>
                <td><?= htmlspecialchars($r['usuario_nombre']) ?></td>
                <td class="mono"><?= htmlspecialchars($r['matricula']) ?></td>
                <td><span class="space-tag"><?= htmlspecialchars($r['espacio_codigo']) ?></span></td>
                <td class="hide-sm"><?= htmlspecialchars(fecha_amigable($r['inicio'])) ?></td>
                <td class="num monto">$<?= number_format((float) $r['monto'], 2, '.', ',') ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($r['estado']) ?>"><?= htmlspecialchars(ucfirst($r['estado'])) ?></span></td>
                <td class="num">
                  <div class="row-actions" style="justify-content:flex-end;">
                    <?php if ($r['estado'] === 'pendiente'): ?>
                      <form method="post" class="inline" data-confirm="¿Registrar el ingreso de <?= htmlspecialchars($r['matricula']) ?>?">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="accion" value="forzar_ingreso">
                        <input type="hidden" name="reserva_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-icon" title="Registrar ingreso"><i class="bi bi-box-arrow-in-right"></i></button>
                      </form>
                      <form method="post" class="inline" data-confirm="¿Cancelar la reserva <?= htmlspecialchars($r['codigo']) ?>? El pago no se devuelve.">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="accion" value="cancelar_reserva">
                        <input type="hidden" name="reserva_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-icon eliminar" title="Cancelar reserva"><i class="bi bi-x-lg"></i></button>
                      </form>
                    <?php elseif ($r['estado'] === 'ingresado'): ?>
                      <form method="post" class="inline" data-confirm="¿Registrar la salida de <?= htmlspecialchars($r['matricula']) ?>?">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="accion" value="forzar_salida">
                        <input type="hidden" name="reserva_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-icon" title="Registrar salida"><i class="bi bi-box-arrow-right"></i></button>
                      </form>
                    <?php else: ?>
                      <span class="muted">—</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</section>

<!-- Confirmacion generica de administracion -->
<div class="modal" id="modal-admin" aria-hidden="true">
  <div class="modal-backdrop" data-close></div>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="admin-confirm-titulo">
    <div class="modal-head">
      <h3 id="admin-confirm-titulo"><i class="bi bi-question-circle-fill"></i> Confirmar accion</h3>
      <button class="modal-x" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body">
      <p id="admin-confirm-msg">¿Estas seguro?</p>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancelar</button>
      <button class="btn primario" type="button" id="admin-confirm-ok"><i class="bi bi-check-lg"></i> Confirmar</button>
    </div>
  </div>
</div>

<?php
$scripts = '<script src="' . htmlspecialchars(asset('assets/js/admin.js')) . '"></script>';
require __DIR__ . '/partials/footer.php';
