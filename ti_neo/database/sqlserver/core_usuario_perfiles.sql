IF OBJECT_ID('dbo.core_usuario_perfiles', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_usuario_perfiles (
        usuario_id BIGINT NOT NULL CONSTRAINT PK_core_usuario_perfiles PRIMARY KEY,
        nombre_visible NVARCHAR(160) NULL,
        cargo_area NVARCHAR(160) NULL,
        color_principal VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_perfiles_color DEFAULT ('#7c3aed'),
        meta_diaria_tickets INT NOT NULL CONSTRAINT DF_core_usuario_perfiles_meta DEFAULT (60),
        avatar_url NVARCHAR(500) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_perfiles_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT FK_core_usuario_perfiles_usuario
            FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id) ON DELETE CASCADE,
        CONSTRAINT CK_core_usuario_perfiles_meta CHECK (meta_diaria_tickets BETWEEN 1 AND 999)
    );
END;
