# Parking Tech

Estacionamiento inteligente que opera **exclusivamente por reservas**. No existe
registro de ingreso instantaneo (walk-in) ni sesiones de estacionamiento: todo
vehiculo que entra lo hace con una reserva previa, pagada y validada por horario.

El sistema combina una aplicacion web (PHP + MySQL) con control fisico de
barreras mediante un **ESP32 con dos servos** (entrada y salida) que se comunica
**solo por polling saliente** (el servidor nunca inicia conexion hacia la placa).

---

## Arquitectura

```
parking tech2.0/
├── index.php                     Punto de entrada (redirige segun sesion)
├── includes/
│   ├── config.php                DB, API key, reglas de negocio
│   ├── db.php                    Conexion PDO (singleton)
│   ├── funciones.php             Reservas, cola de comandos, heartbeat
│   └── auth.php                  Sesion de usuario, CSRF, rutas
├── api/
│   ├── barrera_poll.php          [ESP32] entrega comando + registra latido
│   ├── barrera_ack.php           [ESP32] confirma comando ejecutado
│   ├── abrir_barrera.php         [WEB]  estado / entrada / salida (JSON)
│   └── admin_estado.php          [ADMIN] estado en vivo (dispositivo/comandos)
├── vistas/
│   ├── login.php                 Ingreso
│   ├── registro.php              Alta de cuenta + vehiculo
│   ├── dashboard.php             Panel: reservas y vehiculos
│   ├── nueva_reserva.php         Crear reserva + pago (simulado)
│   ├── admin.php                 Panel de administracion (rol admin)
│   ├── abrir_barrera.php         Pantalla de barrera (asincrona)
│   ├── logout.php
│   └── partials/                 header / footer
├── assets/
│   ├── css/estilos.css
│   └── js/barrera.js             Polling y acciones sin recargar
├── esp32/
│   └── ParkingTech_Barrera/
│       └── ParkingTech_Barrera.ino   Sketch de referencia (INMUTABLE)
└── sql/
    └── parkingtech2.0.sql        Esquema + datos iniciales
```

---

## Puesta en marcha (XAMPP)

1. **Base de datos**: importar `sql/parkingtech2.0.sql` (phpMyAdmin o consola):
   ```
   C:\xampp\mysql\bin\mysql -u root < sql\parkingtech2.0.sql
   ```

2. **Credenciales**: revisar `includes/config.php` (por defecto `root` sin
   contrasena, base `parkingtech2.0`).

3. **Ubicacion del proyecto** (ver contrato IoT mas abajo):
   debe quedar en `C:\xampp\htdocs\ParkingTech`.

4. **Usuarios demo**:

   | Rol    | Email                        | Contrasena |
   |--------|------------------------------|------------|
   | Admin  | admin@parkingtech.local      | admin123   |
   | Usuario| usuario@parkingtech.local    | usuario123 |

---

## Flujo exclusivo de reservas

1. El usuario se registra e inicia sesion.
2. Carga su vehiculo y crea una **reserva** eligiendo espacio y horario.
   El pago se procesa de forma simulada: la reserva queda `pendiente` y `pagada`.
   El espacio pasa a estado `reservado`.
3. Al acercarse la hora (tolerancia de `TOLERANCIA_INGRESO_MIN`), entra a
   **Abrir barrera**. La pantalla detecta automaticamente que corresponde
   **ENTRADA**. Al confirmar:
   - la reserva pasa a `ingresado` (guarda `ingreso_en`),
   - el espacio pasa a `ocupado`,
   - se **encola** un comando `entrada`.
4. Al volver, la pantalla detecta que corresponde **SALIDA** (hay una reserva
   `ingresado`). Al confirmar:
   - la reserva pasa a `finalizado` (guarda `egreso_en`),
   - el espacio vuelve a `libre`,
   - se encola un comando `salida`.

Estados de reserva: `pendiente` -> `ingresado` -> `finalizado` (y `cancelada`).

---

## Panel de administracion

Disponible para usuarios con rol `admin` (enlace "Administracion" en la barra de
navegacion). Reune cinco secciones:

