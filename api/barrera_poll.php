<?php
/**
 * Parking Tech - API IoT: polling del ESP32
 * -----------------------------------------------------------------------------
 * GET api/barrera_poll.php?api_key=...&ip=...&estado=...
 *
 * Contrato EXACTO con el sketch del ESP32 (inmutable):
 *   - Valida api_key.
 *   - Registra el latido y la IP de la placa (heartbeat).
 *   - Si hay comando pendiente responde:
 *         {"hay":true,"id_comando":N,"tipo":"entrada"|"salida"}
 *   - Si no hay nada: {"hay":false}
 *
 * El comando se marca como 'enviado' aqui y se confirma en barrera_ack.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/funciones.php';

validar_api_key();

$ip     = isset($_GET['ip']) ? substr((string) $_GET['ip'], 0, 45) : null;
$estado = isset($_GET['estado']) ? (string) $_GET['estado'] : null;

registrar_latido($ip, $estado);

$comando = tomar_comando_pendiente();

if ($comando === null) {
    json_esp32(['hay' => false]);
}

json_esp32([
    'hay'        => true,
    'id_comando' => $comando['id'],
    'tipo'       => $comando['tipo'],
]);
