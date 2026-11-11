/*
 * Parking Tech - BARRERA por BOTON WEB (polling) + SERVOS
 * -----------------------------------------------------
 * Tarjeta: ESP32 (o ESP32-CAM). Conecta 2 servos: ENTRADA y SALIDA.
 *
 * COMO FUNCIONA
 * -------------
 * La web NO conoce la IP de este ESP32. En cambio, el ESP32 consulta cada
 * segundo al servidor (api/barrera_poll.php):
 *   - Si hay una orden pendiente, la recibe, acciona el servo correspondiente
 *     y confirma con api/barrera_ack.php.
 *   - Ademas envia un "latido" con su IP para que la web muestre "en linea".
 *
 * La barrera queda levantada TIEMPO_ABIERTO_MS y se baja sola (NO bloqueante,
 * con millis(), asi el polling sigue funcionando mientras esta abierta).
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ESP32Servo.h>

// ---------------------------------------------------------------------------
// CONFIGURACION
// ---------------------------------------------------------------------------
const char* ssid     = "ESCUALO";
const char* password = "escualo123";

// Servidor con la API (sin barra final).
const char* SERVIDOR = "http://10.214.73.110/ParkingTech/api";
const char* API_KEY  = "parkingtech-porteria-2026";

// Pines de control para los Servos de cada barrera.
const int PIN_SERVO_ENTRADA = 18;
const int PIN_SERVO_SALIDA  = 19;

// Ángulos de posición de la barrera (puedes ajustarlos si necesitas invertir o cambiar la apertura).
const int ANGULO_REPOSO  = 0;   // Barrera cerrada / abajo
const int ANGULO_ACTIVO  = 90;  // Barrera abierta / arriba

// Tiempo que la barrera queda levantada.
const unsigned long TIEMPO_ABIERTO_MS = 5000;

// Cada cuanto se consulta el servidor.
const unsigned long POLL_MS = 1000;

// ---------------------------------------------------------------------------
// Instancias de Servos y Control no bloqueante
// ---------------------------------------------------------------------------
Servo servoEntrada;
Servo servoSalida;

struct Barrera {
  Servo *servo;
  bool abierta;
  unsigned long horaApertura;
};

Barrera entrada = { &servoEntrada, false, 0 };
Barrera salida  = { &servoSalida,  false, 0 };

void abrirBarrera(Barrera &b) {
  b.servo->write(ANGULO_ACTIVO);
  b.abierta = true;
  b.horaApertura = millis();
  Serial.println("Barrera ABIERTA.");
}

void actualizarBarrera(Barrera &b) {
  if (b.abierta && (millis() - b.horaApertura >= TIEMPO_ABIERTO_MS)) {
    b.servo->write(ANGULO_REPOSO);
    b.abierta = false;
    Serial.println("Barrera cerrada.");
  }
}

const char* estadoActual() {
  if (entrada.abierta || salida.abierta) return "abierta";
  return "cerrada";
}

// ---------------------------------------------------------------------------
// Wi-Fi
// ---------------------------------------------------------------------------
void conectarWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);
  Serial.print("Conectando a Wi-Fi");
  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < 15000) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();
  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Wi-Fi OK. IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("No se pudo conectar (se reintentara).");
  }
}

// ---------------------------------------------------------------------------
// Utilidades de parseo minimo de JSON (evita dependencias extra)
// ---------------------------------------------------------------------------
String extraerTexto(const String &json, const String &clave) {
  int p = json.indexOf("\"" + clave + "\"");
  if (p < 0) return "";
  p = json.indexOf(':', p);
  if (p < 0) return "";
  int q1 = json.indexOf('"', p + 1);
  if (q1 < 0) return "";
  int q2 = json.indexOf('"', q1 + 1);
  if (q2 < 0) return "";
  return json.substring(q1 + 1, q2);
}

long extraerNumero(const String &json, const String &clave) {
  int p = json.indexOf("\"" + clave + "\"");
  if (p < 0) return -1;
  p = json.indexOf(':', p);
  if (p < 0) return -1;
  return json.substring(p + 1).toInt();
}

// ---------------------------------------------------------------------------
// Polling al servidor
// ---------------------------------------------------------------------------
void consultarServidor() {
  Serial.println("Consultando al servidor...");
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(SERVIDOR) + "/barrera_poll.php?api_key=" + API_KEY
               + "&ip=" + WiFi.localIP().toString()
               + "&estado=" + estadoActual();

  http.begin(url);
  http.setTimeout(4000);
  int code = http.GET();
  if (code != 200) {
    Serial.print("Error HTTP en polling, código: ");
    Serial.println(code);
    http.end();
    return;
  }

  String body = http.getString();
  http.end();

  Serial.println("Respuesta recibida del servidor.");

  if (body.indexOf("\"hay\":true") < 0) {
    return;  // no hay comando pendiente
  }

  long idComando = extraerNumero(body, "id_comando");
  String tipo = extraerTexto(body, "tipo");

  if (tipo == "entrada") {
    abrirBarrera(entrada);
  } else if (tipo == "salida") {
    abrirBarrera(salida);
  } else {
    return;
  }

  // Confirmar la ejecución con el parámetro &id=
  if (idComando > 0) {
    HTTPClient ack;
    String urlAck = String(SERVIDOR) + "/barrera_ack.php?api_key=" + API_KEY + "&id=" + String(idComando);
    ack.begin(urlAck);
    ack.setTimeout(4000);
    ack.GET();
    ack.end();
  }
}

// ---------------------------------------------------------------------------
// setup / loop
// ---------------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println("\nBarrera Parking Tech (Servos) iniciando...");

  // Inicializar pines de los servos y llevarlos a posición cerrada
  servoEntrada.attach(PIN_SERVO_ENTRADA);
  servoSalida.attach(PIN_SERVO_SALIDA);
  
  servoEntrada.write(ANGULO_REPOSO);
  servoSalida.write(ANGULO_REPOSO);

  conectarWiFi();
}

void loop() {
  conectarWiFi();

  static unsigned long ultimoPoll = 0;
  if (millis() - ultimoPoll >= POLL_MS) {
    ultimoPoll = millis();
    consultarServidor();
  }

  actualizarBarrera(entrada);
  actualizarBarrera(salida);
}
