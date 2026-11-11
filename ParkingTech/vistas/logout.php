<?php
/**
 * Parking Tech - Cerrar sesion
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

cerrar_sesion();

header('Location: ' . raiz_web() . '/vistas/login.php');
exit;