- **Resumen**: ingresos (total/hoy/mes, ticket promedio), ocupacion, grafico de
  ingresos de los ultimos 7 dias, plazas mas usadas y control de plazas
  (habilitar / poner en mantenimiento).
- **Tarifas**: actualizar el precio por hora de **autos** y **motos** y su estado.
- **Barrera**: estado en vivo del ESP32, **apertura manual** de entrada/salida
  (sin reserva) e historial de comandos.
- **Ingresos**: total recaudado, pagos registrados e ingresos por tipo, con el
  detalle de pagos.
- **Reservas**: vehiculos dentro, todas las reservas con filtro por estado y
  control administrativo (forzar ingreso/salida, cancelar).

Los usuarios comunes no ven el enlace y son redirigidos al panel si intentan
acceder. `api/admin_estado.php` (solo admin) alimenta el refresco en vivo.

---

## Contrato IoT (debe respetarse: el sketch es INMUTABLE)

El ESP32 hace `GET` a `api/barrera_poll.php` cada segundo:

```
GET /ParkingTech/api/barrera_poll.php?api_key=...&ip={IP}&estado={abierta|cerrada}
```

Respuesta con comando pendiente (se busca literalmente `"hay":true`):

```json
{"hay":true,"id_comando":12,"tipo":"entrada"}
```

Sin comando:

```json
{"hay":false}
```

Tras accionar el servo, el ESP32 confirma:

```
GET /ParkingTech/api/barrera_ack.php?api_key=...&id=12
```

`barrera_poll.php` registra ademas el **latido** (IP + estado) en
`barrier_dispositivos`; la web muestra "ESP32 en linea" si el ultimo latido
tiene menos de `DISPOSITIVO_TIMEOUT_SEG` segundos.

La cola `barrier_comandos` maneja estados `pendiente -> enviado -> ejecutado`.
Un comando `enviado` sin ack se reencola automaticamente tras
`COMANDO_REENVIO_SEG` segundos (protege ante un ack perdido).

### Configuracion del sketch ESP32 (WiFi + servidor)

En `esp32/ParkingTech_Barrera/ParkingTech_Barrera.ino` se configuran la red y el
servidor. Valores actuales:

```cpp
const char* ssid     = "ESCUALO";
const char* password = "escualo123";
const char* SERVIDOR = "http://10.214.73.110/ParkingTech/api";
const char* API_KEY  = "parkingtech-porteria-2026";
```

Al cambiar de red hay que actualizar (y **reflashear** el ESP32):

- `ssid` / `password` de la red nueva (**debe ser 2.4 GHz**; el ESP32 no usa 5 GHz);
- `SERVIDOR` con la **IP de la PC** que corre Apache (aqui `10.214.73.110`). Como
  es una IP por DHCP puede cambiar: conviene reservarla en el router o fijarla.

Ademas:

- la carpeta del proyecto en `htdocs` **debe llamarse `ParkingTech`** (existe un
  junction `C:\xampp\htdocs\ParkingTech` → `parking tech2.0`) para que la ruta
  `/ParkingTech/api` funcione;
- la PC y el ESP32 deben quedar en la **misma red/subred**, sin aislamiento de
  clientes;
- el Firewall de Windows debe permitir Apache en el puerto **80** (regla entrante
  para todos los perfiles);
- `ESP32_API_KEY` en `includes/config.php` ya coincide con `API_KEY`.


---

## Seguridad

Esta seccion tiene dos partes: **(1)** lo que ya esta implementado en el codigo y
**(2)** una guia de endurecimiento ordenada por prioridad (`P0` imprescindible,
`P1` muy recomendado, `P2` avanzado) para los dos pilares de seguridad del
sistema: **IAM** (autenticacion y control de acceso) y **comunicacion/red**
(navegador ↔ backend ↔ ESP32).

### 1. Estado de implementacion (auditoria rapida)

