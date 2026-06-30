/*
  Sistema de Gestion de Soporte NEO - esquema operativo.
  Prefijo sn_* para no mezclar con st_* del sistema de tickets/turnero.
*/
USE GESTION_INF1;
SET NOCOUNT ON;

IF OBJECT_ID('dbo.sn_categorias', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.sn_categorias (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_sn_categorias PRIMARY KEY,
        nombre NVARCHAR(120) NOT NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_sn_categorias_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_sn_categorias_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_sn_categorias_nombre UNIQUE (nombre),
        CONSTRAINT CK_sn_categorias_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF OBJECT_ID('dbo.sn_tecnicos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.sn_tecnicos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_sn_tecnicos PRIMARY KEY,
        usuario_id BIGINT NULL,
        nombre_visible NVARCHAR(160) NOT NULL,
        especialidad NVARCHAR(120) NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_sn_tecnicos_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_sn_tecnicos_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT CK_sn_tecnicos_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF OBJECT_ID('dbo.sn_tickets', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.sn_tickets (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_sn_tickets PRIMARY KEY,
        codigo VARCHAR(20) NOT NULL,
        usuario_reporta NVARCHAR(180) NOT NULL,
        area NVARCHAR(160) NOT NULL,
        equipo NVARCHAR(120) NULL,
        problema NVARCHAR(600) NOT NULL,
        categoria_id BIGINT NULL,
        prioridad VARCHAR(20) NOT NULL CONSTRAINT DF_sn_tickets_prioridad DEFAULT ('Media'),
        estado VARCHAR(30) NOT NULL CONSTRAINT DF_sn_tickets_estado DEFAULT ('Nuevo'),
        tecnico_id BIGINT NULL,
        registrado_por BIGINT NULL,
        registrado_en DATETIME2(0) NOT NULL CONSTRAINT DF_sn_tickets_registrado DEFAULT (SYSDATETIME()),
        asignado_en DATETIME2(0) NULL,
        resuelto_en DATETIME2(0) NULL,
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_sn_tickets_codigo UNIQUE (codigo),
        CONSTRAINT CK_sn_tickets_prioridad CHECK (prioridad IN ('Urgente', 'Alta', 'Media', 'Baja')),
        CONSTRAINT CK_sn_tickets_estado CHECK (estado IN ('Nuevo', 'Asignado', 'En Atencion', 'Resuelto', 'Cerrado', 'Anulado'))
    );
END;

IF OBJECT_ID('dbo.sn_ticket_eventos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.sn_ticket_eventos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_sn_ticket_eventos PRIMARY KEY,
        ticket_id BIGINT NOT NULL,
        tipo VARCHAR(40) NOT NULL,
        detalle NVARCHAR(800) NULL,
        creado_por BIGINT NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_sn_ticket_eventos_creado DEFAULT (SYSDATETIME()),
        CONSTRAINT CK_sn_ticket_eventos_tipo CHECK (tipo IN ('creado', 'asignado', 'comentario', 'estado', 'resuelto', 'anulado'))
    );
END;

IF OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_tecnicos_usuario')
        ALTER TABLE dbo.sn_tecnicos ADD CONSTRAINT FK_sn_tecnicos_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id);

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_tickets_registrado_por')
        ALTER TABLE dbo.sn_tickets ADD CONSTRAINT FK_sn_tickets_registrado_por FOREIGN KEY (registrado_por) REFERENCES dbo.core_usuarios(id);

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_ticket_eventos_creado_por')
        ALTER TABLE dbo.sn_ticket_eventos ADD CONSTRAINT FK_sn_ticket_eventos_creado_por FOREIGN KEY (creado_por) REFERENCES dbo.core_usuarios(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_tickets_categoria')
    ALTER TABLE dbo.sn_tickets ADD CONSTRAINT FK_sn_tickets_categoria FOREIGN KEY (categoria_id) REFERENCES dbo.sn_categorias(id);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_tickets_tecnico')
    ALTER TABLE dbo.sn_tickets ADD CONSTRAINT FK_sn_tickets_tecnico FOREIGN KEY (tecnico_id) REFERENCES dbo.sn_tecnicos(id);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_eventos_ticket')
    ALTER TABLE dbo.sn_ticket_eventos ADD CONSTRAINT FK_sn_eventos_ticket FOREIGN KEY (ticket_id) REFERENCES dbo.sn_tickets(id);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_sn_tickets_estado' AND object_id = OBJECT_ID('dbo.sn_tickets'))
    CREATE INDEX IX_sn_tickets_estado ON dbo.sn_tickets(estado, prioridad, registrado_en DESC);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_sn_tickets_busqueda' AND object_id = OBJECT_ID('dbo.sn_tickets'))
    CREATE INDEX IX_sn_tickets_busqueda ON dbo.sn_tickets(codigo, area, equipo);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_sn_ticket_eventos_ticket' AND object_id = OBJECT_ID('dbo.sn_ticket_eventos'))
    CREATE INDEX IX_sn_ticket_eventos_ticket ON dbo.sn_ticket_eventos(ticket_id, creado_en DESC);

MERGE dbo.sn_categorias AS t
USING (SELECT nombre FROM (VALUES
    (N'Hardware'),
    (N'Software'),
    (N'Red'),
    (N'Impresoras'),
    (N'Accesos'),
    (N'Otros')
) AS v(nombre)) AS s
ON t.nombre = s.nombre
WHEN NOT MATCHED THEN
    INSERT (nombre, estado, creado_en) VALUES (s.nombre, 'activo', SYSDATETIME());

SELECT name AS tabla_soporte
FROM sys.tables
WHERE name LIKE 'sn[_]%'
ORDER BY name;
