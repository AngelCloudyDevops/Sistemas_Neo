USE sibd_firmas;

CREATE TABLE IF NOT EXISTS core_especialidades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    sigh_especialidad_id INT UNSIGNED NULL,
    estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
    orden INT UNSIGNED NOT NULL DEFAULT 100,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_core_especialidades_nombre (nombre),
    UNIQUE KEY uk_core_especialidades_sigh_id (sigh_especialidad_id),
    KEY idx_core_especialidades_estado_orden (estado, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_configuracion (
    clave VARCHAR(80) PRIMARY KEY,
    valor TEXT NULL,
    actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_cupo_reservas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    core_especialidad_id BIGINT UNSIGNED NOT NULL,
    sigh_especialidad_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    estado ENUM('reservada', 'liberada') NOT NULL DEFAULT 'reservada',
    reservado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    liberado_en TIMESTAMP NULL DEFAULT NULL,
    liberado_motivo VARCHAR(40) NULL,
    UNIQUE KEY uk_st_cupo_reservas_ticket (ticket_id),
    KEY idx_st_cupo_reservas_activa (fecha, sigh_especialidad_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_usuario_perfiles (
    usuario_id BIGINT UNSIGNED NOT NULL,
    nombre_visible VARCHAR(160) NULL,
    cargo_area VARCHAR(160) NULL,
    color_acento VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
    meta_diaria INT NOT NULL DEFAULT 60,
    avatar_url VARCHAR(500) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id),
    CONSTRAINT fk_st_usuario_perfiles_usuario
        FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT ck_st_usuario_perfiles_meta CHECK (meta_diaria BETWEEN 1 AND 999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_rangos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    tipo_ticket ENUM('Preferencial', 'Normal', 'Procedimiento') NOT NULL,
    desde INT UNSIGNED NOT NULL,
    hasta INT UNSIGNED NOT NULL,
    ultimo INT UNSIGNED NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_por BIGINT UNSIGNED NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_st_rangos_fecha_tipo (fecha, tipo_ticket),
    KEY idx_st_rangos_fecha (fecha),
    CONSTRAINT fk_st_rangos_creado_por
        FOREIGN KEY (creado_por) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT chk_st_rangos_limites CHECK (desde > 0 AND hasta >= desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    tipo_ticket ENUM('Preferencial', 'Normal', 'Procedimiento') NOT NULL,
    prioridad_atencion ENUM('Preferencial', 'Normal') NULL,
    numero INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    tipo_documento ENUM('DNI', 'CE', 'PASAPORTE') NOT NULL,
    numero_documento VARCHAR(20) NOT NULL,
    especialidad_id BIGINT UNSIGNED NOT NULL,
    estado ENUM('emitido', 'anulado', 'en_cola', 'llamando', 'atendiendo', 'atendido', 'ausente') NOT NULL DEFAULT 'emitido',
    estado_impresion ENUM('pendiente', 'impreso', 'cancelado') NOT NULL DEFAULT 'pendiente',
    cola_estado ENUM('sin_cola', 'en_cola', 'llamando', 'atendiendo', 'atendido', 'ausente') NOT NULL DEFAULT 'sin_cola',
    reimpresiones INT UNSIGNED NOT NULL DEFAULT 0,
    creado_por BIGINT UNSIGNED NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    anulado_en DATETIME NULL,
    anulado_por BIGINT UNSIGNED NULL,
    motivo_anulacion VARCHAR(255) NULL,
    UNIQUE KEY uk_st_tickets_uuid (uuid),
    UNIQUE KEY uk_st_tickets_fecha_tipo_numero (fecha, tipo_ticket, numero),
    KEY idx_st_tickets_fecha (fecha),
    KEY idx_st_tickets_codigo (codigo),
    KEY idx_st_tickets_documento (tipo_documento, numero_documento),
    KEY idx_st_tickets_especialidad (especialidad_id),
    KEY idx_st_tickets_estado (estado),
    KEY idx_st_tickets_estado_impresion (estado_impresion),
    CONSTRAINT fk_st_tickets_especialidad
        FOREIGN KEY (especialidad_id) REFERENCES core_especialidades(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_st_tickets_creado_por
        FOREIGN KEY (creado_por) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_st_tickets_anulado_por
        FOREIGN KEY (anulado_por) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_lotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    fecha DATE NOT NULL,
    tipo_ticket ENUM('Preferencial', 'Normal', 'Procedimiento') NOT NULL,
    desde INT UNSIGNED NOT NULL,
    hasta INT UNSIGNED NOT NULL,
    cantidad INT UNSIGNED NOT NULL,
    estado ENUM('pendiente', 'en_impresion', 'impreso', 'interrumpido', 'cancelado') NOT NULL DEFAULT 'pendiente',
    ultimo_enviado INT UNSIGNED NULL,
    retomar_desde INT UNSIGNED NULL,
    creado_por BIGINT UNSIGNED NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    impreso_en DATETIME NULL,
    UNIQUE KEY uk_st_lotes_uuid (uuid),
    KEY idx_st_lotes_fecha_tipo (fecha, tipo_ticket),
    CONSTRAINT fk_st_lotes_creado_por
        FOREIGN KEY (creado_por) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_impresoras (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    modo_impresion ENUM('navegador', 'escpos', 'zpl', 'raw') NOT NULL DEFAULT 'navegador',
    ancho_mm INT UNSIGNED NOT NULL DEFAULT 80,
    alto_mm INT UNSIGNED NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    config_json JSON NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_st_impresoras_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS st_auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NULL,
    accion VARCHAR(100) NOT NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT UNSIGNED NULL,
    detalle TEXT NULL,
    ip VARCHAR(45) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_st_auditoria_usuario (usuario_id),
    KEY idx_st_auditoria_accion (accion),
    KEY idx_st_auditoria_entidad (entidad, entidad_id),
    KEY idx_st_auditoria_creado_en (creado_en),
    CONSTRAINT fk_st_auditoria_usuario
        FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
