/*
 * Parking Tech - Test de UN solo servo (diagnostico pin/servo)
 * ------------------------------------------------------------
 * Mueve un unico servo conectado al pin indicado en PIN.
 *
 * PROCEDIMIENTO:
 *   1) Conecta el servo de SALIDA y deja PIN = 19. Flashea.
 *      - Si se mueve  -> el servo y el pin 19 estan OK.
 *      - Si NO se mueve -> segui al paso 2.
 *   2) Cambia PIN = 18 (pin que sabemos que funciona) y flashea con el MISMO servo.
 *      - Si ahora SI se mueve -> el problema es el GPIO 19 (pin/cable).
 *      - Si sigue SIN moverse -> el problema es el SERVO de salida (o su VCC/GND).
 *
 * Monitor Serie a 115200.
 */

#include <ESP32Servo.h>

const int PIN = 19;   // probar 19 y luego 18

Servo servo;

void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.print("Test de servo en GPIO ");
  Serial.println(PIN);
  servo.attach(PIN);
  servo.write(0);
  delay(1000);
}

void loop() {
  Serial.println("-> 90 (abrir)");
  servo.write(90);
  delay(1500);
  Serial.println("-> 0 (cerrar)");
  servo.write(0);
  delay(1500);
}
