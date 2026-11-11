-- =============================================================================
-- Parking Tech - Esquema de base de datos
-- Sistema de estacionamiento inteligente basado EXCLUSIVAMENTE en reservas.
-- =============================================================================
-- Motor: MySQL / MariaDB (XAMPP)
-- Charset: utf8mb4
--
-- Tablas:
--   usuarios               -> cuentas del sistema
--   vehiculos              -> vehiculos asociados a cada usuario
--   espacios               -> plazas fisicas del estacionamiento
--   tarifas                -> precios por hora
--   reservas               -> reservas (estados: pendiente, ingresado, finalizado, cancelada)
--   barrier_comandos       -> cola de comandos fisicos para el ESP32
--   barrier_dispositivos   -> latido / estado del ESP32 (heartbeat)
-- =============================================================================

-- Se elimina y recrea la base completa para garantizar una instalacion LIMPIA,
-- sin tablas residuales de sistemas previos (sesiones de estacionamiento
-- instantaneo, comandos viejos, etc.).
DROP DATABASE IF EXISTS `parkingtech2.0`;
CREATE DATABASE `parkingtech2.0`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `parkingtech2.0`;

-- -----------------------------------------------------------------------------
-- usuarios
-- -----------------------------------------------------------------------------
CREATE TABLE `usuarios` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`     VARCHAR(100) NOT NULL,
  `email`      VARCHAR(150) NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `telefono`   VARCHAR(30)  DEFAULT NULL,
  `rol`        ENUM('usuario','admin') NOT NULL DEFAULT 'usuario',
  `creado_en`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_usuarios_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- vehiculos
-- -----------------------------------------------------------------------------
CREATE TABLE `vehiculos` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NOT NULL,
  `matricula`  VARCHAR(15) NOT NULL,
  `marca`      VARCHAR(50) DEFAULT NULL,
  `modelo`     VARCHAR(50) DEFAULT NULL,
  `color`      VARCHAR(30) DEFAULT NULL,
  `creado_en`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_vehiculos_matricula` (`matricula`),
  KEY `idx_vehiculos_usuario` (`usuario_id`),
  CONSTRAINT `fk_vehiculos_usuario` FOREIGN KEY (`usuario_id`)
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- espacios
-- -----------------------------------------------------------------------------
CREATE TABLE `espacios` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `codigo`    VARCHAR(10) NOT NULL,
  `tipo`      ENUM('auto','moto') NOT NULL DEFAULT 'auto',
  `estado`    ENUM('libre','reservado','ocupado','mantenimiento') NOT NULL DEFAULT 'libre',
  `ubicacion` VARCHAR(100) DEFAULT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_espacios_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- tarifas
-- -----------------------------------------------------------------------------
CREATE TABLE `tarifas` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`      VARCHAR(60) NOT NULL,
  `precio_hora` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `activo`      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- reservas
-- -----------------------------------------------------------------------------
CREATE TABLE `reservas` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `codigo`      VARCHAR(20) NOT NULL,
  `usuario_id`  INT NOT NULL,
  `vehiculo_id` INT NOT NULL,
  `espacio_id`  INT NOT NULL,
  `inicio`      DATETIME NOT NULL,
  `fin`         DATETIME NOT NULL,
  `estado`      ENUM('pendiente','ingresado','finalizado','cancelada') NOT NULL DEFAULT 'pendiente',
  `monto`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pagada`      TINYINT(1) NOT NULL DEFAULT 0,
  `pagado_en`   DATETIME DEFAULT NULL,
  `ingreso_en`  DATETIME DEFAULT NULL,
  `egreso_en`   DATETIME DEFAULT NULL,
  `creado_en`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_reservas_codigo` (`codigo`),
  KEY `idx_reservas_usuario` (`usuario_id`),
  KEY `idx_reservas_estado` (`estado`),
  CONSTRAINT `fk_reservas_usuario`  FOREIGN KEY (`usuario_id`)  REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reservas_vehiculo` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reservas_espacio`  FOREIGN KEY (`espacio_id`)  REFERENCES `espacios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- barrier_comandos  (cola fisica consumida por el ESP32 via polling)
-- -----------------------------------------------------------------------------
CREATE TABLE `barrier_comandos` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `tipo`          ENUM('entrada','salida') NOT NULL,
  `reserva_id`    INT DEFAULT NULL,
  `estado`        ENUM('pendiente','enviado','ejecutado') NOT NULL DEFAULT 'pendiente',
  `creado_en`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_en`    DATETIME DEFAULT NULL,
  `ejecutado_en`  DATETIME DEFAULT NULL,
  KEY `idx_comandos_estado` (`estado`, `id`),
  CONSTRAINT `fk_comandos_reserva` FOREIGN KEY (`reserva_id`)
    REFERENCES `reservas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- barrier_dispositivos  (latido del ESP32 para estado "en linea")
-- -----------------------------------------------------------------------------
CREATE TABLE `barrier_dispositivos` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `identificador` VARCHAR(50) NOT NULL,
  `ip`            VARCHAR(45) DEFAULT NULL,
  `estado`        ENUM('abierta','cerrada','desconocido') NOT NULL DEFAULT 'desconocido',
  `ultimo_latido` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_dispositivo` (`identificador`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DATOS INICIALES
-- =============================================================================
-- Usuarios demo  (admin123 / usuario123)
INSERT INTO `usuarios` (`nombre`, `email`, `password`, `telefono`, `rol`) VALUES
('Administrador', 'admin@parkingtech.local',
 '$2y$10$bxMYJhUyE2Pep8dWv3jRXuGRLG3.ttfvNZYSV.VeHFwXex7YGfWba', '1100000000', 'admin'),
('Juan Perez', 'usuario@parkingtech.local',
 '$2y$10$AEeZ.O/MqzWmZOspDMCf5eynwyB8U3vSZ.ZJj5i1ZaHI9jW0CG9Qi', '1100000001', 'usuario');

INSERT INTO `vehiculos` (`usuario_id`, `matricula`, `marca`, `modelo`, `color`) VALUES
(2, 'AB123CD', 'Toyota', 'Corolla', 'Gris');

INSERT INTO `espacios` (`codigo`, `tipo`, `ubicacion`) VALUES
('A-01','auto','Planta baja - Sector A'),
('A-02','auto','Planta baja - Sector A'),
('A-03','auto','Planta baja - Sector A'),
('A-04','auto','Planta baja - Sector A'),
('A-05','auto','Planta baja - Sector A'),
('A-06','auto','Planta baja - Sector A'),
('B-01','auto','Planta baja - Sector B'),
('B-02','auto','Planta baja - Sector B'),
('B-03','auto','Planta baja - Sector B'),
('B-04','auto','Planta baja - Sector B'),
('B-05','auto','Planta baja - Sector B'),
('B-06','auto','Planta baja - Sector B'),
('C-01','auto','Planta baja - Sector C'),
('C-02','auto','Planta baja - Sector C'),
('C-03','auto','Planta baja - Sector C'),
('C-04','auto','Planta baja - Sector C'),
('M-01','moto','Planta baja - Sector M'),
('M-02','moto','Planta baja - Sector M');

INSERT INTO `tarifas` (`nombre`, `precio_hora`, `activo`) VALUES
('Hora auto', 500.00, 1),
('Hora moto', 300.00, 1);

INSERT INTO `barrier_dispositivos` (`identificador`, `ip`, `estado`, `ultimo_latido`) VALUES
('esp32-barrera', NULL, 'desconocido', NULL);
