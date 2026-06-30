CREATE TABLE IF NOT EXISTS st_usuario_perfiles (
    usuario_id BIGINT UNSIGNED NOT NULL,
    nombre_visible VARCHAR(160) NULL,
    cargo_area VARCHAR(160) NULL,
    color_acento VARCHAR(20) NOT NULL DEFAULT '#7c3aed',
    meta_diaria INT NOT NULL DEFAULT 60,
    avatar_url VARCHAR(500) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL,
    PRIMARY KEY (usuario_id),
    CONSTRAINT fk_st_usuario_perfiles_usuario
        FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id) ON DELETE CASCADE,
    CONSTRAINT ck_st_usuario_perfiles_meta CHECK (meta_diaria BETWEEN 1 AND 999)
);
