<?php
/**
 * Parking Tech - Configuracion global
 * -----------------------------------------------------------------------------
 * Constantes de conexion, credenciales de la API IoT y parametros de negocio.
 */

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// --- Base de datos (XAMPP por defecto) ---------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'parkingtech2.0');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- API IoT -----------------------------------------------------------------
// Debe coincidir EXACTAMENTE con API_KEY del sketch del ESP32 (inmutable).
define('ESP32_API_KEY', 'parkingtech-porteria-2026');

// Identificador del dispositivo esperado en barrera_poll.php.
define('DISPOSITIVO_ID', 'esp32-barrera');

// --- Reglas de negocio de barrera -------------------------------------------
// Minutos de anticipacion con los que se permite el ingreso.
define('TOLERANCIA_INGRESO_MIN', 15);

// Segundos sin latido tras los cuales el ESP32 se considera "fuera de linea".
define('DISPOSITIVO_TIMEOUT_SEG', 10);

// Segundos que un comando puede estar "enviado" antes de reencolarse
// (protege contra un ack perdido sin reaccionar el servo dos veces).
define('COMANDO_REENVIO_SEG', 10);

// --- Postal / duracion de reserva -------------------------------------------
define('DURACION_RESERVA_MIN_DEFAULT', 60);

// --- Seguridad de sesion (IAM) ----------------------------------------------
// Clave secreta usada para la huella de sesion. CAMBIAR en produccion por un
// valor aleatorio largo (ej. `php -r "echo bin2hex(random_bytes(32));"`).
define('SESSION_SECRET', 'cambia-esta-clave-por-una-aleatoria-larga-en-produccion');

// Minutos/segundos de inactividad antes de cerrar la sesion.
define('SESSION_IDLE_SEG', 1800);   // 30 minutos sin actividad
define('SESSION_ABS_SEG', 28800);   // 8 horas de duracion maxima
define('SESSION_REGEN_SEG', 900);   // regenerar el id de sesion cada 15 min

// true SOLO si el sitio corre detras de un proxy/balanceador de confianza que
// setea X-Forwarded-For / X-Forwarded-Proto. Si no, se ignora (evita spoofing).
define('PROXY_CONFIABLE', false);

// Carpeta para los contadores de rate limiting (fuera del docroot publico).
define('RATE_LIMIT_DIR', dirname(__DIR__) . '/storage/rate');

// --- Rate limiting (anti fuerza bruta) --------------------------------------
define('RATE_LOGIN_IP_MAX', 30);        // intentos de login por IP
define('RATE_LOGIN_IP_VENTANA', 300);   // en 5 minutos
define('RATE_LOGIN_MAIL_MAX', 5);       // intentos fallidos por email+IP
define('RATE_LOGIN_MAIL_VENTANA', 900);// en 15 minutos
define('RATE_REGISTRO_MAX', 10);        // altas por IP
define('RATE_REGISTRO_VENTANA', 600);   // en 10 minutos
define('RATE_BARRERA_USER_MAX', 20);    // aperturas de barrera por usuario
define('RATE_BARRERA_VENTANA', 60);     // en 60 segundos
