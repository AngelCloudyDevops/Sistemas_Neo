/*
  Portal NEO - datos iniciales.
  Ejecutar despues de neo_schema.sql en GESTION_INF1.
*/
USE GESTION_INF1;
IF COL_LENGTH('dbo.neo_sistemas', 'clase_visual') IS NULL
BEGIN
    ALTER TABLE dbo.neo_sistemas
    ADD clase_visual VARCHAR(40) NOT NULL CONSTRAINT DF_neo_sistemas_clase_visual DEFAULT ('core');
END;
MERGE dbo.neo_sistemas AS t
USING (SELECT codigo, nombre, descripcion, url_base, icono, clase_visual, estado, orden FROM (VALUES
    ('gs', N'Gestion de Usuarios', N'Administracion central de usuarios, grupos, politicas y accesos.', N'/gs_neo/public', N'users-cog', 'core', 'activo', 5),
    ('tickets', N'Sistema de Tickets', N'Emision, historial y reportes de tickets de atencion.', N'/ti_neo/public', N'ticket', 'atencion', 'activo', 10),
    ('soporte', N'Gestion de Soporte NEO', N'Mesa de ayuda, asignacion, atencion y cierre de tickets de soporte TI.', N'/soporte_neo/public', N'headphones', 'soporte', 'activo', 12),
    ('inventario', N'Inventario NEO', N'Gestion de personal, equipos e impresoras institucionales.', N'/inventario_neo/public', N'archive', 'operaciones', 'activo', 14),
    ('firmas', N'Gestion de Firmas', N'Gestion de certificados, firmas, alertas y reportes.', N'/SIBD/public', N'file-signature', 'documentos', 'activo', 20)
) AS v(codigo, nombre, descripcion, url_base, icono, clase_visual, estado, orden)) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET
        nombre = s.nombre,
        descripcion = s.descripcion,
        url_base = s.url_base,
        icono = s.icono,
        clase_visual = s.clase_visual,
        estado = s.estado,
        orden = s.orden,
        actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, url_base, icono, clase_visual, estado, orden, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.url_base, s.icono, s.clase_visual, s.estado, s.orden, SYSDATETIME());
DECLARE @gs BIGINT = (SELECT TOP 1 id FROM dbo.neo_sistemas WHERE codigo = 'gs');
DECLARE @tickets BIGINT = (SELECT TOP 1 id FROM dbo.neo_sistemas WHERE codigo = 'tickets');
DECLARE @soporte BIGINT = (SELECT TOP 1 id FROM dbo.neo_sistemas WHERE codigo = 'soporte');
DECLARE @inventario BIGINT = (SELECT TOP 1 id FROM dbo.neo_sistemas WHERE codigo = 'inventario');
DECLARE @firmas BIGINT = (SELECT TOP 1 id FROM dbo.neo_sistemas WHERE codigo = 'firmas');

MERGE dbo.neo_permisos_sistema AS t
USING (
    SELECT @gs AS sistema_id, r.id AS rol_id, CAST(1 AS BIT) AS puede_ver, CAST(1 AS BIT) AS puede_admin
    FROM dbo.core_roles r
    WHERE r.nombre IN (N'Super Admin')

    UNION ALL

    SELECT @tickets AS sistema_id, r.id AS rol_id, CAST(1 AS BIT) AS puede_ver,
           CASE WHEN r.nombre IN (N'Super Admin', N'Administrador') THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS puede_admin
    FROM dbo.core_roles r
    WHERE r.nombre IN (N'Super Admin', N'Administrador', N'Ventanilla', N'Consulta')

    UNION ALL

    SELECT @soporte AS sistema_id, r.id AS rol_id, CAST(1 AS BIT) AS puede_ver,
           CASE WHEN r.nombre IN (N'Super Admin', N'Administrador') THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS puede_admin
    FROM dbo.core_roles r
    WHERE r.nombre IN (N'Super Admin', N'Administrador', N'Tecnico', N'Soporte', N'Consulta')

    UNION ALL

    SELECT @inventario AS sistema_id, r.id AS rol_id, CAST(1 AS BIT) AS puede_ver,
           CASE WHEN r.nombre IN (N'Super Admin', N'Administrador') THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS puede_admin
    FROM dbo.core_roles r
    WHERE r.nombre IN (N'Super Admin', N'Administrador', N'Consulta')

    UNION ALL

    SELECT @firmas AS sistema_id, r.id AS rol_id, CAST(1 AS BIT) AS puede_ver,
           CASE WHEN r.nombre IN (N'Super Admin', N'Administrador') THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS puede_admin
    FROM dbo.core_roles r
    WHERE r.nombre IN (N'Super Admin', N'Administrador')
) AS s
ON t.sistema_id = s.sistema_id AND t.rol_id = s.rol_id
WHEN MATCHED THEN
    UPDATE SET
        puede_ver = s.puede_ver,
        puede_admin = s.puede_admin,
        actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (sistema_id, rol_id, puede_ver, puede_admin, creado_en)
    VALUES (s.sistema_id, s.rol_id, s.puede_ver, s.puede_admin, SYSDATETIME());
SELECT
    s.codigo,
    s.nombre AS sistema,
    r.nombre AS rol,
    p.puede_ver,
    p.puede_admin
FROM dbo.neo_permisos_sistema p
INNER JOIN dbo.neo_sistemas s ON s.id = p.sistema_id
INNER JOIN dbo.core_roles r ON r.id = p.rol_id
ORDER BY s.orden, r.nombre;

