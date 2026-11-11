<?php
/**
 * Layout - cabecera comun (vistas autenticadas)
 * Variables opcionales: $titulo, $activo
 */
$titulo = $titulo ?? 'Parking Tech';
$activo = $activo ?? '';
$u      = usuario_actual();
$raiz   = raiz_web();

$nombre = $u['nombre'] ?? 'Usuario';
$email  = $u['email'] ?? '';
$rol    = $u['rol'] ?? 'usuario';
$avatar = iniciales($nombre);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo) ?> · Parking Tech</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars(asset('assets/css/estilos.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="<?= htmlspecialchars(ruta_inicio($u)) ?>">
      <span class="brand-mark">P</span>
      <span class="brand-name">Parking<span>Tech</span></span>
    </a>

    <nav class="main-nav">
      <?php if ($rol === 'admin'): ?>
      <a class="nav-link <?= $activo === 'admin' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/admin.php">
        <i class="bi bi-shield-lock-fill"></i> Administracion
      </a>
      <?php else: ?>
      <a class="nav-link <?= $activo === 'panel' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/dashboard.php">
        <i class="bi bi-grid-1x2-fill"></i> Panel
      </a>
      <a class="nav-link <?= $activo === 'reservas' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php">
        <i class="bi bi-journal-text"></i> Mis reservas
      </a>
      <a class="nav-link <?= $activo === 'disponibilidad' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/disponibilidad.php">
        <i class="bi bi-grid-3x3-gap"></i> Disponibilidad
      </a>
      <a class="nav-link <?= $activo === 'nueva' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">
        <i class="bi bi-calendar-plus"></i> Nueva reserva
      </a>
      <a class="nav-link <?= $activo === 'barrera' ? 'is-active' : '' ?>" href="<?= htmlspecialchars($raiz) ?>/vistas/abrir_barrera.php">
        <i class="bi bi-door-open"></i> Abrir barrera
      </a>
      <?php endif; ?>
    </nav>

    <button class="nav-toggle" type="button" aria-label="Abrir menu">
      <i class="bi bi-list"></i>
    </button>

    <div class="user-menu">
      <button class="user-trigger" type="button">
        <span class="avatar"><?= htmlspecialchars($avatar) ?></span>
        <span class="user-meta">
          <strong><?= htmlspecialchars($nombre) ?></strong>
          <small><?= htmlspecialchars(ucfirst($rol)) ?></small>
        </span>
        <i class="bi bi-chevron-down chev"></i>
      </button>

      <div class="user-dropdown">
        <div class="dropdown-head">
          <strong><?= htmlspecialchars($nombre) ?></strong>
          <small><?= htmlspecialchars($email) ?></small>
        </div>
        <?php if ($rol === 'admin'): ?>
        <a class="dropdown-item" href="<?= htmlspecialchars($raiz) ?>/vistas/admin.php">
          <i class="bi bi-shield-lock-fill"></i> Administracion
        </a>
        <?php else: ?>
        <a class="dropdown-item" href="<?= htmlspecialchars($raiz) ?>/vistas/abrir_barrera.php">
          <i class="bi bi-door-open"></i> Abrir barrera
        </a>
        <a class="dropdown-item" href="<?= htmlspecialchars($raiz) ?>/vistas/nueva_reserva.php">
          <i class="bi bi-calendar-plus"></i> Nueva reserva
        </a>
        <a class="dropdown-item" href="<?= htmlspecialchars($raiz) ?>/vistas/mis_reservas.php">
          <i class="bi bi-journal-text"></i> Mis reservas
        </a>
        <a class="dropdown-item" href="<?= htmlspecialchars($raiz) ?>/vistas/disponibilidad.php">
          <i class="bi bi-grid-3x3-gap"></i> Disponibilidad
        </a>
        <a class="dropdown-item" href="<?= htmlspecialchars($raiz) ?>/vistas/nuevo_vehiculo.php">
          <i class="bi bi-car-front"></i> Agregar vehiculo
        </a>
        <?php endif; ?>
        <a class="dropdown-item danger" href="<?= htmlspecialchars($raiz) ?>/vistas/logout.php">
          <i class="bi bi-box-arrow-right"></i> Cerrar sesion
        </a>
      </div>
    </div>
  </div>
</header>
<main class="wrap">
