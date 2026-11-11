<?php
/**
 * Parking Tech - API Web: control de barrera del usuario
 * -----------------------------------------------------------------------------
 * POST api/abrir_barrera.php
 *   accion=estado   -> devuelve el contexto actual (reserva + estado del ESP32)
 *   accion=entrada  -> registra el ingreso y encola el comando 'entrada'
 *   accion=salida   -> registra el egreso  y encola el comando 'salida'
 *
 * Requiere sesion de usuario. Las acciones entrada/salida exigen token CSRF.
 * La logica valida estado y horario de la reserva antes de accionar la barrera.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$usuario = usuario_actual();
if (!$usuario) {
    json_out(['ok' => false, 'error' => 'Sesion no valida. Inicie sesion.'], 401);
}

$accion = $_POST['accion'] ?? $_GET['accion'] ?? 'estado';
$pdo    = db();

// ---------------------------------------------------------------------------
// ESTADO (lectura, usada por el polling asincrono de la interfaz)
// ---------------------------------------------------------------------------
if ($accion === 'estado') {
    $contexto = contexto_barrera((int) $usuario['id']);

    json_out([
        'ok'          => true,
        'modo'        => $contexto['modo'],
        'reserva'     => $contexto['reserva'],
        'dispositivo' => estado_dispositivo(),
        'ahora'       => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ]);
}

// ---------------------------------------------------------------------------
// Validacion CSRF para acciones que mueven la barrera
// ---------------------------------------------------------------------------
if (!csrf_valido($_POST['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'Token de seguridad invalido.'], 403);
}

// ---------------------------------------------------------------------------
// Rate limiting (protege la accion fisica contra abuso o automatizacion)
// ---------------------------------------------------------------------------
$limite = rate_limit_estado('barrera:' . (int) $usuario['id'], RATE_BARRERA_USER_MAX, RATE_BARRERA_VENTANA);
if (!$limite['allowed']) {
    rate_limit_json($limite);
}
rate_limit_registrar('barrera:' . (int) $usuario['id'], RATE_BARRERA_VENTANA);

// ---------------------------------------------------------------------------
// ENTRADA
// ---------------------------------------------------------------------------
if ($accion === 'entrada') {
    $contexto = contexto_barrera((int) $usuario['id']);

    if ($contexto['modo'] !== 'entrada' || !$contexto['reserva']) {
        $mensaje = $contexto['modo'] === 'espera'
            ? 'Todavia no es la hora de tu reserva.'
            : 'No tenes una reserva habilitada para ingresar.';
        json_out(['ok' => false, 'error' => $mensaje]);
    }

    $reserva = $contexto['reserva'];

    try {
        $pdo->beginTransaction();

        // Bloqueo y re-validacion del estado real.
        $stmt = $pdo->prepare(
            "SELECT id, estado, pagada FROM reservas WHERE id = :id FOR UPDATE"
        );
        $stmt->execute(['id' => $reserva['id']]);
        $actual = $stmt->fetch();

        if (!$actual || $actual['estado'] !== 'pendiente' || (int) $actual['pagada'] !== 1) {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'La reserva ya no esta disponible para ingresar.']);
        }

        $pdo->prepare(
            "UPDATE reservas
                SET estado = 'ingresado', ingreso_en = NOW()
              WHERE id = :id"
        )->execute(['id' => $reserva['id']]);

        $pdo->prepare(
            "UPDATE espacios SET estado = 'ocupado' WHERE id = :id"
        )->execute(['id' => $reserva['espacio_id']]);

        $comandoId = encolar_comando('entrada', (int) $reserva['id']);
        $pdo->commit();

        json_out([
            'ok'         => true,
            'accion'     => 'entrada',
            'mensaje'    => 'Bienvenido. La barrera de ingreso se esta abriendo.',
            'comando_id' => $comandoId,
            'reserva_id' => (int) $reserva['id'],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['ok' => false, 'error' => 'No se pudo procesar el ingreso.'], 500);
    }
}

// ---------------------------------------------------------------------------
// SALIDA
// ---------------------------------------------------------------------------
if ($accion === 'salida') {
    $contexto = contexto_barrera((int) $usuario['id']);

    if ($contexto['modo'] !== 'salida' || !$contexto['reserva']) {
        json_out(['ok' => false, 'error' => 'No hay un vehiculo ingresado para dar salida.']);
    }

    $reserva = $contexto['reserva'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "SELECT id, estado FROM reservas WHERE id = :id FOR UPDATE"
        );
        $stmt->execute(['id' => $reserva['id']]);
        $actual = $stmt->fetch();

        if (!$actual || $actual['estado'] !== 'ingresado') {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'La reserva no se encuentra ingresada.']);
        }

        $pdo->prepare(
            "UPDATE reservas
                SET estado = 'finalizado', egreso_en = NOW()
              WHERE id = :id"
        )->execute(['id' => $reserva['id']]);

        $pdo->prepare(
            "UPDATE espacios SET estado = 'libre' WHERE id = :id"
        )->execute(['id' => $reserva['espacio_id']]);

        $comandoId = encolar_comando('salida', (int) $reserva['id']);
        $pdo->commit();

        json_out([
            'ok'         => true,
            'accion'     => 'salida',
            'mensaje'    => 'Hasta luego. La barrera de salida se esta abriendo.',
            'comando_id' => $comandoId,
            'reserva_id' => (int) $reserva['id'],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['ok' => false, 'error' => 'No se pudo procesar el egreso.'], 500);
    }
}

json_out(['ok' => false, 'error' => 'Accion desconocida.'], 400);
