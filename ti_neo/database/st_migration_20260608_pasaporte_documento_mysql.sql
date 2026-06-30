-- Agrega PASAPORTE como tipo de documento permitido en MySQL / MariaDB.

ALTER TABLE st_tickets
    MODIFY tipo_documento ENUM('DNI', 'CE', 'PASAPORTE') NOT NULL;
