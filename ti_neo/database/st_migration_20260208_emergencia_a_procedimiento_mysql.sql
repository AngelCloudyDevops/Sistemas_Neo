-- Reemplaza Emergencia por Procedimiento (MySQL / MariaDB).
USE sibd_firmas;

UPDATE st_rangos SET tipo_ticket = 'Procedimiento' WHERE tipo_ticket = 'Emergencia';
UPDATE st_tickets SET tipo_ticket = 'Procedimiento' WHERE tipo_ticket = 'Emergencia';
UPDATE st_lotes SET tipo_ticket = 'Procedimiento' WHERE tipo_ticket = 'Emergencia';

ALTER TABLE st_rangos
    MODIFY tipo_ticket ENUM('Preferencial', 'Normal', 'Procedimiento') NOT NULL;

ALTER TABLE st_tickets
    MODIFY tipo_ticket ENUM('Preferencial', 'Normal', 'Procedimiento') NOT NULL;

ALTER TABLE st_lotes
    MODIFY tipo_ticket ENUM('Preferencial', 'Normal', 'Procedimiento') NOT NULL;
