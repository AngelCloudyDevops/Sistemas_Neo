IF OBJECT_ID('dbo.st_cupo_reservas', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.st_cupo_reservas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_cupo_reservas PRIMARY KEY,
        ticket_id BIGINT NOT NULL,
        core_especialidad_id BIGINT NOT NULL,
        sigh_especialidad_id INT NOT NULL,
        fecha DATE NOT NULL,
        estado VARCHAR(20) NOT NULL CONSTRAINT DF_st_cupo_reservas_estado DEFAULT ('reservada'),
        reservado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_cupo_reservas_reservado DEFAULT (SYSDATETIME()),
        liberado_en DATETIME2(0) NULL,
        liberado_motivo VARCHAR(40) NULL,
        CONSTRAINT UQ_st_cupo_reservas_ticket UNIQUE (ticket_id),
        CONSTRAINT CK_st_cupo_reservas_estado CHECK (estado IN ('reservada', 'liberada'))
    );

    CREATE INDEX IX_st_cupo_reservas_activa
    ON dbo.st_cupo_reservas(fecha, sigh_especialidad_id, estado);
END;
