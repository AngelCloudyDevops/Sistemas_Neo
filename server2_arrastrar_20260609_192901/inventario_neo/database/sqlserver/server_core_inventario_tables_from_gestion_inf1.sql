USE [GESTION_INF];
GO

SET NOCOUNT ON;

IF DB_ID(N'GESTION_INF1') IS NULL
BEGIN
    THROW 51000, 'No existe GESTION_INF1 como origen de migracion.', 1;
END;

IF OBJECT_ID(N'GESTION_INF1.dbo.core_equipos', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_impresoras', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_antivirus', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_sistemas_operativos', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_equipo_hardware', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_equipo_software', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_equipo_perifericos', N'U') IS NULL
   OR OBJECT_ID(N'GESTION_INF1.dbo.core_historial_equipos', N'U') IS NULL
BEGIN
    THROW 51001, 'Faltan tablas core de inventario o detalle en GESTION_INF1.', 1;
END;

IF OBJECT_ID(N'dbo.core_unidades', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_unidades (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_unidades PRIMARY KEY,
        codigo NVARCHAR(30) NULL,
        nombre NVARCHAR(180) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_unidades_estado DEFAULT 'activo',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_unidades_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_unidades ON;
INSERT INTO dbo.core_unidades (id, codigo, nombre, estado, creado_en, actualizado_en)
SELECT id, codigo, nombre, estado, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_unidades AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_unidades AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_unidades OFF;

IF OBJECT_ID(N'dbo.core_areas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_areas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_areas PRIMARY KEY,
        unidad_id BIGINT NULL,
        codigo NVARCHAR(30) NULL,
        nombre NVARCHAR(180) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_areas_estado DEFAULT 'activo',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_areas_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_areas ON;
INSERT INTO dbo.core_areas (id, unidad_id, codigo, nombre, estado, creado_en, actualizado_en)
SELECT id, unidad_id, codigo, nombre, estado, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_areas AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_areas AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_areas OFF;

IF OBJECT_ID(N'dbo.core_oficinas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_oficinas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_oficinas PRIMARY KEY,
        area_id BIGINT NULL,
        codigo NVARCHAR(30) NULL,
        nombre NVARCHAR(180) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_oficinas_estado DEFAULT 'activo',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_oficinas_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_oficinas ON;
INSERT INTO dbo.core_oficinas (id, area_id, codigo, nombre, estado, creado_en, actualizado_en)
SELECT id, area_id, codigo, nombre, estado, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_oficinas AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_oficinas AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_oficinas OFF;

IF OBJECT_ID(N'dbo.core_estados_activo', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_estados_activo (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_estados_activo PRIMARY KEY,
        nombre NVARCHAR(120) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_estados_activo_estado DEFAULT 'activo'
    );
END;

SET IDENTITY_INSERT dbo.core_estados_activo ON;
INSERT INTO dbo.core_estados_activo (id, nombre, estado)
SELECT id, nombre, estado
FROM GESTION_INF1.dbo.core_estados_activo AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_estados_activo AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_estados_activo OFF;

IF OBJECT_ID(N'dbo.core_tipos_equipo', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_tipos_equipo (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_tipos_equipo PRIMARY KEY,
        nombre NVARCHAR(120) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_tipos_equipo_estado DEFAULT 'activo'
    );
END;

SET IDENTITY_INSERT dbo.core_tipos_equipo ON;
INSERT INTO dbo.core_tipos_equipo (id, nombre, estado)
SELECT id, nombre, estado
FROM GESTION_INF1.dbo.core_tipos_equipo AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_tipos_equipo AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_tipos_equipo OFF;

IF OBJECT_ID(N'dbo.core_tipos_impresora', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_tipos_impresora (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_tipos_impresora PRIMARY KEY,
        nombre NVARCHAR(120) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_tipos_impresora_estado DEFAULT 'activo'
    );
END;

SET IDENTITY_INSERT dbo.core_tipos_impresora ON;
INSERT INTO dbo.core_tipos_impresora (id, nombre, estado)
SELECT id, nombre, estado
FROM GESTION_INF1.dbo.core_tipos_impresora AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_tipos_impresora AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_tipos_impresora OFF;

IF OBJECT_ID(N'dbo.core_marcas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_marcas (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_marcas PRIMARY KEY,
        nombre NVARCHAR(120) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_marcas_estado DEFAULT 'activo',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_marcas_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_marcas ON;
INSERT INTO dbo.core_marcas (id, nombre, estado, creado_en, actualizado_en)
SELECT id, nombre, estado, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_marcas AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_marcas AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_marcas OFF;

IF OBJECT_ID(N'dbo.core_modelos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_modelos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_modelos PRIMARY KEY,
        marca_id BIGINT NULL,
        nombre NVARCHAR(150) NOT NULL,
        tipo NVARCHAR(80) NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_modelos_estado DEFAULT 'activo',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_modelos_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_modelos ON;
INSERT INTO dbo.core_modelos (id, marca_id, nombre, tipo, estado, creado_en, actualizado_en)
SELECT id, marca_id, nombre, tipo, estado, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_modelos AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_modelos AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_modelos OFF;

IF OBJECT_ID(N'dbo.core_personal_asignaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_personal_asignaciones (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_personal_asignaciones PRIMARY KEY,
        personal_id BIGINT NOT NULL,
        unidad_id BIGINT NULL,
        oficina_id BIGINT NULL,
        area_id BIGINT NULL,
        cargo_id BIGINT NULL,
        modalidad_id BIGINT NULL,
        resolucion_id BIGINT NULL,
        fecha_inicio DATE NULL,
        fecha_fin DATE NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_personal_asignaciones_estado DEFAULT 'actual',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_personal_asignaciones_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_personal_asignaciones ON;
INSERT INTO dbo.core_personal_asignaciones (
    id, personal_id, unidad_id, oficina_id, area_id, cargo_id, modalidad_id,
    resolucion_id, fecha_inicio, fecha_fin, estado, creado_en, actualizado_en
)
SELECT
    id, personal_id, unidad_id, oficina_id, area_id, cargo_id, modalidad_id,
    resolucion_id, fecha_inicio, fecha_fin, estado, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_personal_asignaciones AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_personal_asignaciones AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_personal_asignaciones OFF;

IF OBJECT_ID(N'dbo.core_equipos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_equipos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_equipos PRIMARY KEY,
        nombre_pc NVARCHAR(120) NOT NULL,
        numero_ip NVARCHAR(45) NULL,
        mac_address NVARCHAR(30) NULL,
        unidad_id BIGINT NULL,
        oficina_id BIGINT NULL,
        area_id BIGINT NULL,
        usuario_actual_id BIGINT NULL,
        cod_patrimonio NVARCHAR(80) NULL,
        fecha_adquisicion DATE NULL,
        fecha_registro DATE NULL,
        estado_id BIGINT NULL,
        observacion NVARCHAR(MAX) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_equipos_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_equipos ON;
INSERT INTO dbo.core_equipos (
    id, nombre_pc, numero_ip, mac_address, unidad_id, oficina_id, area_id,
    usuario_actual_id, cod_patrimonio, fecha_adquisicion, fecha_registro,
    estado_id, observacion, creado_en, actualizado_en
)
SELECT
    id, nombre_pc, numero_ip, mac_address, unidad_id, oficina_id, area_id,
    usuario_actual_id, cod_patrimonio, fecha_adquisicion, fecha_registro,
    estado_id, observacion, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_equipos AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_equipos AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_equipos OFF;

IF OBJECT_ID(N'dbo.core_impresoras', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_impresoras (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_impresoras PRIMARY KEY,
        unidad_id BIGINT NULL,
        area_id BIGINT NULL,
        ubicacion NVARCHAR(180) NULL,
        codpat NVARCHAR(80) NULL,
        cod_inv NVARCHAR(80) NULL,
        tipo_equipo_id BIGINT NULL,
        marca_id BIGINT NULL,
        modelo_id BIGINT NULL,
        tipo_impresora_id BIGINT NULL,
        serie NVARCHAR(120) NULL,
        observacion NVARCHAR(MAX) NULL,
        estado_id BIGINT NULL,
        nota NVARCHAR(MAX) NULL,
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_impresoras_creado DEFAULT SYSDATETIME(),
        actualizado_en DATETIME2(0) NULL
    );
END;

SET IDENTITY_INSERT dbo.core_impresoras ON;
INSERT INTO dbo.core_impresoras (
    id, unidad_id, area_id, ubicacion, codpat, cod_inv, tipo_equipo_id,
    marca_id, modelo_id, tipo_impresora_id, serie, observacion, estado_id,
    nota, creado_en, actualizado_en
)
SELECT
    id, unidad_id, area_id, ubicacion, codpat, cod_inv, tipo_equipo_id,
    marca_id, modelo_id, tipo_impresora_id, serie, observacion, estado_id,
    nota, creado_en, actualizado_en
FROM GESTION_INF1.dbo.core_impresoras AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_impresoras AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_impresoras OFF;

IF OBJECT_ID(N'dbo.core_antivirus', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_antivirus (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_antivirus PRIMARY KEY,
        nombre NVARCHAR(150) NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_antivirus_estado DEFAULT 'activo'
    );
END;

SET IDENTITY_INSERT dbo.core_antivirus ON;
INSERT INTO dbo.core_antivirus (id, nombre, estado)
SELECT id, nombre, estado
FROM GESTION_INF1.dbo.core_antivirus AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_antivirus AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_antivirus OFF;

IF OBJECT_ID(N'dbo.core_sistemas_operativos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_sistemas_operativos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_sistemas_operativos PRIMARY KEY,
        nombre NVARCHAR(150) NOT NULL,
        version NVARCHAR(80) NULL,
        arquitectura NVARCHAR(40) NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_sistemas_operativos_estado DEFAULT 'activo'
    );
END;

SET IDENTITY_INSERT dbo.core_sistemas_operativos ON;
INSERT INTO dbo.core_sistemas_operativos (id, nombre, version, arquitectura, estado)
SELECT id, nombre, version, arquitectura, estado
FROM GESTION_INF1.dbo.core_sistemas_operativos AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_sistemas_operativos AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_sistemas_operativos OFF;

IF OBJECT_ID(N'dbo.core_equipo_hardware', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_equipo_hardware (
        equipo_id BIGINT NOT NULL CONSTRAINT PK_core_equipo_hardware PRIMARY KEY,
        marca_cpu_id BIGINT NULL,
        modelo_cpu_id BIGINT NULL,
        serie_cpu NVARCHAR(120) NULL,
        estado_cpu_id BIGINT NULL,
        marca_mainboard_id BIGINT NULL,
        modelo_mainboard_id BIGINT NULL,
        marca_procesador_id BIGINT NULL,
        procesador NVARCHAR(180) NULL,
        velocidad_procesador NVARCHAR(80) NULL,
        generacion_procesador NVARCHAR(80) NULL,
        tipo_memoria NVARCHAR(80) NULL,
        total_memoria_gb DECIMAL(18,2) NULL,
        marca_disco_id BIGINT NULL,
        capacidad_disco NVARCHAR(80) NULL,
        modelo_disco_id BIGINT NULL,
        tipo_disco NVARCHAR(80) NULL,
        marca_video_id BIGINT NULL,
        modelo_video_id BIGINT NULL,
        actualizado_en DATETIME2(0) NULL
    );
END;

INSERT INTO dbo.core_equipo_hardware (
    equipo_id, marca_cpu_id, modelo_cpu_id, serie_cpu, estado_cpu_id,
    marca_mainboard_id, modelo_mainboard_id, marca_procesador_id,
    procesador, velocidad_procesador, generacion_procesador, tipo_memoria,
    total_memoria_gb, marca_disco_id, capacidad_disco, modelo_disco_id,
    tipo_disco, marca_video_id, modelo_video_id, actualizado_en
)
SELECT
    equipo_id, marca_cpu_id, modelo_cpu_id, serie_cpu, estado_cpu_id,
    marca_mainboard_id, modelo_mainboard_id, marca_procesador_id,
    procesador, velocidad_procesador, generacion_procesador, tipo_memoria,
    total_memoria_gb, marca_disco_id, capacidad_disco, modelo_disco_id,
    tipo_disco, marca_video_id, modelo_video_id, actualizado_en
FROM GESTION_INF1.dbo.core_equipo_hardware AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_equipo_hardware AS dst WHERE dst.equipo_id = src.equipo_id);

IF OBJECT_ID(N'dbo.core_equipo_software', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_equipo_software (
        equipo_id BIGINT NOT NULL CONSTRAINT PK_core_equipo_software PRIMARY KEY,
        sistema_operativo_id BIGINT NULL,
        licencia_so NVARCHAR(150) NULL,
        key_so NVARCHAR(255) NULL,
        software_oficina NVARCHAR(180) NULL,
        key_software_oficina NVARCHAR(255) NULL,
        internet NVARCHAR(80) NULL,
        antivirus_id BIGINT NULL,
        actualizado_en DATETIME2(0) NULL
    );
END;

INSERT INTO dbo.core_equipo_software (
    equipo_id, sistema_operativo_id, licencia_so, key_so, software_oficina,
    key_software_oficina, internet, antivirus_id, actualizado_en
)
SELECT
    equipo_id, sistema_operativo_id, licencia_so, key_so, software_oficina,
    key_software_oficina, internet, antivirus_id, actualizado_en
FROM GESTION_INF1.dbo.core_equipo_software AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_equipo_software AS dst WHERE dst.equipo_id = src.equipo_id);

IF OBJECT_ID(N'dbo.core_equipo_perifericos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_equipo_perifericos (
        equipo_id BIGINT NOT NULL CONSTRAINT PK_core_equipo_perifericos PRIMARY KEY,
        marca_teclado_id BIGINT NULL,
        modelo_teclado_id BIGINT NULL,
        serie_teclado NVARCHAR(120) NULL,
        cod_teclado NVARCHAR(80) NULL,
        estado_teclado_id BIGINT NULL,
        marca_mouse_id BIGINT NULL,
        modelo_mouse_id BIGINT NULL,
        serie_mouse NVARCHAR(120) NULL,
        estado_mouse_id BIGINT NULL,
        marca_monitor_id BIGINT NULL,
        tipo_monitor NVARCHAR(80) NULL,
        modelo_monitor_id BIGINT NULL,
        monitor_pulgadas NVARCHAR(40) NULL,
        serie_monitor NVARCHAR(120) NULL,
        cod_monitor NVARCHAR(80) NULL,
        estado_monitor_id BIGINT NULL,
        marca_estabilizador_id BIGINT NULL,
        modelo_estabilizador_id BIGINT NULL,
        serie_estabilizador NVARCHAR(120) NULL,
        cod_estabilizador NVARCHAR(80) NULL,
        estado_estabilizador_id BIGINT NULL,
        marca_grabadora_dvd_id BIGINT NULL,
        tipo_grabadora_dvd NVARCHAR(120) NULL,
        velocidad_grabadora NVARCHAR(80) NULL,
        actualizado_en DATETIME2(0) NULL
    );
END;

INSERT INTO dbo.core_equipo_perifericos (
    equipo_id, marca_teclado_id, modelo_teclado_id, serie_teclado, cod_teclado,
    estado_teclado_id, marca_mouse_id, modelo_mouse_id, serie_mouse, estado_mouse_id,
    marca_monitor_id, tipo_monitor, modelo_monitor_id, monitor_pulgadas, serie_monitor,
    cod_monitor, estado_monitor_id, marca_estabilizador_id, modelo_estabilizador_id,
    serie_estabilizador, cod_estabilizador, estado_estabilizador_id, marca_grabadora_dvd_id,
    tipo_grabadora_dvd, velocidad_grabadora, actualizado_en
)
SELECT
    equipo_id, marca_teclado_id, modelo_teclado_id, serie_teclado, cod_teclado,
    estado_teclado_id, marca_mouse_id, modelo_mouse_id, serie_mouse, estado_mouse_id,
    marca_monitor_id, tipo_monitor, modelo_monitor_id, monitor_pulgadas, serie_monitor,
    cod_monitor, estado_monitor_id, marca_estabilizador_id, modelo_estabilizador_id,
    serie_estabilizador, cod_estabilizador, estado_estabilizador_id, marca_grabadora_dvd_id,
    tipo_grabadora_dvd, velocidad_grabadora, actualizado_en
FROM GESTION_INF1.dbo.core_equipo_perifericos AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_equipo_perifericos AS dst WHERE dst.equipo_id = src.equipo_id);

IF OBJECT_ID(N'dbo.core_historial_equipos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.core_historial_equipos (
        id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_historial_equipos PRIMARY KEY,
        equipo_id BIGINT NOT NULL,
        personal_id BIGINT NULL,
        fecha_asignacion DATE NOT NULL,
        fecha_retiro DATE NULL,
        motivo NVARCHAR(255) NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_core_historial_equipos_estado DEFAULT 'actual',
        creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_historial_equipos_creado DEFAULT SYSDATETIME()
    );
END;

SET IDENTITY_INSERT dbo.core_historial_equipos ON;
INSERT INTO dbo.core_historial_equipos (id, equipo_id, personal_id, fecha_asignacion, fecha_retiro, motivo, estado, creado_en)
SELECT id, equipo_id, personal_id, fecha_asignacion, fecha_retiro, motivo, estado, creado_en
FROM GESTION_INF1.dbo.core_historial_equipos AS src
WHERE NOT EXISTS (SELECT 1 FROM dbo.core_historial_equipos AS dst WHERE dst.id = src.id);
SET IDENTITY_INSERT dbo.core_historial_equipos OFF;

SELECT 'core_unidades' AS tabla, COUNT(*) AS filas FROM dbo.core_unidades
UNION ALL SELECT 'core_areas', COUNT(*) FROM dbo.core_areas
UNION ALL SELECT 'core_oficinas', COUNT(*) FROM dbo.core_oficinas
UNION ALL SELECT 'core_estados_activo', COUNT(*) FROM dbo.core_estados_activo
UNION ALL SELECT 'core_tipos_equipo', COUNT(*) FROM dbo.core_tipos_equipo
UNION ALL SELECT 'core_tipos_impresora', COUNT(*) FROM dbo.core_tipos_impresora
UNION ALL SELECT 'core_marcas', COUNT(*) FROM dbo.core_marcas
UNION ALL SELECT 'core_modelos', COUNT(*) FROM dbo.core_modelos
UNION ALL SELECT 'core_personal_asignaciones', COUNT(*) FROM dbo.core_personal_asignaciones
UNION ALL SELECT 'core_equipos', COUNT(*) FROM dbo.core_equipos
UNION ALL SELECT 'core_impresoras', COUNT(*) FROM dbo.core_impresoras
UNION ALL SELECT 'core_antivirus', COUNT(*) FROM dbo.core_antivirus
UNION ALL SELECT 'core_sistemas_operativos', COUNT(*) FROM dbo.core_sistemas_operativos
UNION ALL SELECT 'core_equipo_hardware', COUNT(*) FROM dbo.core_equipo_hardware
UNION ALL SELECT 'core_equipo_software', COUNT(*) FROM dbo.core_equipo_software
UNION ALL SELECT 'core_equipo_perifericos', COUNT(*) FROM dbo.core_equipo_perifericos
UNION ALL SELECT 'core_historial_equipos', COUNT(*) FROM dbo.core_historial_equipos;
GO
