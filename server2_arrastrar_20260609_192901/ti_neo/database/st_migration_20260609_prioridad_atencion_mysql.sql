ALTER TABLE st_tickets
    ADD COLUMN prioridad_atencion VARCHAR(20) NULL AFTER tipo_ticket;

UPDATE st_tickets
SET prioridad_atencion = CASE
    WHEN tipo_ticket IN ('Preferencial', 'Normal') THEN tipo_ticket
    ELSE prioridad_atencion
END
WHERE prioridad_atencion IS NULL;
