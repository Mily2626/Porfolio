<?php
/**
 * Parking Tech - Nucleo de seguridad
 * -----------------------------------------------------------------------------
 * Reune las defensas transversales del lado servidor:
 *   - Cabeceras de seguridad HTTP (CSP, anti-clickjacking, nosniff, HSTS...).
 *   - Arranque de sesion endurecido (cookies, huella, expiracion, regeneracion).
 *   - Rate limiting generico en disco (anti fuerza bruta), sin dependencias.
 *
 * NO accede a la base de datos: puede usarse en cualquier endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Utilidades de entorno
// ---------------------------------------------------------------------------

/**
 * Indica si la peticion llego por HTTPS.
 * No confia en X-Forwarded-Proto salvo que PROXY_CONFIABLE sea true.
 */
function es_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (PROXY_CONFIABLE && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }

    return false;
}

/**
 * IP del cliente. Solo usa X-Forwarded-For con un proxy de confianza.
 */
function cliente_ip(): string
{
    if (PROXY_CONFIABLE && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $partes = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($partes[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------------------------------------------------------------------------
// Cabeceras de seguridad
// ---------------------------------------------------------------------------

/**
 * Envia las cabeceras de seguridad de la respuesta. Idempotente y segura de
 * llamar aun despues de haber enviado otras cabeceras.
 */
function cabeceras_seguridad(): void
{
    if (headers_sent()) {
        return;
    }

    // Endurecimiento general.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    // Content-Security-Policy: solo recursos propios + fuentes/iconos CDN.
    // 'unsafe-inline' solo en style-src (la interfaz usa atributos style="...").
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "object-src 'none'; "
        . "img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        . "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
        . "script-src 'self'; "
        . "connect-src 'self'"
    );

    // HSTS solo cuando ya hay HTTPS (no se manda por HTTP plano).
    if (es_https()) {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
    }

    // Evita cache de HTML (moviles sirviendo CSS/JS viejos) y de respuestas API.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ---------------------------------------------------------------------------
// Sesion endurecida
// ---------------------------------------------------------------------------

/**
 * Arranca la sesion con cookies seguras, huella de dispositivo, expiracion por
 * inactividad/duracion y regeneracion periodica del id.
 *
 * La huella (User-Agent + SESSION_SECRET) permite detectar el uso de una cookie
 * robada desde otro dispositivo y cierra la sesion automaticamente.
 */
function iniciar_sesion_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = es_https();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($https) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('PT_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $ahora  = time();
    $huella = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . SESSION_SECRET);

    // Primera visita: inicializa los metadatos de la sesion.
    if (empty($_SESSION['_huella'])) {
        $_SESSION['_huella'] = $huella;
        $_SESSION['_inicio'] = $ahora;
        $_SESSION['_visto']  = $ahora;
        $_SESSION['_regen']  = $ahora;

        return;
    }

    $inactividad = $ahora - (int) ($_SESSION['_visto'] ?? $ahora);
    $duracion    = $ahora - (int) ($_SESSION['_inicio'] ?? $ahora);

    // Huella distinta (posible robo de cookie) o expiracion -> reinicia sesion.
    if (!hash_equals((string) $_SESSION['_huella'], $huella)
        || $inactividad > SESSION_IDLE_SEG
        || $duracion > SESSION_ABS_SEG
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_huella'] = $huella;
        $_SESSION['_inicio'] = $ahora;
        $_SESSION['_visto']  = $ahora;
        $_SESSION['_regen']  = $ahora;
        $_SESSION['flash']   = [
            'tipo'    => 'error',
            'mensaje' => 'Tu sesion expiro o cambio de dispositivo. Volve a ingresar.',
        ];

        return;
    }

    $_SESSION['_visto'] = $ahora;

    // Regenera el id periodicamente (mitiga fijacion y robo de identificador).
    if ($ahora - (int) ($_SESSION['_regen'] ?? $ahora) > SESSION_REGEN_SEG) {
        session_regenerate_id(true);
        $_SESSION['_regen'] = $ahora;
    }
}

/**
 * Regenera el id de sesion conservando los datos. Llamar tras un cambio de
 * privilegio (login, cambio de rol, etc.).
 */
function regenerar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// ---------------------------------------------------------------------------
// Rate limiting (contadores en disco, ventana deslizante)
// ---------------------------------------------------------------------------

function rl_carpeta(): string
{
    if (!is_dir(RATE_LIMIT_DIR)) {
        @mkdir(RATE_LIMIT_DIR, 0775, true);
    }

    return RATE_LIMIT_DIR;
}

function rl_archivo(string $clave): string
{
    return rl_carpeta() . '/' . hash('sha256', $clave) . '.json';
}

/**
 * Lee las marcas de tiempo vigentes de una clave.
 *
 * @return array<int,int>
 */
function rl_leer(string $clave): array
{
    $f = rl_archivo($clave);
    if (!is_file($f)) {
        return [];
    }

    $fp = @fopen($f, 'r');
    if (!$fp) {
        return [];
    }

    $hits = [];
    if (flock($fp, LOCK_SH)) {
        $raw  = (string) stream_get_contents($fp);
        flock($fp, LOCK_UN);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $hits = array_map('intval', $json);
        }
    }
    fclose($fp);

    return $hits;
}

/**
 * Evalua el estado de una clave SIN registrar un evento nuevo.
 *
 * @return array{allowed:bool,count:int,max:int,retry_after:int}
 */
function rate_limit_estado(string $clave, int $max, int $ventana): array
{
    $ahora = time();
    $hits  = array_values(array_filter(
        rl_leer($clave),
        static fn (int $t): bool => ($ahora - $t) < $ventana
    ));

    $allowed = count($hits) < $max;
    $retry   = 0;
    if (!$allowed && $hits !== []) {
        $retry = $ventana - ($ahora - min($hits));
    }

    return [
        'allowed'     => $allowed,
        'count'       => count($hits),
        'max'         => $max,
        'retry_after' => max(0, $retry),
    ];
}

/**
 * Registra un evento en la clave (lectura-modificacion-escritura con lock).
 */
function rate_limit_registrar(string $clave, int $ventana): void
{
    $ahora = time();
    $f     = rl_archivo($clave);
    $fp    = @fopen($f, 'c+');
    if (!$fp) {
        return;
    }

    if (flock($fp, LOCK_EX)) {
        $raw  = (string) stream_get_contents($fp);
        $json = json_decode($raw, true);
        $hits = is_array($json) ? array_map('intval', $json) : [];
        $hits = array_values(array_filter(
            $hits,
            static fn (int $t): bool => ($ahora - $t) < $ventana
        ));
        $hits[] = $ahora;
        $hits   = array_slice($hits, -200);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($hits));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/**
 * Borra el contador de una clave (ej. tras un login exitoso).
 */
function rate_limit_limpiar(string $clave): void
{
    $f = rl_archivo($clave);
    if (is_file($f)) {
        @unlink($f);
    }
}

/**
 * Respuesta JSON 429 estandar para endpoints limitados.
 */
function rate_limit_json(array $estado): void
{
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: ' . (int) $estado['retry_after']);
    echo json_encode([
        'ok'          => false,
        'error'       => 'Demasiadas solicitudes. Espera unos segundos e intenta de nuevo.',
        'retry_after' => (int) $estado['retry_after'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
