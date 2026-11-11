<?php
/**
 * Parking Tech - Registro de cuenta
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (usuario_actual()) {
    header('Location: ' . ruta_inicio());
    exit;
}

$error  = null;
$datos  = ['nombre' => '', 'email' => '', 'telefono' => '', 'matricula' => '', 'marca' => '', 'modelo' => '', 'color' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($datos) as $campo) {
        $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    $password     = (string) ($_POST['password'] ?? '');
    $password2    = (string) ($_POST['password2'] ?? '');
    $conMatricula = $datos['matricula'] !== '';

    // --- Rate limiting (anti abuso de alta de cuentas) ---
    $ip     = cliente_ip();
    $rlKey  = 'registro_ip:' . $ip;
    $limite = rate_limit_estado($rlKey, RATE_REGISTRO_MAX, RATE_REGISTRO_VENTANA);
    if ($limite['allowed']) {
        rate_limit_registrar($rlKey, RATE_REGISTRO_VENTANA);
    }

    if (!$limite['allowed']) {
        $error = 'Demasiadas solicitudes. Espera unos minutos e intenta de nuevo.';
    } elseif (!csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'Token de seguridad invalido. Volve a intentar.';
    } elseif ($datos['nombre'] === '' || $datos['email'] === '' || $password === '') {
        $error = 'Nombre, email y contrasena son obligatorios.';
    } elseif (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es valido.';
    } elseif (strlen($password) < 6) {
        $error = 'La contrasena debe tener al menos 6 caracteres.';
    } elseif ($password !== $password2) {
        $error = 'Las contrasenas no coinciden.';
    } else {
        $pdo = db();
        $existe = $pdo->prepare('SELECT id FROM usuarios WHERE email = :e');
        $existe->execute(['e' => $datos['email']]);
        if ($existe->fetch()) {
            $error = 'Ese email ya esta registrado.';
        } else {
            try {
                $pdo->beginTransaction();

                $ins = $pdo->prepare(
                    "INSERT INTO usuarios (nombre, email, password, telefono, rol)
                     VALUES (:n, :e, :p, :t, 'usuario')"
                );
                $ins->execute([
                    'n' => $datos['nombre'],
                    'e' => $datos['email'],
                    'p' => password_hash($password, PASSWORD_DEFAULT),
                    't' => $datos['telefono'] ?: null,
                ]);
                $usuarioId = (int) $pdo->lastInsertId();

                if ($conMatricula) {
                    $ins = $pdo->prepare(
                        "INSERT INTO vehiculos (usuario_id, matricula, marca, modelo, color)
                         VALUES (:u, :m, :ma, :mo, :c)"
                    );
                    $ins->execute([
                        'u'  => $usuarioId,
                        'm'  => $datos['matricula'],
                        'ma' => $datos['marca'] ?: null,
                        'mo' => $datos['modelo'] ?: null,
                        'c'  => $datos['color'] ?: null,
                    ]);
                }

                $pdo->commit();

                iniciar_sesion($datos['email'], $password);
                header('Location: ' . raiz_web() . '/vistas/dashboard.php');
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getCode() === '23000'
                    ? 'La matricula ya esta registrada por otro usuario.'
                    : 'No se pudo crear la cuenta. Intenta de nuevo.';
            }
        }
    }
}

$raiz = raiz_web();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registro · Parking Tech</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= htmlspecialchars(asset('assets/css/estilos.css')) ?>">
</head>
<body class="auth">
<main class="auth-card">
  <h1 class="logo">Parking<span>Tech</span></h1>
  <p class="sub">Crea tu cuenta para reservar tu lugar</p>

  <?php if ($error): ?>
    <div class="alerta error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Nombre completo
      <input type="text" name="nombre" value="<?= htmlspecialchars($datos['nombre']) ?>" required>
    </label>
    <label>Email
      <input type="email" name="email" value="<?= htmlspecialchars($datos['email']) ?>" required>
    </label>
    <label>Telefono
      <input type="tel" name="telefono" value="<?= htmlspecialchars($datos['telefono']) ?>">
    </label>
    <label>Contrasena
      <input type="password" name="password" minlength="6" required>
    </label>
    <label>Repetir contrasena
      <input type="password" name="password2" minlength="6" required>
    </label>

    <h2 class="seccion">Vehiculo (opcional, lo podes cargar despues)</h2>
    <label>Patente / matricula
      <input type="text" name="matricula" value="<?= htmlspecialchars($datos['matricula']) ?>" placeholder="AB123CD" maxlength="15">
    </label>
    <div class="grid2">
      <label>Marca
        <input type="text" name="marca" value="<?= htmlspecialchars($datos['marca']) ?>">
      </label>
      <label>Modelo
        <input type="text" name="modelo" value="<?= htmlspecialchars($datos['modelo']) ?>">
      </label>
    </div>
    <label>Color
      <input type="text" name="color" value="<?= htmlspecialchars($datos['color']) ?>">
    </label>

    <button type="submit" class="btn primario">Crear cuenta</button>
  </form>

  <p class="pie-auth">Ya tenes cuenta? <a href="<?= htmlspecialchars($raiz) ?>/vistas/login.php">Ingresa</a></p>
</main>
</body>
</html>