| # | Control | Prioridad | Donde |
|---|---------|-----------|-------|
| 1 | Hash de contrasenas con `password_hash()` / `password_verify()` | P0 | `vistas/registro.php`, `includes/auth.php` |
| 2 | Consultas 100% con sentencias preparadas (PDO) | P0 | todo el codigo |
| 3 | Cookies de sesion `HttpOnly` + `SameSite=Lax` (+`Secure` bajo HTTPS) | P0 | `includes/security.php` |
| 4 | `session.use_strict_mode`, `use_only_cookies`, nombre propio `PT_SESSID` | P0 | `includes/security.php` |
| 5 | Regeneracion del id de sesion en login y cada 15 min | P0 | `includes/auth.php`, `includes/security.php` |
| 6 | Expiracion por inactividad (30 min) y maxima (8 h) | P0 | `includes/security.php` |
| 7 | Huella de sesion (User-Agent + secreto) anti-robo de cookie | P1 | `includes/security.php` |
| 8 | CSRF en formularios y en las acciones de barrera | P0 | `includes/auth.php`, `api/*` |
| 9 | RBAC: barrera solo con reserva propia, `pendiente`, `pagada` y en horario | P0 | `includes/funciones.php`, `api/abrir_barrera.php` |
| 10 | RBAC admin: `api/admin_estado.php` y panel exigen rol `admin` | P0 | `api/admin_estado.php`, `includes/auth.php` |
| 11 | Rate limiting en login (por IP y por email+IP) | P0 | `vistas/login.php`, `includes/security.php` |
| 12 | Rate limiting en registro y en apertura de barrera | P1 | `vistas/registro.php`, `api/abrir_barrera.php` |
| 13 | Cabeceras de seguridad: CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP | P0 | `includes/security.php` |
| 14 | `Strict-Transport-Security` (HSTS) automatico bajo HTTPS | P1 | `includes/security.php` |
| 15 | `api_key` del ESP32 comparada con `hash_equals()` (tiempo constante) | P0 | `includes/funciones.php` |
| 16 | `includes/`, `sql/` y `storage/` bloqueados; `.htaccess` raiz endurece Apache | P0 | `**/.htaccess` |
| 17 | Sin cache de HTML para no servir CSS/JS viejos | P2 | `includes/security.php` |

> Codigo nuevo de esta capa: `includes/security.php` (sesion endurecida,
> cabeceras, rate limiting) y las constantes al final de `includes/config.php`.

---

### 2. Arquitectura de seguridad (diagramas conceptuales)

**Pilar 1 - IAM (autenticacion, sesion y control de acceso)**

```
  Navegador                         Backend PHP (Apache)                 MySQL
  ─────────                         ────────────────────                 ─────
  POST /login  ── credenciales ─▶  verifica password_verify()
                                    ├─ rate limiting (IP + email+IP)
                                    ├─ session_regenerate_id()
                                    └─ Set-Cookie PT_SESSID
                                       HttpOnly; SameSite=Lax; [Secure]  ─▶ usuarios.password
                                                                             usuarios.rol
  GET /barrera ── cookie ────────▶  iniciar_sesion_segura()
                                    ├─ valida huella / expiracion
                                    └─ usuario_actual()  ──── rol ─────▶ (RBAC)
                                        │
  POST /api/abrir_barrera ─ csrf ─▶  ├─ rol/pertenencia de la reserva
                                     ├─ estado=pendiente AND pagada=1
                                     ├─ NOW() dentro de [inicio-tol, fin]
                                     ├─ rate limiting (por usuario)
                                     └─ FOR UPDATE + revalidacion
                                        └─ INSERT barrier_comandos ──────▶ (cola)
```

**Pilar 2 - Comunicacion y red (navegador ↔ backend ↔ ESP32)**

```
   ┌─────────────┐        HTTPS (TLS 1.2/1.3)        ┌──────────────────────┐
   │ Navegador   │ ═══════════════════════════════▶  │ Backend (PHP/Apache)  │
   └─────────────┘   CSP · HSTS · cookie Secure       │  + MySQL              │
                                                       └───────────┬──────────┘
                                                                   │  red interna
                                        ┌──────────────────────────┴───────────────┐
                                        │         RED AISLADA DEL PARKING           │
                                        │  VLAN propia + NAT (sin exposicion       │
                                        │  directa a Internet publico)             │
                                        │                                          │
                                        │   ◀── WireGuard VPN / mTLS ──▶           │
                                        │                                          │
                                        │  Broker MQTT sobre TLS (8883)  o         │
                                        │  HTTPS saliente con HMAC + pin/cert      │
                                        │                    │                     │
                                        │              ┌─────▼─────┐               │
                                        │              │  ESP32    │  (polling     │
                                        │              │ + 2 servos│   saliente)   │
                                        │              └───────────┘               │
                                        └──────────────────────────────────────────┘
```

