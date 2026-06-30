USE sibd_firmas;

UPDATE core_especialidades
SET nombre = 'MEDICINA GENERAL', estado = 'activo', orden = 130, actualizado_en = NOW()
WHERE nombre = 'Medicina General';

UPDATE core_especialidades
SET nombre = 'GINECOLOGIA', estado = 'activo', orden = 80, actualizado_en = NOW()
WHERE nombre = 'Ginecologia';

UPDATE core_especialidades
SET nombre = 'OBSTETRICIA', estado = 'activo', orden = 170, actualizado_en = NOW()
WHERE nombre = 'Obstetricia';

UPDATE core_especialidades
SET nombre = 'ODONTOLOGIA', estado = 'activo', orden = 180, actualizado_en = NOW()
WHERE nombre = 'Odontologia';

UPDATE core_especialidades
SET estado = 'inactivo', actualizado_en = NOW()
WHERE nombre IN ('Pediatria', 'Emergencia', 'Triaje');
