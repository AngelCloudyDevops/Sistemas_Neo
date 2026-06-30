/*
  NEO - identidad central, grupos y politicas.
  Ejecutar en GESTION_INF1 para desarrollo. No ejecutar en GESTION_INF produccion
  hasta validar el flujo de gs_neo.
*/
USE GESTION_INF1;
SET NOCOUNT ON;

IF OBJECT_ID('dbo.core_roles', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.core_roles WHERE nombre = N'Super Admin')
BEGIN
    INSERT INTO dbo.core_roles (nombre, descripcion, estado, creado_en)
    VALUES (N'Super Admin', N'Administrador global del ecosistema NEO.', 'activo', SYSDATETIME());
END;

IF OBJECT_ID('dbo.core_roles', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.core_roles WHERE nombre = N'Usuario')
BEGIN
    INSERT INTO dbo.core_roles (nombre, descripcion, estado, creado_en)
    VALUES (N'Usuario', N'Rol base neutro. El acceso real se asigna por grupos y politicas.', 'activo', SYSDATETIME());
END;

IF OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.core_usuarios', 'personal_id') IS NULL
BEGIN
    ALTER TABLE dbo.core_usuarios ADD personal_id BIGINT NULL;
END;

IF OBJECT_ID('dbo.core_personal', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuarios_personal')
BEGIN
    ALTER TABLE dbo.core_usuarios
    ADD CONSTRAINT FK_core_usuarios_personal
        FOREIGN KEY (personal_id) REFERENCES dbo.core_personal(id);
END;

IF OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_core_usuarios_personal_id' AND object_id = OBJECT_ID('dbo.core_usuarios'))
BEGIN
    CREATE INDEX IX_core_usuarios_personal_id ON dbo.core_usuarios(personal_id);
END;

IF OBJECT_ID('dbo.core_grupos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_grupos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_grupos PRIMARY KEY,
        codigo VARCHAR(80) NOT NULL,
        nombre NVARCHAR(120) NOT NULL,
        descripcion NVARCHAR(255) NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_grupos_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_grupos_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_grupos_codigo UNIQUE (codigo),
        CONSTRAINT CK_core_grupos_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF OBJECT_ID('dbo.core_grupo_usuarios', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_grupo_usuarios (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_grupo_usuarios PRIMARY KEY,
        grupo_id BIGINT NOT NULL,
        usuario_id BIGINT NOT NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_grupo_usuarios_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_grupo_usuarios_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_grupo_usuarios UNIQUE (grupo_id, usuario_id),
        CONSTRAINT CK_core_grupo_usuarios_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_politicas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_politicas PRIMARY KEY,
        codigo VARCHAR(120) NOT NULL,
        nombre NVARCHAR(160) NOT NULL,
        sistema_codigo VARCHAR(50) NULL,
        descripcion NVARCHAR(255) NULL,
        tipo VARCHAR(40) NOT NULL CONSTRAINT DF_core_politicas_tipo DEFAULT ('permiso'),
        condicion_json NVARCHAR(MAX) NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_politicas_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_politicas_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_politicas_codigo UNIQUE (codigo),
        CONSTRAINT CK_core_politicas_tipo CHECK (tipo IN ('permiso', 'condicion')),
        CONSTRAINT CK_core_politicas_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF OBJECT_ID('dbo.core_rol_politicas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_rol_politicas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_rol_politicas PRIMARY KEY,
        rol_id BIGINT NOT NULL,
        politica_id BIGINT NOT NULL,
        permitido BIT NOT NULL CONSTRAINT DF_core_rol_politicas_permitido DEFAULT (1),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_rol_politicas_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_rol_politicas UNIQUE (rol_id, politica_id)
    );
END;

IF OBJECT_ID('dbo.core_grupo_politicas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_grupo_politicas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_grupo_politicas PRIMARY KEY,
        grupo_id BIGINT NOT NULL,
        politica_id BIGINT NOT NULL,
        permitido BIT NOT NULL CONSTRAINT DF_core_grupo_politicas_permitido DEFAULT (1),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_grupo_politicas_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_grupo_politicas UNIQUE (grupo_id, politica_id)
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_grupo_usuarios_grupos')
BEGIN
    ALTER TABLE dbo.core_grupo_usuarios
    ADD CONSTRAINT FK_core_grupo_usuarios_grupos FOREIGN KEY (grupo_id) REFERENCES dbo.core_grupos(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_grupo_usuarios_usuarios')
BEGIN
    ALTER TABLE dbo.core_grupo_usuarios
    ADD CONSTRAINT FK_core_grupo_usuarios_usuarios FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_rol_politicas_roles')
BEGIN
    ALTER TABLE dbo.core_rol_politicas
    ADD CONSTRAINT FK_core_rol_politicas_roles FOREIGN KEY (rol_id) REFERENCES dbo.core_roles(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_rol_politicas_politicas')
BEGIN
    ALTER TABLE dbo.core_rol_politicas
    ADD CONSTRAINT FK_core_rol_politicas_politicas FOREIGN KEY (politica_id) REFERENCES dbo.core_politicas(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_grupo_politicas_grupos')
BEGIN
    ALTER TABLE dbo.core_grupo_politicas
    ADD CONSTRAINT FK_core_grupo_politicas_grupos FOREIGN KEY (grupo_id) REFERENCES dbo.core_grupos(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_grupo_politicas_politicas')
BEGIN
    ALTER TABLE dbo.core_grupo_politicas
    ADD CONSTRAINT FK_core_grupo_politicas_politicas FOREIGN KEY (politica_id) REFERENCES dbo.core_politicas(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_core_politicas_sistema' AND object_id = OBJECT_ID('dbo.core_politicas'))
BEGIN
    CREATE INDEX IX_core_politicas_sistema ON dbo.core_politicas(sistema_codigo, estado);
END;

MERGE dbo.core_grupos AS t
USING (SELECT codigo, nombre, descripcion, estado FROM (VALUES
    ('super_admins', N'Super Administradores', N'Administracion global de usuarios, roles, grupos y sistemas.', 'activo'),
    ('tickets_emision', N'Tickets - Emision', N'Usuarios que emiten y reimprimen tickets.', 'activo'),
    ('tickets_turnero', N'Tickets - Turnero', N'Usuarios que llaman y atienden tickets en turnero.', 'activo'),
    ('tickets_consulta', N'Tickets - Consulta', N'Usuarios que consultan tickets, historial y reportes permitidos.', 'activo')
) AS v(codigo, nombre, descripcion, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.estado, SYSDATETIME());

MERGE dbo.core_politicas AS t
USING (SELECT codigo, nombre, sistema_codigo, descripcion, tipo, estado FROM (VALUES
    ('usuarios.ver', N'Ver usuarios', 'gs', N'Permite listar y buscar usuarios.', 'permiso', 'activo'),
    ('usuarios.crear', N'Crear usuarios', 'gs', N'Permite crear cuentas vinculadas a personal.', 'permiso', 'activo'),
    ('usuarios.editar', N'Editar usuarios', 'gs', N'Permite modificar usuarios, roles y grupos.', 'permiso', 'activo'),
    ('usuarios.eliminar', N'Eliminar usuarios', 'gs', N'Permite desactivar o eliminar usuarios.', 'permiso', 'activo'),
    ('roles.admin', N'Administrar roles', 'gs', N'Permite crear y administrar roles.', 'permiso', 'activo'),
    ('grupos.admin', N'Administrar grupos', 'gs', N'Permite crear y administrar grupos.', 'permiso', 'activo'),
    ('politicas.admin', N'Administrar politicas', 'gs', N'Permite crear y administrar politicas.', 'permiso', 'activo'),
    ('tickets.emitir', N'Emitir tickets', 'tickets', N'Permite emitir tickets.', 'permiso', 'activo'),
    ('tickets.reportes.ver', N'Ver reportes de tickets', 'tickets', N'Permite consultar reportes de tickets.', 'permiso', 'activo'),
    ('firmas.ver', N'Ver gestion de firmas', 'firmas', N'Permite entrar al sistema de firmas.', 'permiso', 'activo')
) AS v(codigo, nombre, sistema_codigo, descripcion, tipo, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion,
               tipo = s.tipo, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.estado, SYSDATETIME());

DECLARE @superAdminRol BIGINT = (SELECT TOP 1 id FROM dbo.core_roles WHERE nombre = N'Super Admin');
DECLARE @superAdminsGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'super_admins');

IF @superAdminsGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @superAdminsGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @superAdminRol IS NOT NULL AND @superAdminsGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_usuarios AS t
    USING (
        SELECT id AS usuario_id, @superAdminsGrupo AS grupo_id
        FROM dbo.core_usuarios
        WHERE rol_id = @superAdminRol
          AND estado = 'activo'
    ) AS s
    ON t.usuario_id = s.usuario_id AND t.grupo_id = s.grupo_id
    WHEN MATCHED THEN
        UPDATE SET estado = 'activo', actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (usuario_id, grupo_id, estado, creado_en)
        VALUES (s.usuario_id, s.grupo_id, 'activo', SYSDATETIME());
END;

SELECT name AS tabla_core_identidad
FROM sys.tables
WHERE name IN (
    'core_grupos',
    'core_grupo_usuarios',
    'core_politicas',
    'core_rol_politicas',
    'core_grupo_politicas'
)
ORDER BY name;
