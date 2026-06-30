CREATE TABLE IF NOT EXISTS core_usuario_perfiles (
    usuario_id BIGINT UNSIGNED NOT NULL,
    nombre_visible VARCHAR(160) NULL,
    cargo_area VARCHAR(160) NULL,
    color_principal VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
    meta_diaria_tickets INT NOT NULL DEFAULT 60,
    avatar_url VARCHAR(500) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id),
    CONSTRAINT fk_core_usuario_perfiles_usuario
        FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT ck_core_usuario_perfiles_meta CHECK (meta_diaria_tickets BETWEEN 1 AND 999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
