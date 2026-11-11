<?php
/**
 * Parking Tech - Autenticacion y sesion de usuario
 * -----------------------------------------------------------------------------
 * Sesiones de LOGIN de usuario (necesarias para gestionar reservas).
 * No existe sesion de estacionamiento ni registro instantaneo: todo pasa
 * por una reserva previa.
 */

declare(strict_types=1);

require_once __DIR__ . '/funciones.php';

// Sesion endurecida: cookies HttpOnly/SameSite, huella de dispositivo,
// expiracion por inactividad/duracion y regeneracion periodica del id.
iniciar_sesion_segura();

// ---------------------------------------------------------------------------
// Usuario
// ---------------------------------------------------------------------------

function usuario_actual(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }

    static $usuario = null;
    if ($usuario !== null && (int) $usuario['id'] === (int) $_SESSION['usuario_id']) {
        return $usuario;
    }

    $stmt = db()->prepare('SELECT id, nombre, email, telefono, rol FROM usuarios WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['usuario_id']]);
    $usuario = $stmt->fetch() ?: null;

    return $usuario;
}

function iniciar_sesion(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($password, $usuario['password'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $usuario['id'];

    return true;
}

function cerrar_sesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// Proteccion
// ---------------------------------------------------------------------------

function requiere_login(): array
{
    $usuario = usuario_actual();
    if (!$usuario) {
        header('Location: ' . ruta_login());
        exit;
    }

    return $usuario;
}

/**
 * Indica si el usuario provisto (o el de la sesion) es administrador.
 */
function es_admin(?array $usuario = null): bool
{
    $usuario = $usuario ?? usuario_actual();

    return ($usuario['rol'] ?? 'usuario') === 'admin';
}

/**
 * Ruta de inicio segun el rol: los administradores van a su panel.
 */
function ruta_inicio(?array $usuario = null): string
{
    $usuario = $usuario ?? usuario_actual();

    return raiz_web() . (es_admin($usuario) ? '/vistas/admin.php' : '/vistas/dashboard.php');
}

/**
 * Exige sesion iniciada con rol de usuario comun.
 * Los administradores son redirigidos a su panel de administracion.
 */
function requiere_usuario(): array
{
    $usuario = requiere_login();

    if (es_admin($usuario)) {
        header('Location: ' . raiz_web() . '/vistas/admin.php');
        exit;
    }

    return $usuario;
}

/**
 * Exige sesion iniciada Y rol de administrador.
 * A los usuarios comunes se les redirige al panel con un aviso.
 */
function requiere_admin(): array
{
    $usuario = requiere_login();

    if (!es_admin($usuario)) {
        flash_set('error', 'No tenes permisos para acceder a la administracion.');
        header('Location: ' . raiz_web() . '/vistas/dashboard.php');
        exit;
    }

    return $usuario;
}

/**
 * Ruta web absoluta a la raiz del proyecto (sin barra final).
 * Funciona tanto desde /vistas/ como desde /api/ o la raiz.
 */
function raiz_web(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    return (string) preg_replace('#/(vistas|api)$#', '', $dir);
}

/**
 * Ruta absoluta al login.
 */
function ruta_login(): string
{
    return raiz_web() . '/vistas/login.php';
}

// ---------------------------------------------------------------------------
// CSRF (para formularios y acciones web)
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_valido(?string $token): bool
{
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

// ---------------------------------------------------------------------------
// Mensajes flash (sobreviven a una redireccion)
// ---------------------------------------------------------------------------

function flash_set(string $tipo, string $mensaje): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/**
 * @return array{tipo:string,mensaje:string}|null
 */
function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}
