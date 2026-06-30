SET @add_internal_specialty_column = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'core_especialidades'
              AND COLUMN_NAME = 'es_interno'
        ),
        'SELECT 1',
        'ALTER TABLE core_especialidades ADD COLUMN es_interno TINYINT(1) NOT NULL DEFAULT 0 AFTER orden'
    )
);
PREPARE stmt FROM @add_internal_specialty_column;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE core_especialidades
SET es_interno = 1,
    estado = 'activo',
    actualizado_en = NOW()
WHERE UPPER(nombre) = 'LOTE / REIMPRESION';

SET @add_internal_specialty_index = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'core_especialidades'
              AND INDEX_NAME = 'idx_core_especialidades_visibles'
        ),
        'SELECT 1',
        'CREATE INDEX idx_core_especialidades_visibles ON core_especialidades (estado, es_interno, nombre)'
    )
);
PREPARE stmt FROM @add_internal_specialty_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