---

### 3. Pilar 1 - Autenticacion y control de acceso (IAM)

#### P0 · Sesiones: cookies HttpOnly vs JWT

**Recomendacion para este proyecto: seguir con cookies de sesion** (no JWT en el
navegador). Motivos: la app es renderizada en servidor y una cookie `HttpOnly`
**no es accesible por JavaScript**, por lo que un XSS no puede robarla. Guardar
un JWT en `localStorage`/`sessionStorage` es justamente el peor escenario ante
XSS. Si en el futuro se expone una API para clientes moviles/terceros, usar JWT
**solo** sobre `Authorization: Bearer` con `access token` corto + `refresh token`
con rotacion, y nunca en almacenamiento accesible por JS.

Implementado en `includes/security.php`:

```php
session_name('PT_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,   // true bajo HTTPS
    'httponly' => true,     // inaccesible por JS (anti-XSS)
    'samesite' => 'Lax',    // mitiga CSRF
]);
session_start();
```

**Fijacion de sesion (session fixation):** mitigada con `session.use_strict_mode`
y `session_regenerate_id(true)` en el login y cada 15 min.

**Robo de sesion:** ademas de `HttpOnly`, se calcula una **huella**
(`User-Agent` + `SESSION_SECRET`); si la cookie se usa desde otro dispositivo la
sesion se invalida automaticamente (`includes/security.php`).

**P1 · Poner `Secure` siempre en produccion.** Ya se activa solo si `es_https()`
detecta HTTPS. Cambiar `SESSION_SECRET` por un valor aleatorio:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

#### P0 · RBAC y autorizacion de barrera (regla estricta)

Un usuario comun **solo** puede abrir la barrera si tiene una reserva que cumple
**todas** estas condiciones: es **suya**, esta **pendiente**, esta **pagada** y
`NOW()` cae dentro de la ventana `[inicio - tolerancia, fin]`. La validacion esta
en `contexto_barrera()` y se **revalida dentro de la transaccion** con `FOR UPDATE`
para evitar condiciones de carrera (`api/abrir_barrera.php`):

```php
// includes/funciones.php
WHERE r.usuario_id = :uid
  AND r.estado = 'pendiente'
  AND r.pagada = 1
-- ventana temporal:
if ($ahora >= $inicio->sub($tolerancia) && $ahora <= $fin) { /* permitido */ }
```

```php
// api/abrir_barrera.php (revalidacion atomica)
SELECT id, estado, pagada FROM reservas WHERE id = :id FOR UPDATE;
if (!$actual || $actual['estado'] !== 'pendiente' || (int) $actual['pagada'] !== 1) {
    $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'La reserva ya no esta disponible para ingresar.']);
}
```

Roles: `usuario` y `admin`. El panel y `api/admin_estado.php` exigen rol `admin`
(`requiere_admin()` / chequeo explicito). Para escalar a permisos finos usar una
tabla `roles` + `permisos` + `rol_permiso` y un `puede($usuario,'barrera.abrir')`.

#### P0 · Rate limiting (fuerza bruta y abuso)

Ventana deslizante persistida en `storage/rate/` (sin dependencias):

```php
// login
$limiteIp   = rate_limit_estado('login_ip:'  . $ip, RATE_LOGIN_IP_MAX,  RATE_LOGIN_IP_VENTANA);
$limiteMail = rate_limit_estado('login_fail:' . strtolower($email) . '|' . $ip,
                                RATE_LOGIN_MAIL_MAX, RATE_LOGIN_MAIL_VENTANA);
if (!$limiteIp['allowed'] || !$limiteMail['allowed']) { /* 429 / aviso */ }
rate_limit_registrar($failKey, RATE_LOGIN_MAIL_VENTANA); // solo en fallo
```

