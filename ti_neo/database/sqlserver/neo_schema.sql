/*
  Portal NEO - esquema inicial.
  Ejecutar primero en GESTION_INF1. No ejecutar en GESTION_INF hasta validar.
*/
USE GESTION_INF1;
IF OBJECT_ID('dbo.neo_tokens_revocados', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.neo_tokens_revocados (
        jti VARCHAR(64) NOT NULL CONSTRAINT PK_neo_tokens_revocados PRIMARY KEY,
        usuario_id BIGINT NOT NULL,
        revocado_en DATETIME2(0) NOT NULL CONSTRAINT DF_neo_tokens_revocados_revocado DEFAULT (SYSDATETIME()),
        expira_en DATETIME2(0) NOT NULL
    );
END;
IF OBJECT_ID('dbo.neo_sistemas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.neo_sistemas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_neo_sistemas PRIMARY KEY,
        codigo VARCHAR(50) NOT NULL,
        nombre NVARCHAR(120) NOT NULL,
        descripcion NVARCHAR(255) NULL,
        url_base NVARCHAR(255) NOT NULL,
        icono NVARCHAR(80) NULL,
        clase_visual VARCHAR(40) NOT NULL CONSTRAINT DF_neo_sistemas_clase_visual DEFAULT ('core'),
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_neo_sistemas_estado DEFAULT ('activo'),
        orden INT NOT NULL CONSTRAINT DF_neo_sistemas_orden DEFAULT (100),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_neo_sistemas_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_neo_sistemas_codigo UNIQUE (codigo),
        CONSTRAINT CK_neo_sistemas_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;
IF COL_LENGTH('dbo.neo_sistemas', 'clase_visual') IS NULL
BEGIN
    ALTER TABLE dbo.neo_sistemas
    ADD clase_visual VARCHAR(40) NOT NULL CONSTRAINT DF_neo_sistemas_clase_visual DEFAULT ('core');
END;
IF OBJECT_ID('dbo.neo_permisos_sistema', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.neo_permisos_sistema (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_neo_permisos_sistema PRIMARY KEY,
        sistema_id BIGINT NOT NULL,
        rol_id BIGINT NOT NULL,
        puede_ver BIT NOT NULL CONSTRAINT DF_neo_permisos_puede_ver DEFAULT (1),
        puede_admin BIT NOT NULL CONSTRAINT DF_neo_permisos_puede_admin DEFAULT (0),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_neo_permisos_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_neo_permisos_sistema UNIQUE (sistema_id, rol_id)
    );
END;
IF OBJECT_ID('dbo.neo_logs_acceso', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.neo_logs_acceso (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_neo_logs_acceso PRIMARY KEY,
        usuario_id BIGINT NULL,
        sistema_id BIGINT NULL,
        accion VARCHAR(60) NOT NULL,
        ip VARCHAR(45) NULL,
        user_agent NVARCHAR(255) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_neo_logs_acceso_creado DEFAULT (SYSDATETIME())
    );
END;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_neo_sistemas_estado_orden' AND object_id = OBJECT_ID('dbo.neo_sistemas'))
BEGIN
    CREATE INDEX IX_neo_sistemas_estado_orden ON dbo.neo_sistemas(estado, orden);
END;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_neo_permisos_rol' AND object_id = OBJECT_ID('dbo.neo_permisos_sistema'))
BEGIN
    CREATE INDEX IX_neo_permisos_rol ON dbo.neo_permisos_sistema(rol_id);
END;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_neo_logs_usuario_fecha' AND object_id = OBJECT_ID('dbo.neo_logs_acceso'))
BEGIN
    CREATE INDEX IX_neo_logs_usuario_fecha ON dbo.neo_logs_acceso(usuario_id, creado_en DESC);
END;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_neo_tokens_usuario_expira' AND object_id = OBJECT_ID('dbo.neo_tokens_revocados'))
BEGIN
    CREATE INDEX IX_neo_tokens_usuario_expira ON dbo.neo_tokens_revocados(usuario_id, expira_en);
END;
IF OBJECT_ID('dbo.core_roles', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_neo_permisos_roles')
BEGIN
    ALTER TABLE dbo.neo_permisos_sistema
    ADD CONSTRAINT FK_neo_permisos_roles FOREIGN KEY (rol_id) REFERENCES dbo.core_roles(id);
END;
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_neo_permisos_sistemas')
BEGIN
    ALTER TABLE dbo.neo_permisos_sistema
    ADD CONSTRAINT FK_neo_permisos_sistemas FOREIGN KEY (sistema_id) REFERENCES dbo.neo_sistemas(id);
END;
IF OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_neo_tokens_usuarios')
BEGIN
    ALTER TABLE dbo.neo_tokens_revocados
    ADD CONSTRAINT FK_neo_tokens_usuarios FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id);
END;
IF OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_neo_logs_usuarios')
BEGIN
    ALTER TABLE dbo.neo_logs_acceso
    ADD CONSTRAINT FK_neo_logs_usuarios FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id);
END;
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_neo_logs_sistemas')
BEGIN
    ALTER TABLE dbo.neo_logs_acceso
    ADD CONSTRAINT FK_neo_logs_sistemas FOREIGN KEY (sistema_id) REFERENCES dbo.neo_sistemas(id);
END;
SELECT name AS tabla_neo_creada
FROM sys.tables
WHERE name LIKE 'neo[_]%'
ORDER BY name;

