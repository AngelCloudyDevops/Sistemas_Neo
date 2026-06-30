USE sibd_firmas;

ALTER TABLE st_tickets
    ADD COLUMN estado_impresion ENUM('pendiente', 'impreso', 'cancelado') NOT NULL DEFAULT 'pendiente' AFTER estado,
    ADD KEY idx_st_tickets_estado_impresion (estado_impresion);

UPDATE st_tickets
SET estado_impresion = 'impreso'
WHERE estado_impresion = 'pendiente';
