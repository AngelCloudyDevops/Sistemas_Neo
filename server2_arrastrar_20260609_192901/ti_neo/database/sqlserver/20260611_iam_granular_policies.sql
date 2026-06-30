/*
  IAM granular - 2026-06-11
  Ejecutar en GESTION_INF despues del modelo base IAM.
  Define permisos unitarios, grupos especificos y permisos directos por usuario.
*/

IF OBJECT_ID('dbo.core_usuario_politicas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_usuario_politicas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_usuario_politicas PRIMARY KEY,
        usuario_id BIGINT NOT NULL,
        politica_id BIGINT NOT NULL,
        permitido BIT NOT NULL CONSTRAINT DF_core_usuario_politicas_permitido DEFAULT (1),
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuario_politicas_estado DEFAULT ('activo'),
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_politicas_creado DEFAULT (SYSDATETIME()),
        actualizado_en DATETIME2(0) NULL,
        CONSTRAINT UQ_core_usuario_politicas UNIQUE (usuario_id, politica_id),
        CONSTRAINT CK_core_usuario_politicas_estado CHECK (estado IN ('activo', 'inactivo'))
    );
END;

IF COL_LENGTH('dbo.core_usuario_politicas', 'permitido') IS NULL
BEGIN
    ALTER TABLE dbo.core_usuario_politicas
    ADD permitido BIT NOT NULL CONSTRAINT DF_core_usuario_politicas_permitido DEFAULT (1);
END;

MERGE dbo.core_grupos AS t
USING (SELECT codigo, nombre, descripcion, estado FROM (VALUES
    ('gs_admin', N'GS - Administradores', N'Administra usuarios, grupos, politicas y reinicios en GS NEO.', 'activo'),
    ('gs_consulta', N'GS - Consulta', N'Consulta usuarios, grupos, politicas y auditoria sin modificar accesos.', 'activo'),
    ('tickets_historial', N'Tickets - Historial', N'Consulta historial operativo de tickets.', 'activo'),
    ('tickets_reportes', N'Tickets - Reportes', N'Consulta reportes de tickets.', 'activo'),
    ('tickets_configuracion', N'Tickets - Configuracion', N'Administra ventanillas, pantallas seguras e impresion.', 'activo')
) AS v(codigo, nombre, descripcion, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.estado, SYSDATETIME());

UPDATE dbo.core_grupos
SET estado = 'inactivo',
    descripcion = N'Grupo retirado por ser ambiguo. Use Tickets - Historial, Tickets - Reportes y otros grupos especificos.',
    actualizado_en = SYSDATETIME()
WHERE codigo IN ('tickets_consulta', 'tickets_ventanilla');

UPDATE dbo.core_grupo_usuarios
SET estado = 'inactivo', actualizado_en = SYSDATETIME()
WHERE grupo_id IN (SELECT id FROM dbo.core_grupos WHERE codigo IN ('tickets_consulta', 'tickets_ventanilla'));

MERGE dbo.core_politicas AS t
USING (SELECT codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado FROM (VALUES
    ('sistema.gs.entrar', N'Entrar a GS', 'gs', N'Permite acceder a Gestion de Usuarios NEO.', 'permiso', 'Allow', 'sistema.entrar', 'sistema:gs', 'activo'),
    ('sistema.gs.admin', N'Administrar GS', 'gs', N'Permite administrar Gestion de Usuarios NEO.', 'permiso', 'Allow', 'sistema.admin', 'sistema:gs', 'activo'),
    ('usuarios.ver', N'Ver usuarios', 'gs', N'Permite listar y buscar usuarios.', 'permiso', 'Allow', 'usuarios.ver', 'sistema:gs', 'activo'),
    ('usuarios.crear', N'Crear usuarios', 'gs', N'Permite crear usuarios.', 'permiso', 'Allow', 'usuarios.crear', 'sistema:gs', 'activo'),
    ('usuarios.editar', N'Editar usuarios', 'gs', N'Permite editar usuarios y accesos.', 'permiso', 'Allow', 'usuarios.editar', 'sistema:gs', 'activo'),
    ('usuarios.eliminar', N'Retirar usuarios', 'gs', N'Permite desactivar o retirar usuarios.', 'permiso', 'Allow', 'usuarios.eliminar', 'sistema:gs', 'activo'),
    ('grupos.admin', N'Administrar grupos', 'gs', N'Permite asignar grupos a usuarios.', 'permiso', 'Allow', 'grupos.admin', 'sistema:gs', 'activo'),
    ('politicas.admin', N'Administrar politicas', 'gs', N'Permite asignar permisos especiales directos.', 'permiso', 'Allow', 'politicas.admin', 'sistema:gs', 'activo'),

    ('tickets.emision.ver', N'Ver emision', 'tickets', N'Permite ver la pantalla de emision de tickets.', 'permiso', 'Allow', 'tickets.emision.ver', 'sistema:tickets', 'activo'),
    ('tickets.perfil.ver', N'Ver mi perfil', 'tickets', N'Permite ver el perfil propio dentro de Tickets NEO.', 'permiso', 'Allow', 'tickets.perfil.ver', 'sistema:tickets', 'activo'),
    ('tickets.reimprimir.dia', N'Reimprimir del dia', 'tickets', N'Permite reimprimir tickets emitidos hoy sin historial completo.', 'permiso', 'Allow', 'tickets.reimprimir.dia', 'sistema:tickets', 'activo'),
    ('tickets.reimprimir.historial', N'Reimprimir historial', 'tickets', N'Permite reimprimir tickets desde historial.', 'permiso', 'Allow', 'tickets.reimprimir.historial', 'sistema:tickets', 'activo'),
    ('tickets.reportes.exportar', N'Exportar reportes', 'tickets', N'Permite exportar reportes de tickets.', 'permiso', 'Allow', 'tickets.reportes.exportar', 'sistema:tickets', 'activo'),
    ('tickets.lotes.ver', N'Ver lotes', 'tickets', N'Permite ver lotes pendientes y estado de impresion.', 'permiso', 'Allow', 'tickets.lotes.ver', 'sistema:tickets', 'activo'),
    ('tickets.turnero.ver', N'Ver turnero', 'tickets', N'Permite ver la cola del turnero.', 'permiso', 'Allow', 'tickets.turnero.ver', 'sistema:tickets', 'activo'),
    ('tickets.pantallas.ver', N'Ver administracion de pantallas', 'tickets', N'Permite ver pantallas fisicas registradas.', 'permiso', 'Allow', 'tickets.pantallas.ver', 'sistema:tickets', 'activo'),
    ('tickets.pantalla.admin', N'Administrar pantallas', 'tickets', N'Permite crear y regenerar pantallas seguras.', 'permiso', 'Allow', 'tickets.pantalla.admin', 'sistema:tickets', 'activo'),
    ('tickets.ventanillas.ver', N'Ver ventanillas', 'tickets', N'Permite ver configuracion de ventanillas.', 'permiso', 'Allow', 'tickets.ventanillas.ver', 'sistema:tickets', 'activo'),
    ('tickets.ventanillas.admin', N'Administrar ventanillas', 'tickets', N'Permite asignar, liberar y configurar ventanillas.', 'permiso', 'Allow', 'tickets.ventanillas.admin', 'sistema:tickets', 'activo'),
    ('tickets.impresion.ver', N'Ver impresion', 'tickets', N'Permite ver el diseno de impresion.', 'permiso', 'Allow', 'tickets.impresion.ver', 'sistema:tickets', 'activo'),
    ('tickets.impresion.admin', N'Administrar impresion', 'tickets', N'Permite guardar o restaurar el diseno de impresion.', 'permiso', 'Allow', 'tickets.impresion.admin', 'sistema:tickets', 'activo')
) AS v(codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion, tipo = s.tipo,
               efecto = s.efecto, accion = s.accion, recurso = s.recurso, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.efecto, s.accion, s.recurso, s.estado, SYSDATETIME());

DECLARE @super BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'super_admins');
DECLARE @gsAdmin BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'gs_admin');
DECLARE @gsConsulta BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'gs_consulta');
DECLARE @ticketsAdmin BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_admin');
DECLARE @ticketsEmision BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_emision');
DECLARE @ticketsRangos BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_rangos');
DECLARE @ticketsTurnero BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_turnero');
DECLARE @ticketsPantalla BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_pantalla');
DECLARE @ticketsHistorial BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_historial');
DECLARE @ticketsReportes BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_reportes');
DECLARE @ticketsConfig BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_configuracion');

