IF COL_LENGTH('dbo.st_tickets', 'prioridad_atencion') IS NULL
BEGIN
    ALTER TABLE dbo.st_tickets ADD prioridad_atencion VARCHAR(20) NULL;
END
GO

UPDATE dbo.st_tickets
SET prioridad_atencion = CASE
    WHEN tipo_ticket IN ('Preferencial', 'Normal') THEN tipo_ticket
    ELSE prioridad_atencion
END
WHERE prioridad_atencion IS NULL;
GO
