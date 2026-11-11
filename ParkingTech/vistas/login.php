<?php
/**
 * Parking Tech - Inicio de sesion
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (usuario_actual()) {
    header('Location: ' . ruta_inicio());
    exit;
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $pass  = (string) ($_POST['password'] ?? '');

    // --- Rate limiting (anti fuerza bruta) ---
    $ip      = cliente_ip();
    $ipKey   = 'login_ip:' . $ip;
    $failKey = 'login_fail:' . strtolower($email) . '|' . $ip;

    $limiteIp   = rate_limit_estado($ipKey, RATE_LOGIN_IP_MAX, RATE_LOGIN_IP_VENTANA);
    $limiteMail = rate_limit_estado($failKey, RATE_LOGIN_MAIL_MAX, RATE_LOGIN_MAIL_VENTANA);

    if (!$limiteIp['allowed'] || !$limiteMail['allowed']) {
        $espera = max($limiteIp['retry_after'], $limiteMail['retry_after']);
        $error  = 'Demasiados intentos. Espera ' . max(1, (int) ceil($espera / 60)) . ' minuto(s) e intenta de nuevo.';
    } elseif (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad invalido. Volve a intentar.';
    } elseif ($email === '' || $pass === '') {
        $error = 'Completa email y contrasena.';
    } else {
        rate_limit_registrar($ipKey, RATE_LOGIN_IP_VENTANA);

        if (iniciar_sesion($email, $pass)) {
            rate_limit_limpiar($failKey);
            header('Location: ' . ruta_inicio());
            exit;
        }

        // Fallo: cuenta como intento fallido para ese email+IP.
        rate_limit_registrar($failKey, RATE_LOGIN_MAIL_VENTANA);
        $error = 'Credenciales incorrectas.';
    }
}

$raiz = raiz_web();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar · Parking Tech</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars(asset('assets/css/estilos.css')) ?>">
</head>
<body class="auth">
<main class="auth-card">
  <h1 class="logo">Parking<span>Tech</span></h1>
  <p class="sub">Estacionamiento inteligente por reservas</p>

  <?php if ($error): ?>
    <div class="alerta error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Email
      <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus>
    </label>
    <label>Contrasena
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="btn primario">Ingresar</button>
  </form>

  <p class="pie-auth">No tenes cuenta? <a href="<?= htmlspecialchars($raiz) ?>/vistas/registro.php">Registrate</a></p>
  <p class="demo">Demo: usuario@parkingtech.local / usuario123</p>
</main>
</body>
</html>