DELETE gp
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_grupos g ON g.id = gp.grupo_id
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE g.codigo IN ('gs_admin','gs_consulta','tickets_admin','tickets_emision','tickets_rangos','tickets_turnero','tickets_pantalla','tickets_historial','tickets_reportes','tickets_configuracion')
  AND (p.codigo LIKE 'sistema.gs.%' OR p.sistema_codigo IN ('gs', 'tickets') OR p.codigo LIKE 'tickets.%');

IF @gsAdmin IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @gsAdmin grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.gs.entrar','sistema.gs.admin','usuarios.ver','usuarios.crear','usuarios.editar','usuarios.eliminar','grupos.admin','politicas.admin')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @gsAdmin IS NOT NULL
    MERGE dbo.core_grupo_usuarios AS t
    USING (
        SELECT id usuario_id, @gsAdmin grupo_id
        FROM dbo.core_usuarios
        WHERE estado = 'activo'
          AND LOWER(email) IN ('zgonzales@hsj.gob.pe')
    ) s
    ON t.usuario_id = s.usuario_id AND t.grupo_id = s.grupo_id
    WHEN MATCHED THEN UPDATE SET estado = 'activo', actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (usuario_id, grupo_id, estado, creado_en) VALUES (s.usuario_id, s.grupo_id, 'activo', SYSDATETIME());

IF @gsConsulta IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @gsConsulta grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.gs.entrar','usuarios.ver')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsEmision IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsEmision grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.emision.ver','tickets.perfil.ver','tickets.emitir','tickets.reimprimir.dia')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsHistorial IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsHistorial grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.perfil.ver','tickets.historial.ver')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsReportes IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsReportes grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.perfil.ver','tickets.reportes.ver')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsRangos IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsRangos grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.perfil.ver','tickets.rangos.ver','tickets.rangos.admin','tickets.lotes.ver','tickets.lotes.admin')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsTurnero IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsTurnero grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.perfil.ver','tickets.turnero.ver','tickets.turnero.atender','tickets.pantalla.ver')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsPantalla IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsPantalla grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.pantalla.ver')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsConfig IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsConfig grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE codigo IN ('sistema.tickets.entrar','tickets.perfil.ver','tickets.ventanillas.ver','tickets.ventanillas.admin','tickets.pantallas.ver','tickets.pantalla.admin','tickets.impresion.ver','tickets.impresion.admin','tickets.configurar')) s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

IF @ticketsAdmin IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (SELECT @ticketsAdmin grupo_id, id politica_id, CAST(1 AS BIT) permitido FROM dbo.core_politicas WHERE sistema_codigo = 'tickets' OR codigo = 'sistema.tickets.entrar') s
    ON t.grupo_id=s.grupo_id AND t.politica_id=s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido=s.permitido, actualizado_en=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en) VALUES (s.grupo_id,s.politica_id,s.permitido,SYSDATETIME());

PRINT N'IAM granular actualizado.';
