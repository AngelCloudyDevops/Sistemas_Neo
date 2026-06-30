USE GESTION_INF;
GO

MERGE dbo.core_especialidades AS t
USING (SELECT nombre, estado, orden FROM (VALUES
    (N'ANESTESIOLOGIA', N'activo', 10),
    (N'CARDIOLOGIA', N'activo', 20),
    (N'CIRUGIA GENERAL', N'activo', 30),
    (N'CRED', N'activo', 40),
    (N'DERMATOLOGIA', N'activo', 50),
    (N'ENDOCRINOLOGIA', N'activo', 60),
    (N'GASTROENTEROLOGIA', N'activo', 70),
    (N'GINECOLOGIA', N'activo', 80),
    (N'INFECTOLOGIA', N'activo', 90),
    (N'MEDICINA ALTERNATIVA', N'activo', 100),
    (N'MEDICINA FAMILIAR', N'activo', 110),
    (N'MEDICINA FISICA', N'activo', 120),
    (N'MEDICINA GENERAL', N'activo', 130),
    (N'MEDICINA INTERNA', N'activo', 140),
    (N'NEUMOLOGIA', N'activo', 150),
    (N'NEUROLOGIA', N'activo', 160),
    (N'OBSTETRICIA', N'activo', 170),
    (N'ODONTOLOGIA', N'activo', 180),
    (N'OFTALMOLOGIA', N'activo', 190),
    (N'ONCOLOGIA', N'activo', 200),
    (N'OTORRINO', N'activo', 210),
    (N'PEDIATRIA GENERAL', N'activo', 220),
    (N'PSICOLOGIA', N'activo', 230),
    (N'PSIQUIATRIA', N'activo', 240),
    (N'REUMATOLOGIA', N'activo', 250),
    (N'TRAUMATOLOGIA', N'activo', 260),
    (N'UROLOGIA', N'activo', 270)
) AS v(nombre, estado, orden)) AS s
ON t.nombre = s.nombre
WHEN MATCHED THEN
    UPDATE SET estado = s.estado, orden = s.orden, actualizado_en = GETDATE()
WHEN NOT MATCHED THEN
    INSERT (nombre, estado, orden, creado_en) VALUES (s.nombre, s.estado, s.orden, GETDATE());
GO

UPDATE dbo.core_especialidades
SET nombre = N'MEDICINA GENERAL', estado = N'activo', orden = 130, actualizado_en = GETDATE()
WHERE nombre = N'Medicina General';

UPDATE dbo.core_especialidades
SET nombre = N'GINECOLOGIA', estado = N'activo', orden = 80, actualizado_en = GETDATE()
WHERE nombre = N'Ginecologia';

UPDATE dbo.core_especialidades
SET nombre = N'OBSTETRICIA', estado = N'activo', orden = 170, actualizado_en = GETDATE()
WHERE nombre = N'Obstetricia';

UPDATE dbo.core_especialidades
SET nombre = N'ODONTOLOGIA', estado = N'activo', orden = 180, actualizado_en = GETDATE()
WHERE nombre = N'Odontologia';

UPDATE dbo.core_especialidades
SET estado = N'inactivo', actualizado_en = GETDATE()
WHERE nombre IN (N'Pediatria', N'Emergencia', N'Triaje');
GO
