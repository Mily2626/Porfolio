<?php
/**
 * Parking Tech - Funciones de negocio compartidas
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// Cabeceras de seguridad en toda respuesta PHP (web y API).
cabeceras_seguridad();

// ---------------------------------------------------------------------------
// Respuestas
// ---------------------------------------------------------------------------

/**
 * Emite una respuesta JSON y termina la ejecucion.
 */
function json_out(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emite la respuesta que el ESP32 espera en el polling.
 * El sketch busca literalmente `"hay":true`, por eso json_encode (sin espacios).
 */
function json_esp32(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ---------------------------------------------------------------------------
// API KEY (IoT)
// ---------------------------------------------------------------------------

/**
 * URL de un asset con cache-busting basado en la fecha de modificacion.
 */
function asset(string $ruta): string
{
    $ruta = ltrim($ruta, '/');
    $abs  = dirname(__DIR__) . '/' . $ruta;
    $v    = is_file($abs) ? (string) filemtime($abs) : '1';

    return raiz_web() . '/' . $ruta . '?v=' . $v;
}

function validar_api_key(): void
{
    $recibida = $_GET['api_key'] ?? '';
    if (!hash_equals(ESP32_API_KEY, (string) $recibida)) {
        json_esp32(['ok' => false, 'error' => 'api_key invalida'], 401);
    }
}

// ---------------------------------------------------------------------------
// Generacion de codigos
// ---------------------------------------------------------------------------

function generar_codigo_reserva(): string
{
    return 'RSV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Formatea una fecha/hora para la interfaz: "13 Oct, 00:20 hs".
 */
function fecha_amigable(?string $fecha): string
{
    if (!$fecha) {
        return '—';
    }

    $d     = new DateTimeImmutable($fecha);
    $meses = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    return $d->format('d') . ' ' . $meses[(int) $d->format('n')] . ', ' . $d->format('H:i') . ' hs';
}

/**
 * Devuelve las iniciales de un nombre para el avatar.
 */
function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $ini    = strtoupper(substr($partes[0] ?? '', 0, 1) . substr($partes[1] ?? '', 0, 1));

    return $ini !== '' ? $ini : '?';
}

// ---------------------------------------------------------------------------
// Vehiculos
// ---------------------------------------------------------------------------

/**
 * Elimina un vehiculo del usuario.
 * No permite borrar vehiculos con reservas activas (pendiente / ingresado).
 * El historial (finalizado / cancelada) se elimina en cascada con el vehiculo.
 *
 * @return array{0:bool,1:?string}  [ok, mensaje de error]
 */
function eliminar_vehiculo(PDO $pdo, int $usuarioId, int $vehiculoId): array
{
    $st = $pdo->prepare('SELECT * FROM vehiculos WHERE id = :id AND usuario_id = :u');
    $st->execute(['id' => $vehiculoId, 'u' => $usuarioId]);
    if (!$st->fetch()) {
        return [false, 'No encontramos ese vehiculo.'];
    }

    $act = $pdo->prepare(
        "SELECT COUNT(*) FROM reservas
          WHERE vehiculo_id = :id AND estado IN ('pendiente','ingresado')"
    );
    $act->execute(['id' => $vehiculoId]);
    if ((int) $act->fetchColumn() > 0) {
        return [false, 'No podes eliminar un vehiculo con reservas activas.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM vehiculos WHERE id = :id AND usuario_id = :u')
            ->execute(['id' => $vehiculoId, 'u' => $usuarioId]);
        $pdo->commit();

        return [true, null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [false, 'No se pudo eliminar el vehiculo. Intenta de nuevo.'];
    }
}

// ---------------------------------------------------------------------------
// Disponibilidad de espacios
// ---------------------------------------------------------------------------

/**
 * Lista los espacios con su tarifa, estado y hora en que se liberan.
 *
 * @return array<int,array<string,mixed>>
 */
function disponibilidad_espacios(): array
{
    $pdo = db();

    return $pdo->query(
        "SELECT e.*, t.precio_hora,
                (SELECT MIN(r.fin)
                   FROM reservas r
                  WHERE r.espacio_id = e.id
                    AND r.estado IN ('pendiente','ingresado')
                    AND r.fin >= NOW()) AS libre_desde
           FROM espacios e
           LEFT JOIN tarifas t ON t.activo = 1 AND t.nombre = CONCAT('Hora ', e.tipo)
          ORDER BY e.codigo"
    )->fetchAll();
}

// ---------------------------------------------------------------------------
// Reserva activa del usuario
// ---------------------------------------------------------------------------

/**
 * Devuelve el contexto del usuario para la pantalla de barrera.
 *
 * modo:
 *   'salida'  -> hay una reserva ya ingresada (debe egresar)
 *   'entrada' -> hay una reserva pagada dentro de la ventana de ingreso
 *   'espera'  -> hay reserva pagada pero aun no llego la hora
 *   'ninguno' -> no hay nada que hacer
 */
function contexto_barrera(int $usuarioId): array
{
    $pdo = db();

    // 1) Vehiculo dentro del estacionamiento (entrada ya registrada).
    $stmt = $pdo->prepare(
        "SELECT r.*, e.codigo AS espacio_codigo, v.matricula
           FROM reservas r
           JOIN espacios e ON e.id = r.espacio_id
           JOIN vehiculos v ON v.id = r.vehiculo_id
          WHERE r.usuario_id = :uid AND r.estado = 'ingresado'
          ORDER BY r.ingreso_en DESC
          LIMIT 1"
    );
    $stmt->execute(['uid' => $usuarioId]);
    $ingresada = $stmt->fetch();
    if ($ingresada) {
        return ['modo' => 'salida', 'reserva' => $ingresada];
    }

    // 2) Reservas pagadas y pendientes de ingreso.
    $stmt = $pdo->prepare(
        "SELECT r.*, e.codigo AS espacio_codigo, v.matricula
           FROM reservas r
           JOIN espacios e ON e.id = r.espacio_id
           JOIN vehiculos v ON v.id = r.vehiculo_id
          WHERE r.usuario_id = :uid AND r.estado = 'pendiente' AND r.pagada = 1
          ORDER BY r.inicio ASC"
    );
    $stmt->execute(['uid' => $usuarioId]);

    $ahora = new DateTimeImmutable('now');
    
    $tolerancia = new DateInterval('PT' . TOLERANCIA_INGRESO_MIN . 'M');

    foreach ($stmt->fetchAll() as $reserva) {
        $inicio = new DateTimeImmutable($reserva['inicio']);
        $fin    = new DateTimeImmutable($reserva['fin']);

        if ($ahora >= $inicio->sub($tolerancia) && $ahora <= $fin) {
            return ['modo' => 'entrada', 'reserva' => $reserva];
        }

        if ($ahora < $inicio->sub($tolerancia)) {
            return ['modo' => 'espera', 'reserva' => $reserva];
        }
    }

    return ['modo' => 'ninguno', 'reserva' => null];
}

// ---------------------------------------------------------------------------
// Cola de comandos del ESP32
// ---------------------------------------------------------------------------

/**
 * Encola un comando fisico de barrera. Devuelve el id creado.
 */
function encolar_comando(string $tipo, ?int $reservaId = null): int
{
    if (!in_array($tipo, ['entrada', 'salida'], true)) {
        throw new InvalidArgumentException('Tipo de comando invalido.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO barrier_comandos (tipo, reserva_id, estado, creado_en)
         VALUES (:tipo, :reserva, 'pendiente', NOW())"
    );
    $stmt->execute(['tipo' => $tipo, 'reserva' => $reservaId]);

    return (int) $pdo->lastInsertId();
}

/**
 * Toma el proximo comando pendiente y lo marca como 'enviado'.
 * Reencola los comandos 'enviado' cuyo ack nunca llego.
 *
 * @return array{tipo:string,id:int,reserva_id:?int}|null
 */
function tomar_comando_pendiente(): ?array
{
    $pdo = db();

    // Recuperar comandos enviados sin ack (ack perdido).
    $pdo->exec(
        "UPDATE barrier_comandos
            SET estado = 'pendiente', enviado_en = NULL
          WHERE estado = 'enviado'
            AND enviado_en < (NOW() - INTERVAL " . COMANDO_REENVIO_SEG . " SECOND)"
    );

    // FOR UPDATE requiere transaccion para evitar doble entrega.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT id, tipo, reserva_id
               FROM barrier_comandos
              WHERE estado = 'pendiente'
              ORDER BY id ASC
              LIMIT 1
              FOR UPDATE"
        );
        $comando = $stmt->fetch();

        if (!$comando) {
            $pdo->commit();
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE barrier_comandos
                SET estado = 'enviado', enviado_en = NOW()
              WHERE id = :id"
        );
        $upd->execute(['id' => $comando['id']]);
        $pdo->commit();

        return [
            'id'         => (int) $comando['id'],
            'tipo'       => $comando['tipo'],
            'reserva_id' => $comando['reserva_id'] !== null ? (int) $comando['reserva_id'] : null,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Confirma la ejecucion de un comando (ack del ESP32).
 */
function ejecutar_comando(int $comandoId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE barrier_comandos
            SET estado = 'ejecutado', ejecutado_en = NOW()
          WHERE id = :id AND estado <> 'ejecutado'"
    );
    $stmt->execute(['id' => $comandoId]);

    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Estado del dispositivo
// ---------------------------------------------------------------------------

/**
 * Registra el latido (heartbeat) del ESP32.
 */
function registrar_latido(?string $ip, ?string $estado): void
{
    $estado = in_array($estado, ['abierta', 'cerrada'], true) ? $estado : 'desconocido';

    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO barrier_dispositivos (identificador, ip, estado, ultimo_latido)
         VALUES (:id, :ip, :estado, NOW())
         ON DUPLICATE KEY UPDATE ip = VALUES(ip), estado = VALUES(estado), ultimo_latido = NOW()"
    );
    $stmt->execute(['id' => DISPOSITIVO_ID, 'ip' => $ip, 'estado' => $estado]);
}

/**
 * Estado de linea del ESP32 para la interfaz web.
 */
function estado_dispositivo(): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT ip, estado, ultimo_latido,
                (ultimo_latido IS NOT NULL
                 AND ultimo_latido >= (NOW() - INTERVAL " . (int) DISPOSITIVO_TIMEOUT_SEG . " SECOND)) AS en_linea
           FROM barrier_dispositivos
          WHERE identificador = :id"
    );
    $stmt->execute(['id' => DISPOSITIVO_ID]);
    $row = $stmt->fetch();

    return [
        'en_linea'      => (bool) ($row['en_linea'] ?? false),
        'estado'        => $row['estado'] ?? 'desconocido',
        'ip'            => $row['ip'] ?? null,
        'ultimo_latido' => $row['ultimo_latido'] ?? null,
    ];
}

// ===========================================================================
// ADMINISTRACION
// ===========================================================================

// ---------------------------------------------------------------------------
// Tarifas (precio por hora de autos y motos)
// ---------------------------------------------------------------------------

/**
 * Lista todas las tarifas, dejando primero las de estacionamiento.
 *
 * @return array<int,array<string,mixed>>
 */
function tarifas_todas(): array
{
    return db()->query(
        "SELECT * FROM tarifas
          ORDER BY (nombre LIKE 'Hora %') DESC, nombre"
    )->fetchAll();
}

/**
 * Actualiza el precio y el estado de una tarifa.
 *
 * @return array{0:bool,1:?string}  [ok, mensaje de error]
 */
function tarifa_actualizar(int $id, float $precio, bool $activo): array
{
    if ($precio < 0 || $precio > 1000000) {
        return [false, 'El precio debe estar entre 0 y 1.000.000.'];
    }

    $pdo = db();
    $ex  = $pdo->prepare('SELECT COUNT(*) FROM tarifas WHERE id = :id');
    $ex->execute(['id' => $id]);
    if ((int) $ex->fetchColumn() === 0) {
        return [false, 'Tarifa no encontrada.'];
    }

    $st = $pdo->prepare('UPDATE tarifas SET precio_hora = :p, activo = :a WHERE id = :id');
    $st->execute(['p' => round($precio, 2), 'a' => $activo ? 1 : 0, 'id' => $id]);

    return [true, null];
}

// ---------------------------------------------------------------------------
// Estadisticas generales
// ---------------------------------------------------------------------------

/**
 * Reune los indicadores del estacionamiento para el panel de administracion.
 *
 * @return array<string,mixed>
 */
function admin_estadisticas(): array
{
    $pdo = db();
    $e   = [];

    // Ocupacion de plazas.
    $esp = ['libre' => 0, 'reservado' => 0, 'ocupado' => 0, 'mantenimiento' => 0];
    foreach ($pdo->query('SELECT estado, COUNT(*) c FROM espacios GROUP BY estado') as $r) {
        $esp[$r['estado']] = (int) $r['c'];
    }
    $e['espacios']       = $esp;
    $e['espacios_total'] = array_sum($esp);
    $e['ocupacion_pct']  = $e['espacios_total'] > 0
        ? round((($esp['ocupado'] + $esp['reservado']) / $e['espacios_total']) * 100, 1)
        : 0.0;

    // Usuarios y vehiculos.
    $e['usuarios']  = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $e['admins']    = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'")->fetchColumn();
    $e['vehiculos'] = (int) $pdo->query('SELECT COUNT(*) FROM vehiculos')->fetchColumn();

    // Reservas por estado.
    $res = ['pendiente' => 0, 'ingresado' => 0, 'finalizado' => 0, 'cancelada' => 0];
    foreach ($pdo->query('SELECT estado, COUNT(*) c FROM reservas GROUP BY estado') as $r) {
        $res[$r['estado']] = (int) $r['c'];
    }
    $e['reservas']       = $res;
    $e['reservas_total'] = array_sum($res);

    // Ingresos (los pagos no se devuelven, por eso se cuenta todo pago realizado).
    $e['ingresos_total'] = (float) $pdo->query(
        'SELECT COALESCE(SUM(monto), 0) FROM reservas WHERE pagada = 1'
    )->fetchColumn();
    $e['ingresos_hoy'] = (float) $pdo->query(
        'SELECT COALESCE(SUM(monto), 0) FROM reservas WHERE pagada = 1 AND DATE(pagado_en) = CURDATE()'
    )->fetchColumn();
    $e['ingresos_mes'] = (float) $pdo->query(
        'SELECT COALESCE(SUM(monto), 0) FROM reservas
          WHERE pagada = 1
            AND YEAR(pagado_en) = YEAR(CURDATE())
            AND MONTH(pagado_en) = MONTH(CURDATE())'
    )->fetchColumn();
    $e['pagos_count']     = (int) $pdo->query('SELECT COUNT(*) FROM reservas WHERE pagada = 1')->fetchColumn();
    $e['ticket_promedio'] = $e['pagos_count'] > 0
        ? round($e['ingresos_total'] / $e['pagos_count'], 2)
        : 0.0;

    // Ingresos por tipo de plaza (auto / moto).
    $e['ingresos_tipo'] = $pdo->query(
        "SELECT e.tipo, COALESCE(SUM(r.monto), 0) total, COUNT(*) c
           FROM reservas r
           JOIN espacios e ON e.id = r.espacio_id
          WHERE r.pagada = 1
          GROUP BY e.tipo"
    )->fetchAll();

    // Ingresos de los ultimos 7 dias.
    $e['ingresos_7d'] = $pdo->query(
        "SELECT DATE(pagado_en) d, COALESCE(SUM(monto), 0) total
           FROM reservas
          WHERE pagada = 1 AND pagado_en >= (CURDATE() - INTERVAL 6 DAY)
          GROUP BY DATE(pagado_en)
          ORDER BY d"
    )->fetchAll();

    // Plazas mas usadas.
    $e['top_espacios'] = $pdo->query(
        "SELECT e.codigo, e.tipo, COUNT(*) c, COALESCE(SUM(r.monto), 0) total
           FROM reservas r
           JOIN espacios e ON e.id = r.espacio_id
          GROUP BY e.id, e.codigo, e.tipo
          ORDER BY c DESC
          LIMIT 5"
    )->fetchAll();

    return $e;
}

/**
 * Serie de los ultimos 7 dias (rellena los dias sin movimientos con 0).
 *
 * @param array<int,array<string,mixed>> $filas  Resultado de ingresos_7d
 * @return array<int,array{dia:string,label:string,total:float}>
 */
function admin_serie_7d(array $filas): array
{
    $mapa = [];
    foreach ($filas as $f) {
        $mapa[(string) $f['d']] = (float) $f['total'];
    }

    $dias   = [1 => 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab', 'Dom'];
    $serie  = [];
    $hoy    = new DateTimeImmutable('today');

    for ($i = 6; $i >= 0; $i--) {
        $dia = $hoy->sub(new DateInterval('P' . $i . 'D'));
        $clave = $dia->format('Y-m-d');
        $serie[] = [
            'dia'   => $clave,
            'label' => $dias[(int) $dia->format('N')],
            'total' => $mapa[$clave] ?? 0.0,
        ];
    }

    return $serie;
}

// ---------------------------------------------------------------------------
// Consultas del panel
// ---------------------------------------------------------------------------

/**
 * Reservas de todos los usuarios, opcionalmente filtradas por estado.
 *
 * @return array<int,array<string,mixed>>
 */
function admin_reservas(?string $estado = null, int $limite = 100): array
{
    $pdo = db();
    $sql = "SELECT r.*, e.codigo AS espacio_codigo, e.tipo AS espacio_tipo,
                   v.matricula, u.nombre AS usuario_nombre, u.email AS usuario_email
              FROM reservas r
              JOIN espacios e ON e.id = r.espacio_id
              JOIN vehiculos v ON v.id = r.vehiculo_id
              JOIN usuarios u ON u.id = r.usuario_id";

    $params = [];
    if ($estado !== null && in_array($estado, ['pendiente', 'ingresado', 'finalizado', 'cancelada'], true)) {
        $sql .= ' WHERE r.estado = :estado';
        $params['estado'] = $estado;
    }

    $sql .= ' ORDER BY r.creado_en DESC LIMIT ' . max(1, min(500, $limite));

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll();
}

/**
 * Ultimos pagos registrados.
 *
 * @return array<int,array<string,mixed>>
 */
function admin_pagos(int $limite = 50): array
{
    $pdo = db();
    $st  = $pdo->query(
        "SELECT r.monto, r.pagado_en, r.codigo, r.estado,
                v.matricula, e.codigo AS espacio_codigo, e.tipo AS espacio_tipo,
                u.nombre AS usuario_nombre
           FROM reservas r
           JOIN vehiculos v ON v.id = r.vehiculo_id
           JOIN espacios  e ON e.id = r.espacio_id
           JOIN usuarios  u ON u.id = r.usuario_id
          WHERE r.pagada = 1
          ORDER BY r.pagado_en DESC, r.id DESC
          LIMIT " . max(1, min(500, $limite))
    );

    return $st->fetchAll();
}

/**
 * Ultimos comandos de barrera.
 *
 * @return array<int,array<string,mixed>>
 */
function admin_comandos(int $limite = 12): array
{
    $pdo = db();
    $st  = $pdo->query(
        "SELECT c.*, r.codigo AS reserva_codigo
           FROM barrier_comandos c
           LEFT JOIN reservas r ON r.id = c.reserva_id
          ORDER BY c.id DESC
          LIMIT " . max(1, min(200, $limite))
    );

    return $st->fetchAll();
}

/**
 * Vehiculos que estan actualmente dentro del estacionamiento.
 *
 * @return array<int,array<string,mixed>>
 */
function admin_vehiculos_dentro(): array
{
    $pdo = db();
    return $pdo->query(
        "SELECT r.id, r.codigo, r.ingreso_en, r.fin,
                e.codigo AS espacio_codigo, e.tipo AS espacio_tipo,
                v.matricula, u.nombre AS usuario_nombre
           FROM reservas r
           JOIN espacios e ON e.id = r.espacio_id
           JOIN vehiculos v ON v.id = r.vehiculo_id
           JOIN usuarios u ON u.id = r.usuario_id
          WHERE r.estado = 'ingresado'
          ORDER BY r.ingreso_en DESC"
    )->fetchAll();
}

// ---------------------------------------------------------------------------
// Acciones de administracion
// ---------------------------------------------------------------------------

/**
 * Apertura manual de barrera (sin reserva asociada).
 *
 * @return array{0:bool,1:string|int}  [ok, id de comando o mensaje de error]
 */
function admin_abrir_barrera(string $tipo)
{
    if (!in_array($tipo, ['entrada', 'salida'], true)) {
        return [false, 'Tipo de apertura invalido.'];
    }

    try {
        $id = encolar_comando($tipo, null);
        return [true, $id];
    } catch (Throwable $e) {
        return [false, 'No se pudo encolar la apertura.'];
    }
}

/**
 * Fuerza el ingreso de una reserva pendiente y pagada.
 *
 * @return array{0:bool,1:?string}
 */
function admin_forzar_ingreso(int $reservaId): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM reservas WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $reservaId]);
        $r = $st->fetch();

        if (!$r) {
            $pdo->rollBack();
            return [false, 'Reserva no encontrada.'];
        }
        if ($r['estado'] !== 'pendiente' || (int) $r['pagada'] !== 1) {
            $pdo->rollBack();
            return [false, 'Solo se puede dar ingreso a reservas pendientes y pagadas.'];
        }

        $pdo->prepare("UPDATE reservas SET estado = 'ingresado', ingreso_en = NOW() WHERE id = :id")
            ->execute(['id' => $reservaId]);
        $pdo->prepare("UPDATE espacios SET estado = 'ocupado' WHERE id = :id")
            ->execute(['id' => $r['espacio_id']]);

        encolar_comando('entrada', $reservaId);
        $pdo->commit();

        return [true, $r['codigo']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'No se pudo registrar el ingreso.'];
    }
}

