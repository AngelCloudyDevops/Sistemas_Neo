/*
  Portal NEO - clases visuales por sistema.
  Ejecutar en GESTION_INF o GESTION_INF1 segun ambiente.

  Clases permitidas por convencion:
  - core: Portal NEO, GS NEO, accesos centrales.
  - atencion: tickets, citas, turnero, pantalla.
  - operaciones: inventario, equipos, impresoras, mantenimiento.
  - soporte: mesa de ayuda, incidencias, soporte tecnico.
  - documentos: firmas, certificados, archivos documentales.
*/

IF OBJECT_ID('dbo.neo_sistemas', 'U') IS NULL
    THROW 50001, 'Falta dbo.neo_sistemas. Ejecuta neo_schema.sql primero.', 1;

IF COL_LENGTH('dbo.neo_sistemas', 'clase_visual') IS NULL
BEGIN
    ALTER TABLE dbo.neo_sistemas
    ADD clase_visual VARCHAR(40) NOT NULL CONSTRAINT DF_neo_sistemas_clase_visual DEFAULT ('core');
END;

UPDATE dbo.neo_sistemas
SET clase_visual = CASE codigo
        WHEN 'gs' THEN 'core'
        WHEN 'tickets' THEN 'atencion'
        WHEN 'soporte' THEN 'soporte'
        WHEN 'inventario' THEN 'operaciones'
        WHEN 'firmas' THEN 'documentos'
        ELSE clase_visual
    END,
    actualizado_en = SYSDATETIME()
WHERE codigo IN ('gs', 'tickets', 'soporte', 'inventario', 'firmas');

SELECT codigo, nombre, clase_visual
FROM dbo.neo_sistemas
ORDER BY orden, nombre;
