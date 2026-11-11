<?php
/**
 * Parking Tech - Punto de entrada
 * Redirige al panel o al login segun la sesion.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (usuario_actual()) {
    header('Location: ' . ruta_inicio());
} else {
    header('Location: ' . raiz_web() . '/vistas/login.php');
}
exit;
