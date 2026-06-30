IF COL_LENGTH('dbo.core_especialidades', 'sigh_especialidad_id') IS NULL
BEGIN
    ALTER TABLE dbo.core_especialidades ADD sigh_especialidad_id INT NULL;
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_core_especialidades_sigh_id'
      AND object_id = OBJECT_ID('dbo.core_especialidades')
)
BEGIN
    EXEC(N'CREATE UNIQUE INDEX UX_core_especialidades_sigh_id
        ON dbo.core_especialidades(sigh_especialidad_id)
        WHERE sigh_especialidad_id IS NOT NULL;');
END;

EXEC(N'
UPDATE dbo.core_especialidades
SET nombre = N''OTORRINOLARINGOLOGIA'',
    sigh_especialidad_id = 46,
    actualizado_en = SYSDATETIME()
WHERE nombre = N''OTORRINO'';

UPDATE dbo.core_especialidades
SET nombre = N''ODONTOESTOMATOLOGIA'',
    sigh_especialidad_id = 53,
    actualizado_en = SYSDATETIME()
WHERE nombre = N''ODONTOLOGIA'';

UPDATE dbo.core_especialidades
SET nombre = N''MEDICINA FISICA Y REHABILITACION'',
    sigh_especialidad_id = 51,
    actualizado_en = SYSDATETIME()
WHERE nombre = N''MEDICINA FISICA'';

MERGE dbo.core_especialidades AS t
USING (SELECT sigh_especialidad_id, nombre FROM (VALUES
    (179, N''ANESTESIOLOGIA''),
    (1, N''CARDIOLOGIA''),
    (38, N''CIRUGIA GENERAL''),
    (73, N''CRED''),
    (3, N''DERMATOLOGIA''),
    (4, N''ENDOCRINOLOGIA''),
    (5, N''GASTROENTEROLOGIA''),
    (204, N''INFECTOLOGIA''),
    (246, N''MEDICINA ALTERNATIVA''),
    (9, N''MEDICINA INTERNA''),
    (13, N''NEUMOLOGIA''),
    (14, N''NEUROLOGIA''),
    (44, N''OFTALMOLOGIA''),
    (202, N''ONCOLOGIA''),
    (27, N''PEDIATRIA GENERAL''),
    (54, N''PSICOLOGIA''),
    (16, N''PSIQUIATRIA''),
    (17, N''REUMATOLOGIA''),
    (159, N''TRAUMATOLOGIA''),
    (160, N''UROLOGIA'')
) AS v(sigh_especialidad_id, nombre)) AS s
ON t.nombre = s.nombre
WHEN MATCHED THEN
    UPDATE SET sigh_especialidad_id = s.sigh_especialidad_id,
               actualizado_en = SYSDATETIME();

MERGE dbo.core_especialidades AS t
USING (SELECT sigh_especialidad_id, nombre, orden FROM (VALUES
    (78, N''PLANIFICACION FAMILIAR'', 310),
    (245, N''CDJ'', 320),
    (207, N''GINECO OBSTETRICIA'', 330),
    (1247, N''PROC MEDICINA FISICA Y REHAB'', 340),
    (184, N''PROC REUMATOLOGIA'', 350),
    (244, N''ODONTOPEDIATRIA'', 360),
    (224, N''TO CARDIOLOGIA'', 370),
    (163, N''NEONATOLOGIA'', 380),
    (167, N''PROC CARDIOLOGIA'', 390),
    (208, N''PROC GASTRO'', 400)
) AS v(sigh_especialidad_id, nombre, orden)) AS s
ON t.sigh_especialidad_id = s.sigh_especialidad_id OR t.nombre = s.nombre
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre,
               estado = ''activo'',
               actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (nombre, estado, orden, sigh_especialidad_id, creado_en)
    VALUES (s.nombre, ''activo'', s.orden, s.sigh_especialidad_id, SYSDATETIME());
');
