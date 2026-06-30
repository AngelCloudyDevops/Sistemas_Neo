/*
  Crea/recarga GESTION_INF_DEV copiando datos desde GESTION_INF.
  Ejecutar en Navicat conectado al SQL Server 172.16.10.18.

  No modifica datos de GESTION_INF. Solo lee produccion y escribe DEV.
*/

USE master;
GO

IF DB_ID(N'GESTION_INF_DEV') IS NULL
BEGIN
    CREATE DATABASE GESTION_INF_DEV;
END;
GO

USE GESTION_INF_DEV;
GO

DECLARE @dropSql NVARCHAR(MAX) = N'';

SELECT @dropSql = @dropSql + N'
IF OBJECT_ID(N''dbo.' + name + N''', N''U'') IS NOT NULL DROP TABLE dbo.' + QUOTENAME(name) + N';'
FROM sys.tables
WHERE name IN (
    N'st_auditoria',
    N'st_tickets',
    N'st_lotes',
    N'st_rangos',
    N'st_impresoras',
    N'st_configuracion',
    N'core_usuarios',
    N'core_especialidades',
    N'core_roles'
)
ORDER BY CASE name
    WHEN N'st_auditoria' THEN 1
    WHEN N'st_tickets' THEN 2
    WHEN N'st_lotes' THEN 3
    WHEN N'st_rangos' THEN 4
    WHEN N'st_impresoras' THEN 5
    WHEN N'st_configuracion' THEN 6
    WHEN N'core_usuarios' THEN 7
    WHEN N'core_especialidades' THEN 8
    WHEN N'core_roles' THEN 9
    ELSE 99
END;

EXEC sp_executesql @dropSql;
GO

SELECT * INTO dbo.core_roles FROM GESTION_INF.dbo.core_roles;
SELECT * INTO dbo.core_usuarios FROM GESTION_INF.dbo.core_usuarios;
SELECT * INTO dbo.core_especialidades FROM GESTION_INF.dbo.core_especialidades;
SELECT * INTO dbo.st_configuracion FROM GESTION_INF.dbo.st_configuracion;
SELECT * INTO dbo.st_impresoras FROM GESTION_INF.dbo.st_impresoras;
SELECT * INTO dbo.st_rangos FROM GESTION_INF.dbo.st_rangos;
SELECT * INTO dbo.st_lotes FROM GESTION_INF.dbo.st_lotes;
SELECT * INTO dbo.st_tickets FROM GESTION_INF.dbo.st_tickets;
SELECT * INTO dbo.st_auditoria FROM GESTION_INF.dbo.st_auditoria;
GO

CREATE UNIQUE INDEX UX_core_roles_id ON dbo.core_roles(id);
CREATE UNIQUE INDEX UX_core_usuarios_id ON dbo.core_usuarios(id);
CREATE UNIQUE INDEX UX_core_especialidades_id ON dbo.core_especialidades(id);
CREATE UNIQUE INDEX UX_st_rangos_id ON dbo.st_rangos(id);
CREATE UNIQUE INDEX UX_st_tickets_id ON dbo.st_tickets(id);
CREATE UNIQUE INDEX UX_st_lotes_id ON dbo.st_lotes(id);
CREATE UNIQUE INDEX UX_st_impresoras_id ON dbo.st_impresoras(id);
CREATE UNIQUE INDEX UX_st_auditoria_id ON dbo.st_auditoria(id);

CREATE UNIQUE INDEX UX_core_especialidades_nombre ON dbo.core_especialidades(nombre);
CREATE UNIQUE INDEX UX_st_configuracion_clave ON dbo.st_configuracion(clave);
CREATE UNIQUE INDEX UX_st_rangos_fecha_tipo ON dbo.st_rangos(fecha, tipo_ticket);
CREATE UNIQUE INDEX UX_st_tickets_uuid ON dbo.st_tickets(uuid);
CREATE UNIQUE INDEX UX_st_tickets_fecha_tipo_numero ON dbo.st_tickets(fecha, tipo_ticket, numero);

CREATE INDEX IX_core_especialidades_estado_orden ON dbo.core_especialidades(estado, orden);
CREATE INDEX IX_st_tickets_fecha ON dbo.st_tickets(fecha);
CREATE INDEX IX_st_tickets_codigo ON dbo.st_tickets(codigo);
CREATE INDEX IX_st_tickets_documento ON dbo.st_tickets(tipo_documento, numero_documento);
CREATE INDEX IX_st_tickets_especialidad ON dbo.st_tickets(especialidad_id);
CREATE INDEX IX_st_tickets_estado ON dbo.st_tickets(estado);
CREATE INDEX IX_st_tickets_estado_impresion ON dbo.st_tickets(estado_impresion);
CREATE INDEX IX_st_auditoria_creado_en ON dbo.st_auditoria(creado_en);
GO

SELECT 'core_roles' AS tabla, COUNT(*) AS total FROM dbo.core_roles
UNION ALL SELECT 'core_usuarios', COUNT(*) FROM dbo.core_usuarios
UNION ALL SELECT 'core_especialidades', COUNT(*) FROM dbo.core_especialidades
UNION ALL SELECT 'st_configuracion', COUNT(*) FROM dbo.st_configuracion
UNION ALL SELECT 'st_rangos', COUNT(*) FROM dbo.st_rangos
UNION ALL SELECT 'st_tickets', COUNT(*) FROM dbo.st_tickets
UNION ALL SELECT 'st_auditoria', COUNT(*) FROM dbo.st_auditoria;
GO