- Login: **30** intentos/IP/5 min y **5** fallos por email+IP/15 min (verificado:
  bloquea al 6º intento).
- Registro: **10** altas/IP/10 min.
- Barrera: **20** aperturas/usuario/60 s (`rate_limit_json()` responde `429` +
  `Retry-After`).

**P1 · Ajustar `PROXY_CONFIABLE`** solo si hay un proxy/balanceador propio
(`X-Forwarded-For`). Si hay un WAF/CDN, mover el rate limiting tambien alli
(limite por IP real, no spoofeable).

#### P0 · CSRF, XSS y fijacion de sesion

- **CSRF**: token por sesion (`csrf_token()`) en todo formulario y en las
  acciones de barrera (header `SameSite=Lax` como segunda barrera). Validacion
  con `hash_equals` via `csrf_valido()`.
- **XSS**: toda salida usa `htmlspecialchars()`. La **CSP** (`script-src 'self'`)
  bloquea scripts inline/inyectados. No usar `'unsafe-inline'` en `script-src`.
- **Fijacion**: ya cubierta (item anterior).

**P1 · Politica de contrasenas y 2FA.** Subir el minimo a 10+ caracteres,
bloquear las filtradas (HIBP) y ofrecer 2FA (TOTP) para administradores.
`PASSWORD_DEFAULT` ya usa bcrypt; considerar `PASSWORD_ARGON2ID` si el hosting lo
soporta.

#### P2 · Avanzado

- **Sesion unica por cuenta** (invalidar la anterior al iniciar una nueva) o
  cierre remoto de sesiones.
- **Auditoria**: registrar eventos (`login_ok`, `login_fail`, `barrera_abrir`)
  con IP, UA y timestamp en una tabla `auditoria`.
- **Secretos fuera del codigo**: mover `SESSION_SECRET`, `ESP32_API_KEY` y la
  clave de DB a variables de entorno / `.env` (nunca versionados).

---

### 4. Pilar 2 - Comunicacion y red (navegador, backend e IoT)

#### P0 · Navegador ↔ Backend: TLS y HSTS

El canal **debe** ser HTTPS con TLS 1.2+ (ideal 1.3). La cookie de sesion ya se
marca `Secure` automaticamente bajo HTTPS y la app envia **HSTS**.

Apache (vhost), ejemplo minimo:

```apache
<VirtualHost *:443>
    ServerName parking.midominio.com
    SSLEngine on
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLHonorCipherOrder on
    SSLCertificateFile      C:/xampp/apache/conf/ssl/parking.crt
    SSLCertificateKeyFile   C:/xampp/apache/conf/ssl/parking.key
    # Redirigir todo HTTP -> HTTPS (ver .htaccess raiz, bloque mod_rewrite)
</VirtualHost>
```

Para pruebas locales sin exponer a Internet, usar **VPN** (ver mas abajo) o un
tunel con TLS; no publicar la app directo a Internet sin TLS.

#### P1 · Backend ↔ ESP32: proteger la orden de apertura

Hoy el dispositivo hace **polling saliente por HTTP** con `api_key` en la query:

```
GET /ParkingTech/api/barrera_poll.php?api_key=...&ip=...&estado=...
```

Riesgo: en la LAN la clave viaja en claro y queda en logs. Como el sketch es
**INMUTABLE**, hay tres caminos (de menos a mas robusto):

1. **HMAC con `api_key` (compatible con query)**: sumar `ts` + `sig`
   (`HMAC-SHA256` sobre `id+ts`) y validarlo en `barrera_ack.php`. Requiere
   agregar el calculo en el firmware *o* interponer un **gateway** que firme.
   Ejemplo de verificacion en PHP:
   ```php
   $ts  = (int) ($_GET['ts'] ?? 0);
   $sig = (string) ($_GET['sig'] ?? '');
   $msg = $id . '|' . $ts;
   $esp = hash_hmac('sha256', $msg, ESP32_API_KEY);
   if (abs(time() - $ts) > 30 || !hash_equals($esp, $sig)) {
       json_esp32(['ok' => false, 'error' => 'firma invalida'], 401);
   }
   ```
