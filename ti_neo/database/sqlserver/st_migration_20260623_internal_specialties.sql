IF COL_LENGTH('dbo.core_especialidades', 'es_interno') IS NULL
BEGIN
    ALTER TABLE dbo.core_especialidades
    ADD es_interno BIT NOT NULL CONSTRAINT DF_core_especialidades_es_interno DEFAULT (0);
END;

EXEC(N'
UPDATE dbo.core_especialidades
SET es_interno = 1,
    estado = ''activo'',
    actualizado_en = SYSDATETIME()
WHERE UPPER(nombre) = N''LOTE / REIMPRESION'';
');

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'IX_core_especialidades_visibles'
      AND object_id = OBJECT_ID('dbo.core_especialidades')
)
BEGIN
    EXEC(N'CREATE INDEX IX_core_especialidades_visibles
    ON dbo.core_especialidades(estado, es_interno, nombre);');
END;
