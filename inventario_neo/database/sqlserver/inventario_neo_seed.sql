/*
  Inventario NEO.
  Ejecutar en GESTION_INF1 despues del esquema IAM/NEO.
*/
USE GESTION_INF1;
SET NOCOUNT ON;

IF OBJECT_ID('dbo.neo_sistemas', 'U') IS NULL
    THROW 52001, 'Falta dbo.neo_sistemas. Ejecuta neo_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_grupos', 'U') IS NULL
    THROW 52002, 'Falta dbo.core_grupos. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NULL
    THROW 52003, 'Falta dbo.core_politicas. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF OBJECT_ID('dbo.core_grupo_politicas', 'U') IS NULL
    THROW 52004, 'Falta dbo.core_grupo_politicas. Ejecuta core_identity_rbac_schema.sql primero.', 1;

IF COL_LENGTH('dbo.core_politicas', 'efecto') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD efecto VARCHAR(10) NOT NULL CONSTRAINT DF_core_politicas_efecto_inventario DEFAULT ('Allow');
END;

IF COL_LENGTH('dbo.core_politicas', 'accion') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD accion VARCHAR(120) NOT NULL CONSTRAINT DF_core_politicas_accion_inventario DEFAULT ('*');
END;

IF COL_LENGTH('dbo.core_politicas', 'recurso') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD recurso VARCHAR(160) NOT NULL CONSTRAINT DF_core_politicas_recurso_inventario DEFAULT ('*');
END;

MERGE dbo.neo_sistemas AS t
USING (SELECT
    'inventario' AS codigo,
    N'Inventario NEO' AS nombre,
    N'Gestion de personal, equipos e impresoras institucionales.' AS descripcion,
    N'/inventario_neo/public' AS url_base,
    N'archive' AS icono,
    'activo' AS estado,
    14 AS orden
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
    ('inventario_admin', N'Inventario - Administradores', N'Administra personal, equipos e impresoras.', 'activo'),
    ('inventario_editor', N'Inventario - Editores', N'Crea y edita registros de inventario.', 'activo'),
    ('inventario_consulta', N'Inventario - Consulta', N'Consulta inventario sin modificar registros.', 'activo')
) AS v(codigo, nombre, descripcion, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.estado, SYSDATETIME());

MERGE dbo.core_politicas AS t
USING (SELECT codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado FROM (VALUES
    ('sistema.inventario.entrar', N'Entrar a Inventario NEO', 'inventario', N'Permite ver Inventario NEO en el portal.', 'permiso', 'Allow', 'sistema.entrar', 'sistema:inventario', 'activo'),
    ('sistema.inventario.admin', N'Administrar Inventario NEO', 'inventario', N'Permite administrar todos los modulos de inventario.', 'permiso', 'Allow', 'sistema.admin', 'sistema:inventario', 'activo'),
    ('inventario.personal.ver', N'Ver personal', 'inventario', N'Permite consultar core_personal.', 'permiso', 'Allow', 'inventario.personal.ver', 'sistema:inventario', 'activo'),
    ('inventario.personal.editar', N'Editar personal', 'inventario', N'Permite crear y modificar personal.', 'permiso', 'Allow', 'inventario.personal.editar', 'sistema:inventario', 'activo'),
    ('inventario.equipos.ver', N'Ver equipos', 'inventario', N'Permite consultar core_equipos.', 'permiso', 'Allow', 'inventario.equipos.ver', 'sistema:inventario', 'activo'),
    ('inventario.equipos.editar', N'Editar equipos', 'inventario', N'Permite crear y modificar equipos.', 'permiso', 'Allow', 'inventario.equipos.editar', 'sistema:inventario', 'activo'),
    ('inventario.impresoras.ver', N'Ver impresoras', 'inventario', N'Permite consultar core_impresoras.', 'permiso', 'Allow', 'inventario.impresoras.ver', 'sistema:inventario', 'activo'),
    ('inventario.impresoras.editar', N'Editar impresoras', 'inventario', N'Permite crear y modificar impresoras.', 'permiso', 'Allow', 'inventario.impresoras.editar', 'sistema:inventario', 'activo')
) AS v(codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion,
               tipo = s.tipo, efecto = s.efecto, accion = s.accion, recurso = s.recurso,
               estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.efecto, s.accion, s.recurso, s.estado, SYSDATETIME());

DECLARE @admin BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'inventario_admin');
DECLARE @editor BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'inventario_editor');
DECLARE @consulta BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'inventario_consulta');
DECLARE @super_admins BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'super_admins');

MERGE dbo.core_grupo_politicas AS t
USING (
    SELECT @admin AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
    FROM dbo.core_politicas
    WHERE sistema_codigo = 'inventario'
      AND @admin IS NOT NULL
    UNION ALL
    SELECT @super_admins, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE sistema_codigo = 'inventario'
      AND @super_admins IS NOT NULL
    UNION ALL
    SELECT @editor, id, CAST(1 AS BIT)
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
      AND @editor IS NOT NULL
    UNION ALL
    SELECT @consulta, id, CAST(1 AS BIT)
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.inventario.entrar',
        'inventario.personal.ver',
        'inventario.equipos.ver',
        'inventario.impresoras.ver'
    )
      AND @consulta IS NOT NULL
) AS s
ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
WHEN MATCHED THEN
    UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (grupo_id, politica_id, permitido, creado_en)
    VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

IF OBJECT_ID('dbo.neo_permisos_sistema', 'U') IS NOT NULL
BEGIN
    DECLARE @sistema BIGINT = (SELECT TOP 1 id FROM dbo.neo_sistemas WHERE codigo = 'inventario');

    MERGE dbo.neo_permisos_sistema AS t
    USING (
        SELECT @sistema AS sistema_id, r.id AS rol_id, CAST(1 AS BIT) AS puede_ver,
               CASE WHEN r.nombre IN (N'Super Admin', N'Administrador') THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS puede_admin
        FROM dbo.core_roles r
        WHERE r.nombre IN (N'Super Admin', N'Administrador')
    ) AS s
    ON t.sistema_id = s.sistema_id AND t.rol_id = s.rol_id
    WHEN MATCHED THEN
        UPDATE SET puede_ver = s.puede_ver, puede_admin = s.puede_admin, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (sistema_id, rol_id, puede_ver, puede_admin, creado_en)
        VALUES (s.sistema_id, s.rol_id, s.puede_ver, s.puede_admin, SYSDATETIME());
END;

SELECT codigo, nombre, url_base, orden
FROM dbo.neo_sistemas
WHERE codigo = 'inventario';