2. **HTTPS con certificado fijado (pinning)** en el ESP32 + **mTLS** (certificado
   de cliente). Es la mejor opcion si el firmware puede cambiar.
3. **Broker MQTT sobre TLS (puerto 8883)** con `LWT` para el heartbeat. El
   backend publica en `parking/barrera/cmd` (QoS 1) y el ESP32 se suscribe; el
   ack va a `parking/barrera/ack`. Limita el ataque de sniffing y no expone HTTP.

`P2 · Rotacion de claves` del dispositivo (dos claves activas, retire de a una) e
identidad por dispositivo (`device_id` + secreto propio) en vez de una clave
global.

#### P1 · Aislamiento y seguridad de red del controlador

El ESP32 **no debe estar expuesto a Internet**. Estrategias combinables:

- **Segmentacion (VLAN)**: colocar la placa y el backend en una VLAN de IoT con
  reglas que solo permitan backend → dispositivo (o dispositivo → backend:puerto).
- **NAT / sin port-forward**: el dispositivo inicia conexiones salientes (como
  ahora con el polling); nunca abrirle un puerto entrante desde Internet.
- **VPN (WireGuard)** para administracion remota del sitio, sin publicar nada:
  ```
  # servidor (gateway del parking)
  [Interface]
  Address    = 10.13.13.1/24
  ListenPort = 51820
  PrivateKey = <server_priv>

  [Peer]                       # notebook del tecnico
  PublicKey  = <tecnico_pub>
  AllowedIPs = 10.13.13.2/32
  ```
- **Gateway / relay intermedio**: si se necesita acceso remoto, exponer una
  pasarela (reverse proxy mTLS) que reenvie a la red interna, no al dispositivo.
- **Firewall**: denegar por defecto el tráfico IoT → LAN y permitir solo lo
  necesario (backend:puerto, NTP, DNS).

#### P2 · Integridad y disponibilidad

- Firmar/verificar **cada** comando (no solo el ack) y usar **nonce/ts** para
  evitar *replay* de una apertura.
- `fail-safe`: si el comando no se ackea, no reintentar indefinidamente el servo
  (ya existe reencolado con `COMANDO_REENVIO_SEG`).
- Monitoreo de desconexiones (el heartbeat ya existe) con alertas.

---

### 5. Endurecimiento del servidor (checklist)

- **PHP (`php.ini`)**: `expose_php=Off`, `display_errors=Off` (en produccion),
  `session.cookie_httponly=1`, `session.use_strict_mode=1`,
  `session.cookie_samesite=Lax`, `allow_url_fopen=Off` si no se usa.
- **MySQL**: crear un usuario dedicado con privilegios minimos sobre
  `parkingtech2.0` (SELECT/INSERT/UPDATE/DELETE) en vez de `root` sin clave:
  ```sql
  CREATE USER 'parking_app'@'localhost' IDENTIFIED BY '<clave-fuerte>';
  GRANT SELECT, INSERT, UPDATE, DELETE ON `parkingtech2.0`.* TO 'parking_app'@'localhost';
  ```
  (No otorgar `DROP`/`ALTER`/`FILE` en produccion.)
- **Apache**: `Options -Indexes`, `.htaccess` raiz endurecido (ya incluido),
  redireccion HTTPS (bloque comentado, activar en produccion).
- **Backups cifrados** y **rotacion de logs**; no loguear la `api_key` ni datos
  de tarjeta.
- **Actualizaciones**: mantener PHP/Apache/MySQL parcheados.

### 6. Roadmap sugerido

| Prioridad | Tarea |
|-----------|-------|
| P0 | Servir el sitio por HTTPS y activar `Secure`+HSTS (ya automatico con HTTPS) |
| P0 | Cambiar `SESSION_SECRET`, `ESP32_API_KEY` y clave de DB por valores propios/aleatorios |
| P1 | Firmar los comandos IoT (HMAC) o migrar a MQTT/TLS o HTTPS con mTLS |
| P1 | Aislar el ESP32 en VLAN + acceso por WireGuard (sin exposicion a Internet) |
| P1 | WAF/CDN delante del sitio + rate limiting en el borde |
| P2 | 2FA para admins, auditoria de eventos, sesion unica, rotacion de claves IoT |

