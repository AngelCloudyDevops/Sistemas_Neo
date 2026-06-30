-- Agrega PASAPORTE como tipo de documento permitido en SQL Server.
-- Ejecutar en GESTION_INF1 primero; luego en produccion cuando se valide.

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_st_tickets_documento'
      AND parent_object_id = OBJECT_ID('dbo.st_tickets')
)
BEGIN
    ALTER TABLE dbo.st_tickets DROP CONSTRAINT CK_st_tickets_documento;
END;

ALTER TABLE dbo.st_tickets
ADD CONSTRAINT CK_st_tickets_documento
CHECK (tipo_documento IN ('DNI', 'CE', 'PASAPORTE'));
