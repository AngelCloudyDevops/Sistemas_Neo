/*
  INSTALACION SOPORTE NEO
  Ejecutar en Navicat seleccionando primero la base GESTION_INF.
  Orden incluido: esquema operativo sn_* + registro en Portal NEO + grupos/permisos.
*/
/*
  Sistema de Gestion de Soporte NEO - esquema operativo.
  Prefijo sn_* para no mezclar con st_* del sistema de tickets/turnero.
*/
USE GESTION_INF;
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

/*
  No se crean llaves foraneas hacia dbo.core_usuarios porque en algunas bases de
  produccion core_usuarios.id no esta declarado como PK/UNIQUE. Soporte NEO guarda
  los IDs para trazabilidad, pero evita bloquear la instalacion por esa diferencia.
*/

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_tickets_categoria')
    ALTER TABLE dbo.sn_tickets ADD CONSTRAINT FK_sn_tickets_categoria FOREIGN KEY (categoria_id) REFERENCES dbo.sn_categorias(id);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_tickets_tecnico')
    ALTER TABLE dbo.sn_tickets ADD CONSTRAINT FK_sn_tickets_tecnico FOREIGN KEY (tecnico_id) REFERENCES dbo.sn_tecnicos(id);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_sn_eventos_ticket')
    ALTER TABLE dbo.sn_ticket_eventos ADD CONSTRAINT FK_sn_eventos_ticket FOREIGN KEY (ticket_id) REFERENCES dbo.sn_tickets(id);

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_sn_tickets_estado' AND object_id = OBJECT_ID('dbo.sn_tickets'))
    EXEC(N'CREATE INDEX IX_sn_tickets_estado ON dbo.sn_tickets(estado, prioridad, registrado_en DESC)');

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_sn_tickets_busqueda' AND object_id = OBJECT_ID('dbo.sn_tickets'))
    EXEC(N'CREATE INDEX IX_sn_tickets_busqueda ON dbo.sn_tickets(codigo, area, equipo)');

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_sn_ticket_eventos_ticket' AND object_id = OBJECT_ID('dbo.sn_ticket_eventos'))
    EXEC(N'CREATE INDEX IX_sn_ticket_eventos_ticket ON dbo.sn_ticket_eventos(ticket_id, creado_en DESC)');

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



/* ===== SEED / PORTAL / PERMISOS ===== */

/*
  Sistema de Gestion de Soporte NEO.
  Ejecutar en GESTION_INF despues del esquema IAM/NEO.
*/
USE GESTION_INF;
SET NOCOUNT ON;

IF OBJECT_ID('dbo.neo_sistemas', 'U') IS NULL
    THROW 51001, 'Falta dbo.neo_sistemas. Ejecuta neo_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_grupos', 'U') IS NULL
    THROW 51002, 'Falta dbo.core_grupos. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NULL
    THROW 51003, 'Falta dbo.core_politicas. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_grupo_politicas', 'U') IS NULL
    THROW 51004, 'Falta dbo.core_grupo_politicas. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF COL_LENGTH('dbo.core_politicas', 'efecto') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD efecto VARCHAR(10) NOT NULL CONSTRAINT DF_core_politicas_efecto_soporte DEFAULT ('Allow');
END;

IF COL_LENGTH('dbo.core_politicas', 'accion') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD accion VARCHAR(120) NOT NULL CONSTRAINT DF_core_politicas_accion_soporte DEFAULT ('*');
END;

IF COL_LENGTH('dbo.core_politicas', 'recurso') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD recurso VARCHAR(160) NOT NULL CONSTRAINT DF_core_politicas_recurso_soporte DEFAULT ('*');
END;

MERGE dbo.neo_sistemas AS t
USING (SELECT
    'soporte' AS codigo,
    N'Gestion de Soporte NEO' AS nombre,
    N'Mesa de ayuda, asignacion, atencion y cierre de tickets de soporte TI.' AS descripcion,
    N'/soporte_neo/public' AS url_base,
    N'headphones' AS icono,
    'activo' AS estado,
    12 AS orden
) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, url_base = s.url_base,
               icono = s.icono, estado = s.estado, orden = s.orden, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, url_base, icono, estado, orden, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.url_base, s.icono, s.estado, s.orden, SYSDATETIME());

MERGE dbo.core_grupos AS t
USING (SELECT codigo, nombre, descripcion, estado FROM (VALUES
    ('soporte_admin', N'Soporte - Administradores', N'Administra todo el sistema de soporte NEO.', 'activo'),
    ('soporte_tecnico', N'Soporte - Tecnicos', N'Atiende, asigna y resuelve tickets de soporte.', 'activo'),
    ('soporte_consulta', N'Soporte - Consulta', N'Consulta tickets y reportes de soporte.', 'activo')
) AS v(codigo, nombre, descripcion, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.estado, SYSDATETIME());

MERGE dbo.core_politicas AS t
USING (SELECT codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado FROM (VALUES
    ('sistema.soporte.entrar', N'Entrar a Gestion de Soporte', 'soporte', N'Permite ver Gestion de Soporte NEO en el portal.', 'permiso', 'Allow', 'sistema.entrar', 'sistema:soporte', 'activo'),
    ('sistema.soporte.admin', N'Administrar Gestion de Soporte', 'soporte', N'Permite administrar Gestion de Soporte NEO.', 'permiso', 'Allow', 'sistema.admin', 'sistema:soporte', 'activo'),
    ('soporte.tickets.crear', N'Crear tickets de soporte', 'soporte', N'Permite registrar tickets de soporte.', 'permiso', 'Allow', 'soporte.tickets.crear', 'sistema:soporte', 'activo'),
    ('soporte.tickets.asignar', N'Asignar tickets de soporte', 'soporte', N'Permite asignar tecnicos a tickets.', 'permiso', 'Allow', 'soporte.tickets.asignar', 'sistema:soporte', 'activo'),
    ('soporte.tickets.resolver', N'Resolver tickets de soporte', 'soporte', N'Permite cerrar tickets de soporte.', 'permiso', 'Allow', 'soporte.tickets.resolver', 'sistema:soporte', 'activo'),
    ('soporte.reportes.ver', N'Ver reportes de soporte', 'soporte', N'Permite consultar reportes de soporte.', 'permiso', 'Allow', 'soporte.reportes.ver', 'sistema:soporte', 'activo')
) AS v(codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion,
               tipo = s.tipo, efecto = s.efecto, accion = s.accion, recurso = s.recurso,
               estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.efecto, s.accion, s.recurso, s.estado, SYSDATETIME());

DECLARE @soporteAdmin BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'soporte_admin');
DECLARE @soporteTecnico BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'soporte_tecnico');
DECLARE @soporteConsulta BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'soporte_consulta');

MERGE dbo.core_grupo_politicas AS t
USING (
    SELECT @soporteAdmin AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.soporte.entrar',
        'sistema.soporte.admin',
        'soporte.tickets.crear',
        'soporte.tickets.asignar',
        'soporte.tickets.resolver',
        'soporte.reportes.ver'
    )
    UNION ALL
    SELECT @soporteTecnico, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN ('sistema.soporte.entrar', 'soporte.tickets.crear', 'soporte.tickets.asignar', 'soporte.tickets.resolver')
    UNION ALL
    SELECT @soporteConsulta, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN ('sistema.soporte.entrar', 'soporte.reportes.ver')
) AS s
ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
WHEN MATCHED THEN
    UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (grupo_id, politica_id, permitido, creado_en)
    VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

SELECT s.codigo, s.nombre, s.url_base, s.orden
FROM dbo.neo_sistemas s
WHERE s.codigo = 'soporte';



