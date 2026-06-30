/*
  NEO - modelo de permisos tipo IAM.
  Ejecutar en GESTION_INF1 para desarrollo. No ejecutar en GESTION_INF produccion
  hasta validar el flujo completo.

  Regla:
  1. Deny explicito gana.
  2. Allow explicito permite.
  3. Sin Allow queda denegado por defecto.
*/
USE GESTION_INF1;
SET NOCOUNT ON;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.core_politicas', 'efecto') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD efecto VARCHAR(10) NOT NULL CONSTRAINT DF_core_politicas_efecto DEFAULT ('Allow');
END;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.core_politicas', 'accion') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD accion VARCHAR(120) NOT NULL CONSTRAINT DF_core_politicas_accion DEFAULT ('*');
END;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.core_politicas', 'recurso') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD recurso VARCHAR(160) NOT NULL CONSTRAINT DF_core_politicas_recurso DEFAULT ('*');
END;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.check_constraints
        WHERE name = 'CK_core_politicas_efecto'
          AND parent_object_id = OBJECT_ID('dbo.core_politicas')
   )
BEGIN
    EXEC(N'ALTER TABLE dbo.core_politicas
        ADD CONSTRAINT CK_core_politicas_efecto CHECK (efecto IN (''Allow'', ''Deny''));');
END;

IF OBJECT_ID('dbo.core_usuario_politicas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_usuario_politicas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_usuario_politicas PRIMARY KEY,
        usuario_id BIGINT NOT NULL,
        politica_id BIGINT NOT NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_politicas_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_politicas_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_usuario_politicas UNIQUE (usuario_id, politica_id),
        CONSTRAINT CK_core_usuario_politicas_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF OBJECT_ID('dbo.core_usuario_roles', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_usuario_roles (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_usuario_roles PRIMARY KEY,
        usuario_id BIGINT NOT NULL,
        rol_id BIGINT NOT NULL,
        motivo NVARCHAR(255) NULL,
        vigente_desde DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_roles_desde DEFAULT (SYSDATETIME()),
        vigente_hasta DATETIME2(0) NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_roles_estado DEFAULT ('activo'),
        creado_por BIGINT NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_roles_creado DEFAULT (SYSDATETIME()),
        revocado_por BIGINT NULL,
        revocado_en DATETIME2(0) NULL,
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT CK_core_usuario_roles_estado CHECK (estado IN ('activo', 'revocado', 'expirado', 'inactivo'))
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuario_politicas_usuarios')
BEGIN
    ALTER TABLE dbo.core_usuario_politicas
    ADD CONSTRAINT FK_core_usuario_politicas_usuarios FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuario_politicas_politicas')
BEGIN
    ALTER TABLE dbo.core_usuario_politicas
    ADD CONSTRAINT FK_core_usuario_politicas_politicas FOREIGN KEY (politica_id) REFERENCES dbo.core_politicas(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuario_roles_usuarios')
BEGIN
    ALTER TABLE dbo.core_usuario_roles
    ADD CONSTRAINT FK_core_usuario_roles_usuarios FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuario_roles_roles')
BEGIN
    ALTER TABLE dbo.core_usuario_roles
    ADD CONSTRAINT FK_core_usuario_roles_roles FOREIGN KEY (rol_id) REFERENCES dbo.core_roles(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuario_roles_creador')
BEGIN
    ALTER TABLE dbo.core_usuario_roles
    ADD CONSTRAINT FK_core_usuario_roles_creador FOREIGN KEY (creado_por) REFERENCES dbo.core_usuarios(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_core_usuario_roles_revocador')
BEGIN
    ALTER TABLE dbo.core_usuario_roles
    ADD CONSTRAINT FK_core_usuario_roles_revocador FOREIGN KEY (revocado_por) REFERENCES dbo.core_usuarios(id);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_core_politicas_eval' AND object_id = OBJECT_ID('dbo.core_politicas'))
BEGIN
    EXEC(N'CREATE INDEX IX_core_politicas_eval ON dbo.core_politicas(estado, efecto, accion, recurso, sistema_codigo);');
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_core_usuario_politicas_usuario' AND object_id = OBJECT_ID('dbo.core_usuario_politicas'))
BEGIN
    CREATE INDEX IX_core_usuario_politicas_usuario ON dbo.core_usuario_politicas(usuario_id, estado);
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_core_usuario_roles_vigencia' AND object_id = OBJECT_ID('dbo.core_usuario_roles'))
BEGIN
    CREATE INDEX IX_core_usuario_roles_vigencia ON dbo.core_usuario_roles(usuario_id, estado, vigente_desde, vigente_hasta);
END;

MERGE dbo.core_grupos AS t
USING (SELECT codigo, nombre, descripcion, estado FROM (VALUES
    ('tickets_admin', N'Tickets - Administradores', N'Administra todo el sistema de tickets: emision, rangos, lotes, reportes y configuracion.', 'activo'),
    ('tickets_emision', N'Tickets - Emision', N'Emite tickets en ventanilla y confirma/cancela impresion.', 'activo'),
    ('tickets_consulta', N'Tickets - Consulta', N'Consulta y opera las vistas principales de tickets sin administracion avanzada.', 'activo'),
    ('tickets_rangos', N'Tickets - Rangos', N'Opera tickets, turnero, pantalla y administra rangos diarios sin historial ni reportes.', 'activo'),
    ('tickets_turnero', N'Tickets - Turnero', N'Atiende y llama tickets desde el modulo de turnero.', 'activo'),
    ('tickets_pantalla', N'Tickets - Pantalla', N'Muestra la pantalla publica de llamados sin operar tickets ni administrar el sistema.', 'activo'),
    ('tickets_solo_impresion', N'Tickets - Solo impresion', N'Permite emitir tickets impresos sin ingresar al turnero.', 'activo'),
    ('gs_admin', N'GS - Administradores', N'Administra usuarios, grupos, politicas y accesos del ecosistema NEO.', 'activo'),
    ('gs_consulta', N'GS - Consulta', N'Permite ingresar a Gestion de Usuarios en modo consulta.', 'activo'),
    ('inventario_admin', N'Inventario - Administradores', N'Administra personal, equipos e impresoras.', 'activo'),
    ('inventario_editor', N'Inventario - Editores', N'Crea y edita registros de inventario.', 'activo'),
    ('inventario_consulta', N'Inventario - Consulta', N'Consulta inventario sin modificar registros.', 'activo'),
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

EXEC sp_executesql N'
UPDATE dbo.core_politicas
SET accion = CASE
        WHEN codigo IN (''usuarios.ver'', ''usuarios.crear'', ''usuarios.editar'', ''usuarios.eliminar'') THEN codigo
        WHEN codigo IN (''roles.admin'', ''grupos.admin'', ''politicas.admin'') THEN codigo
        WHEN codigo LIKE ''tickets.%'' THEN codigo
        WHEN codigo LIKE ''firmas.%'' THEN codigo
        ELSE accion
    END,
    recurso = CASE
        WHEN sistema_codigo IS NOT NULL THEN CONCAT(''sistema:'', sistema_codigo)
        ELSE recurso
    END,
    efecto = CASE WHEN efecto IN (''Allow'', ''Deny'') THEN efecto ELSE ''Allow'' END
WHERE estado = ''activo'';
';

EXEC sp_executesql N'
MERGE dbo.core_politicas AS t
USING (SELECT codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado FROM (VALUES
    (''sistema.gs.entrar'', N''Entrar a Gestion de Usuarios'', ''gs'', N''Permite ver el sistema GS NEO en el portal.'', ''permiso'', ''Allow'', ''sistema.entrar'', ''sistema:gs'', ''activo''),
    (''sistema.gs.admin'', N''Administrar Gestion de Usuarios'', ''gs'', N''Permite administrar GS NEO.'', ''permiso'', ''Allow'', ''sistema.admin'', ''sistema:gs'', ''activo''),
    (''usuarios.ver'', N''Ver usuarios'', ''gs'', N''Permite listar y buscar usuarios.'', ''permiso'', ''Allow'', ''usuarios.ver'', ''sistema:gs'', ''activo''),
    (''usuarios.crear'', N''Crear usuarios'', ''gs'', N''Permite crear cuentas vinculadas a personal.'', ''permiso'', ''Allow'', ''usuarios.crear'', ''sistema:gs'', ''activo''),
    (''usuarios.editar'', N''Editar usuarios'', ''gs'', N''Permite modificar usuarios, roles y grupos.'', ''permiso'', ''Allow'', ''usuarios.editar'', ''sistema:gs'', ''activo''),
    (''usuarios.eliminar'', N''Eliminar usuarios'', ''gs'', N''Permite desactivar o eliminar usuarios.'', ''permiso'', ''Allow'', ''usuarios.eliminar'', ''sistema:gs'', ''activo''),
    (''roles.admin'', N''Administrar roles'', ''gs'', N''Permite crear y administrar roles.'', ''permiso'', ''Allow'', ''roles.admin'', ''sistema:gs'', ''activo''),
    (''grupos.admin'', N''Administrar grupos'', ''gs'', N''Permite crear y administrar grupos.'', ''permiso'', ''Allow'', ''grupos.admin'', ''sistema:gs'', ''activo''),
    (''politicas.admin'', N''Administrar politicas'', ''gs'', N''Permite crear y administrar politicas.'', ''permiso'', ''Allow'', ''politicas.admin'', ''sistema:gs'', ''activo''),
    (''sistema.inventario.entrar'', N''Entrar a Inventario NEO'', ''inventario'', N''Permite ver Inventario NEO en el portal.'', ''permiso'', ''Allow'', ''sistema.entrar'', ''sistema:inventario'', ''activo''),
    (''sistema.inventario.admin'', N''Administrar Inventario NEO'', ''inventario'', N''Permite administrar todos los modulos de inventario.'', ''permiso'', ''Allow'', ''sistema.admin'', ''sistema:inventario'', ''activo''),
    (''inventario.personal.ver'', N''Ver personal'', ''inventario'', N''Permite consultar personal.'', ''permiso'', ''Allow'', ''inventario.personal.ver'', ''sistema:inventario'', ''activo''),
    (''inventario.personal.editar'', N''Editar personal'', ''inventario'', N''Permite crear y modificar personal.'', ''permiso'', ''Allow'', ''inventario.personal.editar'', ''sistema:inventario'', ''activo''),
    (''inventario.equipos.ver'', N''Ver equipos'', ''inventario'', N''Permite consultar equipos.'', ''permiso'', ''Allow'', ''inventario.equipos.ver'', ''sistema:inventario'', ''activo''),
    (''inventario.equipos.editar'', N''Editar equipos'', ''inventario'', N''Permite crear y modificar equipos.'', ''permiso'', ''Allow'', ''inventario.equipos.editar'', ''sistema:inventario'', ''activo''),
    (''inventario.impresoras.ver'', N''Ver impresoras'', ''inventario'', N''Permite consultar impresoras.'', ''permiso'', ''Allow'', ''inventario.impresoras.ver'', ''sistema:inventario'', ''activo''),
    (''inventario.impresoras.editar'', N''Editar impresoras'', ''inventario'', N''Permite crear y modificar impresoras.'', ''permiso'', ''Allow'', ''inventario.impresoras.editar'', ''sistema:inventario'', ''activo''),
    (''sistema.tickets.entrar'', N''Entrar a Tickets'', ''tickets'', N''Permite ver el sistema de tickets en el portal.'', ''permiso'', ''Allow'', ''sistema.entrar'', ''sistema:tickets'', ''activo''),
    (''tickets.emision.ver'', N''Ver emision de tickets'', ''tickets'', N''Permite acceder a la vista de emision.'', ''permiso'', ''Allow'', ''tickets.emision.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.emitir'', N''Emitir tickets'', ''tickets'', N''Permite emitir tickets en ventanilla.'', ''permiso'', ''Allow'', ''tickets.emitir'', ''sistema:tickets'', ''activo''),
    (''tickets.emitir.solo_impresion'', N''Emitir solo impresion'', ''tickets'', N''Permite imprimir tickets sin enviarlos al turnero.'', ''permiso'', ''Allow'', ''tickets.emitir.solo_impresion'', ''sistema:tickets'', ''activo''),
    (''tickets.reimprimir'', N''Reimprimir tickets'', ''tickets'', N''Permite reimprimir tickets emitidos.'', ''permiso'', ''Allow'', ''tickets.reimprimir'', ''sistema:tickets'', ''activo''),
    (''tickets.reimprimir.dia'', N''Reimprimir tickets del dia'', ''tickets'', N''Permite reimprimir tickets emitidos durante el dia operativo.'', ''permiso'', ''Allow'', ''tickets.reimprimir.dia'', ''sistema:tickets'', ''activo''),
    (''tickets.reimprimir.historial'', N''Reimprimir desde historial'', ''tickets'', N''Permite reimprimir tickets desde el historial.'', ''permiso'', ''Allow'', ''tickets.reimprimir.historial'', ''sistema:tickets'', ''activo''),
    (''tickets.anular'', N''Anular tickets'', ''tickets'', N''Permite anular tickets emitidos.'', ''permiso'', ''Allow'', ''tickets.anular'', ''sistema:tickets'', ''activo''),
    (''tickets.historial.ver'', N''Ver historial de tickets'', ''tickets'', N''Permite consultar historial operativo de tickets.'', ''permiso'', ''Allow'', ''tickets.historial.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.reportes.ver'', N''Ver reportes de tickets'', ''tickets'', N''Permite consultar reportes de tickets.'', ''permiso'', ''Allow'', ''tickets.reportes.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.rangos.ver'', N''Ver rangos de tickets'', ''tickets'', N''Permite ver calendarios y rangos diarios.'', ''permiso'', ''Allow'', ''tickets.rangos.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.rangos.admin'', N''Administrar rangos de tickets'', ''tickets'', N''Permite crear o modificar rangos diarios.'', ''permiso'', ''Allow'', ''tickets.rangos.admin'', ''sistema:tickets'', ''activo''),
    (''tickets.lotes.ver'', N''Ver lotes de tickets'', ''tickets'', N''Permite consultar lotes pendientes de impresion.'', ''permiso'', ''Allow'', ''tickets.lotes.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.lotes.admin'', N''Administrar lotes de tickets'', ''tickets'', N''Permite generar, marcar y retomar lotes de impresion.'', ''permiso'', ''Allow'', ''tickets.lotes.admin'', ''sistema:tickets'', ''activo''),
    (''tickets.configurar'', N''Configurar Tickets'', ''tickets'', N''Permite modificar parametros y diseno de impresion.'', ''permiso'', ''Allow'', ''tickets.configurar'', ''sistema:tickets'', ''activo''),
    (''tickets.turnero.ver'', N''Ver turnero'', ''tickets'', N''Permite acceder a la vista del turnero.'', ''permiso'', ''Allow'', ''tickets.turnero.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.turnero.atender'', N''Atender turnero'', ''tickets'', N''Permite llamar y atender tickets en turnero.'', ''permiso'', ''Allow'', ''tickets.turnero.atender'', ''sistema:tickets'', ''activo''),
    (''tickets.pantalla.ver'', N''Ver pantalla de tickets'', ''tickets'', N''Permite usar la pantalla visual de tickets.'', ''permiso'', ''Allow'', ''tickets.pantalla.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.pantalla.admin'', N''Administrar pantalla de tickets'', ''tickets'', N''Permite crear y configurar pantallas fisicas.'', ''permiso'', ''Allow'', ''tickets.pantalla.admin'', ''sistema:tickets'', ''activo''),
    (''tickets.pantallas.ver'', N''Ver pantallas fisicas'', ''tickets'', N''Permite consultar las pantallas fisicas registradas.'', ''permiso'', ''Allow'', ''tickets.pantallas.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.ventanillas.ver'', N''Ver ventanillas'', ''tickets'', N''Permite consultar asignaciones de ventanillas.'', ''permiso'', ''Allow'', ''tickets.ventanillas.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.ventanillas.admin'', N''Administrar ventanillas'', ''tickets'', N''Permite asignar, liberar y configurar ventanillas.'', ''permiso'', ''Allow'', ''tickets.ventanillas.admin'', ''sistema:tickets'', ''activo''),
    (''tickets.impresion.ver'', N''Ver configuracion de impresion'', ''tickets'', N''Permite consultar configuracion de impresion.'', ''permiso'', ''Allow'', ''tickets.impresion.ver'', ''sistema:tickets'', ''activo''),
    (''tickets.impresion.admin'', N''Administrar impresion'', ''tickets'', N''Permite modificar diseno y parametros de impresion.'', ''permiso'', ''Allow'', ''tickets.impresion.admin'', ''sistema:tickets'', ''activo''),
    (''tickets.perfil.ver'', N''Ver perfil de Tickets'', ''tickets'', N''Permite acceder al perfil de usuario en Tickets.'', ''permiso'', ''Allow'', ''tickets.perfil.ver'', ''sistema:tickets'', ''activo''),
    (''sistema.soporte.entrar'', N''Entrar a Gestion de Soporte'', ''soporte'', N''Permite ver Gestion de Soporte NEO en el portal.'', ''permiso'', ''Allow'', ''sistema.entrar'', ''sistema:soporte'', ''activo''),
    (''sistema.soporte.admin'', N''Administrar Gestion de Soporte'', ''soporte'', N''Permite administrar Gestion de Soporte NEO.'', ''permiso'', ''Allow'', ''sistema.admin'', ''sistema:soporte'', ''activo''),
    (''soporte.tickets.crear'', N''Crear tickets de soporte'', ''soporte'', N''Permite registrar tickets de soporte.'', ''permiso'', ''Allow'', ''soporte.tickets.crear'', ''sistema:soporte'', ''activo''),
    (''soporte.tickets.asignar'', N''Asignar tickets de soporte'', ''soporte'', N''Permite asignar tecnicos a tickets.'', ''permiso'', ''Allow'', ''soporte.tickets.asignar'', ''sistema:soporte'', ''activo''),
    (''soporte.tickets.resolver'', N''Resolver tickets de soporte'', ''soporte'', N''Permite cerrar tickets de soporte.'', ''permiso'', ''Allow'', ''soporte.tickets.resolver'', ''sistema:soporte'', ''activo''),
    (''soporte.reportes.ver'', N''Ver reportes de soporte'', ''soporte'', N''Permite consultar reportes de soporte.'', ''permiso'', ''Allow'', ''soporte.reportes.ver'', ''sistema:soporte'', ''activo''),
    (''sistema.firmas.entrar'', N''Entrar a Firmas'', ''firmas'', N''Permite ver el sistema de firmas en el portal.'', ''permiso'', ''Allow'', ''sistema.entrar'', ''sistema:firmas'', ''activo''),
    (''sistema.todo.entrar'', N''Entrar a todos los sistemas'', NULL, N''Permite ver todos los sistemas activos.'', ''permiso'', ''Allow'', ''sistema.entrar'', ''sistema:*'', ''activo''),
    (''sistema.todo.admin'', N''Administrar todos los sistemas'', NULL, N''Permite administracion global de sistemas.'', ''permiso'', ''Allow'', ''sistema.admin'', ''sistema:*'', ''activo'')
) AS v(codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion,
               tipo = s.tipo, efecto = s.efecto, accion = s.accion, recurso = s.recurso,
               estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.efecto, s.accion, s.recurso, s.estado, SYSDATETIME());
';

DECLARE @superAdminRol BIGINT = (SELECT TOP 1 id FROM dbo.core_roles WHERE nombre IN (N'Super Admin', N'Super Administrador') ORDER BY id);
DECLARE @superAdminsGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'super_admins' ORDER BY id);
DECLARE @gsAdminGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'gs_admin' ORDER BY id);
DECLARE @gsConsultaGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'gs_consulta' ORDER BY id);
DECLARE @inventarioAdminGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'inventario_admin' ORDER BY id);
DECLARE @inventarioEditorGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'inventario_editor' ORDER BY id);
DECLARE @inventarioConsultaGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'inventario_consulta' ORDER BY id);
DECLARE @ticketsEmisionGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_emision' ORDER BY id);
DECLARE @ticketsConsultaGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_consulta' ORDER BY id);
DECLARE @ticketsRangosGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_rangos' ORDER BY id);
DECLARE @ticketsTurneroGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_turnero' ORDER BY id);
DECLARE @ticketsPantallaGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_pantalla' ORDER BY id);
DECLARE @ticketsSoloImpresionGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_solo_impresion' ORDER BY id);
DECLARE @ticketsAdminGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_admin' ORDER BY id);
DECLARE @soporteAdminGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'soporte_admin' ORDER BY id);
DECLARE @soporteTecnicoGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'soporte_tecnico' ORDER BY id);
DECLARE @soporteConsultaGrupo BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'soporte_consulta' ORDER BY id);

IF @superAdminsGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @superAdminsGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN ('sistema.todo.entrar', 'sistema.todo.admin')
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @superAdminsGrupo IS NOT NULL AND @superAdminRol IS NOT NULL
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

DELETE gp
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_grupos g ON g.id = gp.grupo_id
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE g.codigo IN ('gs_admin', 'gs_consulta')
  AND (p.codigo LIKE 'sistema.gs.%' OR p.sistema_codigo = 'gs');

DELETE gp
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_grupos g ON g.id = gp.grupo_id
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE g.codigo IN ('inventario_admin', 'inventario_editor', 'inventario_consulta')
  AND (p.codigo LIKE 'inventario.%' OR p.codigo LIKE 'sistema.inventario.%');

DELETE gp
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_grupos g ON g.id = gp.grupo_id
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE g.codigo IN (
        'tickets_admin',
        'tickets_emision',
        'tickets_consulta',
        'tickets_rangos',
        'tickets_turnero',
        'tickets_pantalla',
        'tickets_solo_impresion'
    )
  AND (p.codigo LIKE 'tickets.%' OR p.codigo LIKE 'sistema.tickets.%');

UPDATE dbo.core_grupo_usuarios
SET estado = 'inactivo', actualizado_en = SYSDATETIME()
WHERE grupo_id IN (SELECT id FROM dbo.core_grupos WHERE codigo = 'tickets_ventanilla');

UPDATE dbo.core_grupos
SET estado = 'inactivo',
    descripcion = N'Grupo retirado. Use Tickets - Emision y Tickets - Turnero segun corresponda.',
    actualizado_en = SYSDATETIME()
WHERE codigo = 'tickets_ventanilla';

IF @gsAdminGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @gsAdminGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.gs.entrar',
            'sistema.gs.admin',
            'usuarios.ver',
            'usuarios.crear',
            'usuarios.editar',
            'usuarios.eliminar',
            'roles.admin',
            'grupos.admin',
            'politicas.admin'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @gsConsultaGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @gsConsultaGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN ('sistema.gs.entrar', 'usuarios.ver')
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @inventarioAdminGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @inventarioAdminGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE sistema_codigo = 'inventario'
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @inventarioEditorGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @inventarioEditorGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.inventario.entrar',
            'inventario.personal.ver',
            'inventario.personal.editar',
            'inventario.equipos.ver',
            'inventario.equipos.editar',
            'inventario.impresoras.ver',
            'inventario.impresoras.editar'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @inventarioConsultaGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @inventarioConsultaGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.inventario.entrar',
            'inventario.personal.ver',
            'inventario.equipos.ver',
            'inventario.impresoras.ver'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsEmisionGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsEmisionGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.emision.ver',
            'tickets.perfil.ver',
            'tickets.emitir',
            'tickets.reimprimir',
            'tickets.reimprimir.dia'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsSoloImpresionGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsSoloImpresionGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.emision.ver',
            'tickets.perfil.ver',
            'tickets.emitir.solo_impresion',
            'tickets.reimprimir.dia'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsConsultaGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsConsultaGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.emision.ver',
            'tickets.perfil.ver',
            'tickets.emitir',
            'tickets.reimprimir',
            'tickets.reimprimir.dia',
            'tickets.historial.ver',
            'tickets.reportes.ver',
            'tickets.rangos.ver',
            'tickets.rangos.admin',
            'tickets.lotes.ver',
            'tickets.lotes.admin',
            'tickets.turnero.ver',
            'tickets.turnero.atender',
            'tickets.pantalla.ver',
            'tickets.pantallas.ver',
            'tickets.ventanillas.ver',
            'tickets.impresion.ver'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsRangosGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsRangosGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.emision.ver',
            'tickets.perfil.ver',
            'tickets.emitir',
            'tickets.reimprimir',
            'tickets.reimprimir.dia',
            'tickets.rangos.ver',
            'tickets.rangos.admin',
            'tickets.lotes.ver',
            'tickets.lotes.admin',
            'tickets.turnero.ver',
            'tickets.turnero.atender',
            'tickets.pantalla.ver'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsTurneroGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsTurneroGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.turnero.ver',
            'tickets.perfil.ver',
            'tickets.emitir',
            'tickets.reimprimir',
            'tickets.reimprimir.dia',
            'tickets.historial.ver',
            'tickets.rangos.ver',
            'tickets.rangos.admin',
            'tickets.lotes.ver',
            'tickets.lotes.admin',
            'tickets.turnero.atender',
            'tickets.pantalla.ver'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsPantallaGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsPantallaGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.pantalla.ver',
            'tickets.pantallas.ver'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @ticketsAdminGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsAdminGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.emision.ver',
            'tickets.perfil.ver',
            'tickets.emitir',
            'tickets.emitir.solo_impresion',
            'tickets.reimprimir',
            'tickets.reimprimir.dia',
            'tickets.reimprimir.historial',
            'tickets.anular',
            'tickets.historial.ver',
            'tickets.reportes.ver',
            'tickets.rangos.ver',
            'tickets.rangos.admin',
            'tickets.lotes.ver',
            'tickets.lotes.admin',
            'tickets.configurar',
            'tickets.turnero.ver',
            'tickets.turnero.atender',
            'tickets.pantalla.ver',
            'tickets.pantalla.admin',
            'tickets.pantallas.ver',
            'tickets.ventanillas.ver',
            'tickets.ventanillas.admin',
            'tickets.impresion.ver',
            'tickets.impresion.admin'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @soporteAdminGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @soporteAdminGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.soporte.entrar',
            'sistema.soporte.admin',
            'soporte.tickets.crear',
            'soporte.tickets.asignar',
            'soporte.tickets.resolver',
            'soporte.reportes.ver'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @soporteTecnicoGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @soporteTecnicoGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN ('sistema.soporte.entrar', 'soporte.tickets.crear', 'soporte.tickets.asignar', 'soporte.tickets.resolver')
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

IF @soporteConsultaGrupo IS NOT NULL
BEGIN
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @soporteConsultaGrupo AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN ('sistema.soporte.entrar', 'soporte.reportes.ver')
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());
END;

/*
  Compatibilidad temporal:
  Si ya existe el rol Super Admin, se mantiene su politica para no bloquear usuarios
  antiguos que asuman el rol por core_usuario_roles. La regla nueva es asignar
  permisos permanentes por grupo.
*/
IF @superAdminRol IS NOT NULL
BEGIN
    MERGE dbo.core_rol_politicas AS t
    USING (
        SELECT @superAdminRol AS rol_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN ('sistema.todo.entrar', 'sistema.todo.admin')
    ) AS s
    ON t.rol_id = s.rol_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN
        UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (rol_id, politica_id, permitido, creado_en)
        VALUES (s.rol_id, s.politica_id, s.permitido, SYSDATETIME());
END;

EXEC sp_executesql N'
SELECT codigo, efecto, accion, recurso, estado
FROM dbo.core_politicas
WHERE codigo LIKE ''sistema.%''
   OR codigo LIKE ''inventario.%''
ORDER BY codigo;
';
