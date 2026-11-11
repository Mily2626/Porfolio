<?php
/**
 * Parking Tech - API IoT: confirmacion (ack) del ESP32
 * -----------------------------------------------------------------------------
 * GET api/barrera_ack.php?api_key=...&id=...
 *
 * El ESP32 avisa que ya acciono el servo correspondiente al comando `id`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/funciones.php';

validar_api_key();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    json_esp32(['ok' => false, 'error' => 'id invalido'], 400);
}

$ok = ejecutar_comando($id);

json_esp32(['ok' => $ok]);
