/*
 * Parking Tech - Diagnostico de servos (NO es el sketch de produccion)
 * -------------------------------------------------------------------
 * Mueve alternadamente el servo de ENTRADA (GPIO 18) y el de SALIDA
 * (GPIO 19) para verificar cableado, pin y alimentacion.
 *
 * Uso: flashear en el mismo ESP32. Abrir el Monitor Serie a 115200.
 *   - Si un servo NO se mueve pero el otro SI, el problema es ese servo
 *     (cable de senal, GND comun o alimentacion).
 *   - Si ninguno se mueve, revisar alimentacion / GND comun.
 *   - Si ambos se mueven, el hardware esta OK (revisar montaje/mecanica).
 *
 * Recorda: volver a flashear ParkingTech_Barrera.ino despues del test.
 */

#include <ESP32Servo.h>

const int PIN_ENTRADA = 18;
const int PIN_SALIDA  = 19;

Servo servoEntrada;
Servo servoSalida;

void mover(const char* nombre, Servo &s) {
  Serial.print(nombre); Serial.println(" -> 90 grados (abrir)");
  s.write(90);
  delay(2000);
  Serial.print(nombre); Serial.println(" -> 0 grados (cerrar)");
  s.write(0);
  delay(2000);
}

void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.println("\n=== Test de servos Parking Tech ===");

  servoEntrada.attach(PIN_ENTRADA);
  servoSalida.attach(PIN_SALIDA);
  servoEntrada.write(0);
  servoSalida.write(0);
  delay(500);
  Serial.println("Ambos servos en reposo (0 grados).");
}

void loop() {
  mover("ENTRADA (GPIO18)", servoEntrada);
  mover("SALIDA  (GPIO19)", servoSalida);
}
