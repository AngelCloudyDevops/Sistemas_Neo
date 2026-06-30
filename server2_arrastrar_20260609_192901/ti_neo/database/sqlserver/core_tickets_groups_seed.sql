/*
  NEO - grupos y politicas funcionales para ti_neo.
  Ejecutar en GESTION_INF1 para desarrollo. No ejecutar en GESTION_INF produccion
  hasta validar el flujo completo desde gs_neo y ti_neo.

  Modelo:
  - Grupos = acceso permanente.
  - Politicas = acciones concretas.
  - Roles = compatibilidad/elevacion temporal, no fuente principal permanente.
*/
USE GESTION_INF1;
SET NOCOUNT ON;

IF OBJECT_ID('dbo.core_grupos', 'U') IS NULL
    THROW 50001, 'Falta dbo.core_grupos. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NULL
    THROW 50002, 'Falta dbo.core_politicas. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_grupo_politicas', 'U') IS NULL
    THROW 50003, 'Falta dbo.core_grupo_politicas. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF COL_LENGTH('dbo.core_politicas', 'efecto') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD efecto VARCHAR(10) NOT NULL CONSTRAINT DF_core_politicas_efecto DEFAULT ('Allow');
END;

IF COL_LENGTH('dbo.core_politicas', 'accion') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD accion VARCHAR(120) NOT NULL CONSTRAINT DF_core_politicas_accion DEFAULT ('*');
END;

IF COL_LENGTH('dbo.core_politicas', 'recurso') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD recurso VARCHAR(160) NOT NULL CONSTRAINT DF_core_politicas_recurso DEFAULT ('*');
END;