/**
 * Fuerza la salida de un vehiculo ingresado.
 *
 * @return array{0:bool,1:?string}
 */
function admin_forzar_salida(int $reservaId): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM reservas WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $reservaId]);
        $r = $st->fetch();

        if (!$r) {
            $pdo->rollBack();
            return [false, 'Reserva no encontrada.'];
        }
        if ($r['estado'] !== 'ingresado') {
            $pdo->rollBack();
            return [false, 'La reserva no esta ingresada.'];
        }

        $pdo->prepare("UPDATE reservas SET estado = 'finalizado', egreso_en = NOW() WHERE id = :id")
            ->execute(['id' => $reservaId]);
        $pdo->prepare("UPDATE espacios SET estado = 'libre' WHERE id = :id")
            ->execute(['id' => $r['espacio_id']]);

        encolar_comando('salida', $reservaId);
        $pdo->commit();

        return [true, $r['codigo']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'No se pudo registrar el egreso.'];
    }
}

/**
 * Cancela una reserva pendiente y libera su plaza (el pago no se devuelve).
 *
 * @return array{0:bool,1:?string}
 */
function admin_cancelar_reserva(int $reservaId): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM reservas WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $reservaId]);
        $r = $st->fetch();

        if (!$r) {
            $pdo->rollBack();
            return [false, 'Reserva no encontrada.'];
        }
        if ($r['estado'] !== 'pendiente') {
            $pdo->rollBack();
            return [false, 'Solo se pueden cancelar reservas pendientes.'];
        }

        $pdo->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = :id")
            ->execute(['id' => $reservaId]);
        $pdo->prepare("UPDATE espacios SET estado = 'libre' WHERE id = :id AND estado = 'reservado'")
            ->execute(['id' => $r['espacio_id']]);

        $pdo->commit();

        return [true, $r['codigo']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'No se pudo cancelar la reserva.'];
    }
}

/**
 * Pone una plaza en libre o mantenimiento (no permite tocar plazas con reserva activa).
 *
 * @return array{0:bool,1:?string}
 */
function admin_set_espacio_estado(int $espacioId, string $estado): array
{
    if (!in_array($estado, ['libre', 'mantenimiento'], true)) {
        return [false, 'Estado de plaza invalido.'];
    }

    $pdo = db();
    $st  = $pdo->prepare('SELECT * FROM espacios WHERE id = :id');
    $st->execute(['id' => $espacioId]);
    $e = $st->fetch();
    if (!$e) {
        return [false, 'Plaza no encontrada.'];
    }

    $act = $pdo->prepare(
        "SELECT COUNT(*) FROM reservas
          WHERE espacio_id = :id AND estado IN ('pendiente', 'ingresado')"
    );
    $act->execute(['id' => $espacioId]);
    if ((int) $act->fetchColumn() > 0) {
        return [false, 'La plaza tiene una reserva activa.'];
    }

    $pdo->prepare('UPDATE espacios SET estado = :s WHERE id = :id')
        ->execute(['s' => $estado, 'id' => $espacioId]);

    return [true, $e['codigo']];
}
