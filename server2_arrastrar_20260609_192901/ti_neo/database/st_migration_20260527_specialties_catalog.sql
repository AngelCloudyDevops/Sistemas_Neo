USE sibd_firmas;

INSERT INTO core_especialidades (nombre, estado, orden) VALUES
('ANESTESIOLOGIA', 'activo', 10),
('CARDIOLOGIA', 'activo', 20),
('CIRUGIA GENERAL', 'activo', 30),
('CRED', 'activo', 40),
('DERMATOLOGIA', 'activo', 50),
('ENDOCRINOLOGIA', 'activo', 60),
('GASTROENTEROLOGIA', 'activo', 70),
('GINECOLOGIA', 'activo', 80),
('INFECTOLOGIA', 'activo', 90),
('MEDICINA ALTERNATIVA', 'activo', 100),
('MEDICINA FAMILIAR', 'activo', 110),
('MEDICINA FISICA', 'activo', 120),
('MEDICINA GENERAL', 'activo', 130),
('MEDICINA INTERNA', 'activo', 140),
('NEUMOLOGIA', 'activo', 150),
('NEUROLOGIA', 'activo', 160),
('OBSTETRICIA', 'activo', 170),
('ODONTOLOGIA', 'activo', 180),
('OFTALMOLOGIA', 'activo', 190),
('ONCOLOGIA', 'activo', 200),
('OTORRINO', 'activo', 210),
('PEDIATRIA GENERAL', 'activo', 220),
('PSICOLOGIA', 'activo', 230),
('PSIQUIATRIA', 'activo', 240),
('REUMATOLOGIA', 'activo', 250),
('TRAUMATOLOGIA', 'activo', 260),
('UROLOGIA', 'activo', 270)
ON DUPLICATE KEY UPDATE
    estado = VALUES(estado),
    orden = VALUES(orden),
    actualizado_en = NOW();
