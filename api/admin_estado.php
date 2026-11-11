<?php
/**
 * Parking Tech - API Web (administracion): estado en vivo
 * -----------------------------------------------------------------------------
 * GET api/admin_estado.php
 *
 * Requiere sesion de administrador. Devuelve el estado del dispositivo de
 * barrera, los ultimos comandos y un resumen del estacionamiento, para que el
 * panel de administracion pueda refrescarse sin recargar la pagina.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$usuario = usuario_actual();
if (!$usuario || ($usuario['rol'] ?? 'usuario') !== 'admin') {
    json_out(['ok' => false, 'error' => 'Acceso no autorizado.'], 403);
}

$pdo = db();

$esp = ['libre' => 0, 'reservado' => 0, 'ocupado' => 0, 'mantenimiento' => 0];
foreach ($pdo->query('SELECT estado, COUNT(*) c FROM espacios GROUP BY estado') as $r) {
    $esp[$r['estado']] = (int) $r['c'];
}

$dentro = (int) $pdo->query("SELECT COUNT(*) FROM reservas WHERE estado = 'ingresado'")->fetchColumn();
$pendientes = (int) $pdo->query("SELECT COUNT(*) FROM reservas WHERE estado = 'pendiente'")->fetchColumn();

json_out([
    'ok'          => true,
    'dispositivo' => estado_dispositivo(),
    'espacios'    => $esp,
    'dentro'      => $dentro,
    'pendientes'  => $pendientes,
    'comandos'    => array_map(static function (array $c): array {
        return [
            'id'         => (int) $c['id'],
            'tipo'       => $c['tipo'],
            'estado'     => $c['estado'],
            'codigo'     => $c['reserva_codigo'],
            'creado_en'  => $c['creado_en'],
            'manual'     => $c['reserva_id'] === null,
        ];
    }, admin_comandos(8)),
    'ahora'       => (new DateTimeImmutable('now'))->format('H:i:s'),
]);