MERGE dbo.core_grupos AS t
USING (SELECT codigo, nombre, descripcion, estado FROM (VALUES
    ('tickets_admin', N'Tickets - Administradores', N'Administra todo el sistema de tickets: emision, rangos, lotes, reportes y configuracion.', 'activo'),
    ('tickets_emision', N'Tickets - Emision', N'Emite tickets en ventanilla y confirma/cancela impresion.', 'activo'),
    ('tickets_consulta', N'Tickets - Consulta', N'Consulta y opera las vistas principales de tickets sin administracion avanzada.', 'activo'),
    ('tickets_rangos', N'Tickets - Rangos', N'Opera tickets, turnero, pantalla y administra rangos diarios sin historial ni reportes.', 'activo'),
    ('tickets_turnero', N'Tickets - Turnero', N'Atiende y llama tickets desde el modulo de turnero.', 'activo'),
    ('tickets_pantalla', N'Tickets - Pantalla', N'Muestra la pantalla publica de llamados sin operar tickets ni administrar el sistema.', 'activo')
) AS v(codigo, nombre, descripcion, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.estado, SYSDATETIME());

MERGE dbo.core_politicas AS t
USING (SELECT codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado FROM (VALUES
    ('sistema.tickets.entrar', N'Entrar a Tickets', 'tickets', N'Permite ver ti_neo en el portal y cargar el modulo.', 'permiso', 'Allow', 'sistema.entrar', 'sistema:tickets', 'activo'),
    ('sistema.tickets.admin', N'Administrar Tickets', 'tickets', N'Permite administracion completa de ti_neo.', 'permiso', 'Allow', 'sistema.admin', 'sistema:tickets', 'activo'),
    ('tickets.emitir', N'Emitir tickets', 'tickets', N'Permite emitir tickets desde ventanilla.', 'permiso', 'Allow', 'tickets.emitir', 'sistema:tickets', 'activo'),
    ('tickets.reimprimir', N'Reimprimir tickets', 'tickets', N'Permite reimprimir tickets emitidos.', 'permiso', 'Allow', 'tickets.reimprimir', 'sistema:tickets', 'activo'),
    ('tickets.anular', N'Anular tickets', 'tickets', N'Permite anular tickets emitidos.', 'permiso', 'Allow', 'tickets.anular', 'sistema:tickets', 'activo'),
    ('tickets.historial.ver', N'Ver historial de tickets', 'tickets', N'Permite consultar historial operativo de tickets.', 'permiso', 'Allow', 'tickets.historial.ver', 'sistema:tickets', 'activo'),
    ('tickets.reportes.ver', N'Ver reportes de tickets', 'tickets', N'Permite consultar reportes de tickets.', 'permiso', 'Allow', 'tickets.reportes.ver', 'sistema:tickets', 'activo'),
    ('tickets.rangos.ver', N'Ver rangos de tickets', 'tickets', N'Permite ver calendarios y rangos diarios.', 'permiso', 'Allow', 'tickets.rangos.ver', 'sistema:tickets', 'activo'),
    ('tickets.rangos.admin', N'Administrar rangos de tickets', 'tickets', N'Permite crear o modificar rangos diarios.', 'permiso', 'Allow', 'tickets.rangos.admin', 'sistema:tickets', 'activo'),
    ('tickets.lotes.admin', N'Administrar lotes de tickets', 'tickets', N'Permite generar, marcar y retomar lotes de impresion.', 'permiso', 'Allow', 'tickets.lotes.admin', 'sistema:tickets', 'activo'),
    ('tickets.configurar', N'Configurar Tickets', 'tickets', N'Permite modificar parametros y diseno de impresion.', 'permiso', 'Allow', 'tickets.configurar', 'sistema:tickets', 'activo'),
    ('tickets.turnero.atender', N'Atender turnero', 'tickets', N'Permite llamar y atender tickets en turnero.', 'permiso', 'Allow', 'tickets.turnero.atender', 'sistema:tickets', 'activo'),
    ('tickets.pantalla.ver', N'Ver pantalla de tickets', 'tickets', N'Permite usar la pantalla visual de tickets.', 'permiso', 'Allow', 'tickets.pantalla.ver', 'sistema:tickets', 'activo')
) AS v(codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion,
               tipo = s.tipo, efecto = s.efecto, accion = s.accion, recurso = s.recurso,
               estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.efecto, s.accion, s.recurso, s.estado, SYSDATETIME());

DECLARE @ticketsAdmin BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_admin');
DECLARE @ticketsEmision BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_emision');
DECLARE @ticketsConsulta BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_consulta');
DECLARE @ticketsRangos BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_rangos');
DECLARE @ticketsTurnero BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_turnero');
DECLARE @ticketsPantalla BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_pantalla');

UPDATE dbo.core_grupo_usuarios
SET estado = 'inactivo', actualizado_en = SYSDATETIME()
WHERE grupo_id IN (SELECT id FROM dbo.core_grupos WHERE codigo = 'tickets_ventanilla');

UPDATE dbo.core_grupos
SET estado = 'inactivo',
    descripcion = N'Grupo retirado. Use Tickets - Emision y Tickets - Turnero segun corresponda.',
    actualizado_en = SYSDATETIME()
WHERE codigo = 'tickets_ventanilla';

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
        'tickets_pantalla'
    )
  AND (p.codigo LIKE 'tickets.%' OR p.codigo LIKE 'sistema.tickets.%');

MERGE dbo.core_grupo_politicas AS t
USING (
    SELECT @ticketsAdmin AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.tickets.entrar',
        'sistema.tickets.admin',
        'tickets.emitir',
        'tickets.reimprimir',
        'tickets.anular',
        'tickets.historial.ver',
        'tickets.reportes.ver',
        'tickets.rangos.ver',
        'tickets.rangos.admin',
        'tickets.lotes.admin',
        'tickets.configurar',
        'tickets.turnero.atender',
        'tickets.pantalla.ver'
    )
    UNION ALL
    SELECT @ticketsEmision, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN ('sistema.tickets.entrar', 'tickets.emitir', 'tickets.reimprimir')
    UNION ALL
    SELECT @ticketsConsulta, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.tickets.entrar',
        'tickets.emitir',
        'tickets.reimprimir',
        'tickets.historial.ver',
        'tickets.reportes.ver',
        'tickets.rangos.ver',
        'tickets.rangos.admin',
        'tickets.lotes.admin',
        'tickets.turnero.atender',
        'tickets.pantalla.ver'
    )
    UNION ALL
    SELECT @ticketsRangos, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.tickets.entrar',
        'tickets.emitir',
        'tickets.reimprimir',
        'tickets.rangos.ver',
        'tickets.rangos.admin',
        'tickets.lotes.admin',
        'tickets.turnero.atender',
        'tickets.pantalla.ver'
    )
    UNION ALL
    SELECT @ticketsTurnero, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.tickets.entrar',
        'tickets.emitir',
        'tickets.reimprimir',
        'tickets.historial.ver',
        'tickets.rangos.ver',
        'tickets.rangos.admin',
        'tickets.lotes.admin',
        'tickets.turnero.atender',
        'tickets.pantalla.ver'
    )
    UNION ALL
    SELECT @ticketsPantalla, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.tickets.entrar',
        'tickets.pantalla.ver'
    )
) AS s
ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
WHEN MATCHED THEN
    UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (grupo_id, politica_id, permitido, creado_en)
    VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

SELECT
    g.codigo AS grupo_codigo,
    g.nombre AS grupo,
    p.codigo AS politica_codigo,
    p.accion,
    p.recurso,
    gp.permitido
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_grupos g ON g.id = gp.grupo_id
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE g.codigo LIKE 'tickets_%'
ORDER BY g.nombre, p.codigo;
