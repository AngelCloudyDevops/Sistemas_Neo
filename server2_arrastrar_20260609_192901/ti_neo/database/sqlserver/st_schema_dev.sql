USE GESTION_INF_DEV;
GO

IF OBJECT_ID('dbo.st_auditoria', 'U') IS NOT NULL DROP TABLE dbo.st_auditoria;
IF OBJECT_ID('dbo.st_tickets', 'U') IS NOT NULL DROP TABLE dbo.st_tickets;
IF OBJECT_ID('dbo.st_lotes', 'U') IS NOT NULL DROP TABLE dbo.st_lotes;
IF OBJECT_ID('dbo.st_rangos', 'U') IS NOT NULL DROP TABLE dbo.st_rangos;
IF OBJECT_ID('dbo.st_impresoras', 'U') IS NOT NULL DROP TABLE dbo.st_impresoras;
IF OBJECT_ID('dbo.st_usuario_perfiles', 'U') IS NOT NULL DROP TABLE dbo.st_usuario_perfiles;
IF OBJECT_ID('dbo.st_configuracion', 'U') IS NOT NULL DROP TABLE dbo.st_configuracion;
IF OBJECT_ID('dbo.core_especialidades', 'U') IS NOT NULL DROP TABLE dbo.core_especialidades;
IF OBJECT_ID('dbo.core_usuarios', 'U') IS NOT NULL DROP TABLE dbo.core_usuarios;
IF OBJECT_ID('dbo.core_roles', 'U') IS NOT NULL DROP TABLE dbo.core_roles;
GO

CREATE TABLE dbo.core_roles (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_roles PRIMARY KEY,
    nombre NVARCHAR(50) NOT NULL,
    descripcion NVARCHAR(255) NULL,
    estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_roles_estado DEFAULT ('activo'),
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_roles_creado DEFAULT (SYSDATETIME()),
    actualizado_en DATETIME2(0) NULL,
    CONSTRAINT UQ_core_roles_nombre UNIQUE (nombre),
    CONSTRAINT CK_core_roles_estado CHECK (estado IN ('activo', 'inactivo'))
);
GO

CREATE TABLE dbo.core_usuarios (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_usuarios PRIMARY KEY,
    rol_id BIGINT NOT NULL,
    dni VARCHAR(20) NULL,
    nombres NVARCHAR(120) NOT NULL,
    apellidos NVARCHAR(120) NOT NULL,
    email NVARCHAR(150) NOT NULL,
    password_hash NVARCHAR(255) NOT NULL,
    must_change_password BIT NOT NULL CONSTRAINT DF_core_usuarios_must_change DEFAULT (0),
    area NVARCHAR(120) NULL,
    cargo NVARCHAR(120) NULL,
    telefono VARCHAR(30) NULL,
    origen VARCHAR(50) NOT NULL CONSTRAINT DF_core_usuarios_origen DEFAULT ('local'),
    usuario_externo_id NVARCHAR(100) NULL,
    ultimo_login_en DATETIME2(0) NULL,
    estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_usuarios_estado DEFAULT ('activo'),
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuarios_creado DEFAULT (SYSDATETIME()),
    actualizado_en DATETIME2(0) NULL,
    CONSTRAINT FK_core_usuarios_roles FOREIGN KEY (rol_id) REFERENCES dbo.core_roles(id),
    CONSTRAINT UQ_core_usuarios_email UNIQUE (email),
    CONSTRAINT UQ_core_usuarios_dni UNIQUE (dni),
    CONSTRAINT CK_core_usuarios_estado CHECK (estado IN ('activo', 'inactivo', 'bloqueado'))
);
GO

CREATE INDEX IX_core_usuarios_rol ON dbo.core_usuarios(rol_id);
CREATE INDEX IX_core_usuarios_area ON dbo.core_usuarios(area);
GO

CREATE TABLE dbo.core_especialidades (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_core_especialidades PRIMARY KEY,
    nombre NVARCHAR(120) NOT NULL,
    sigh_especialidad_id INT NULL,
    estado VARCHAR(20) NOT NULL CONSTRAINT DF_core_especialidades_estado DEFAULT ('activo'),
    orden INT NOT NULL CONSTRAINT DF_core_especialidades_orden DEFAULT (100),
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_especialidades_creado DEFAULT (SYSDATETIME()),
    actualizado_en DATETIME2(0) NULL,
    CONSTRAINT UQ_core_especialidades_nombre UNIQUE (nombre),
    CONSTRAINT CK_core_especialidades_estado CHECK (estado IN ('activo', 'inactivo'))
);
GO

CREATE INDEX IX_core_especialidades_estado_orden ON dbo.core_especialidades(estado, orden);
GO

CREATE UNIQUE INDEX UX_core_especialidades_sigh_id
ON dbo.core_especialidades(sigh_especialidad_id)
WHERE sigh_especialidad_id IS NOT NULL;
GO

CREATE TABLE dbo.st_configuracion (
    clave VARCHAR(80) NOT NULL CONSTRAINT PK_st_configuracion PRIMARY KEY,
    valor NVARCHAR(MAX) NULL,
    actualizado_en DATETIME2(0) NULL
);
GO

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
GO

CREATE INDEX IX_st_cupo_reservas_activa ON dbo.st_cupo_reservas(fecha, sigh_especialidad_id, estado);
GO

CREATE TABLE dbo.st_usuario_perfiles (
    usuario_id BIGINT NOT NULL CONSTRAINT PK_st_usuario_perfiles PRIMARY KEY,
    nombre_visible NVARCHAR(160) NULL,
    cargo_area NVARCHAR(160) NULL,
    color_acento VARCHAR(20) NOT NULL CONSTRAINT DF_st_usuario_perfiles_color DEFAULT ('#7c3aed'),
    meta_diaria INT NOT NULL CONSTRAINT DF_st_usuario_perfiles_meta DEFAULT (60),
    avatar_url NVARCHAR(500) NULL,
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_usuario_perfiles_creado DEFAULT (SYSDATETIME()),
    actualizado_en DATETIME2(0) NULL,
    CONSTRAINT FK_st_usuario_perfiles_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id) ON DELETE CASCADE,
    CONSTRAINT CK_st_usuario_perfiles_meta CHECK (meta_diaria BETWEEN 1 AND 999)
);
GO

CREATE TABLE dbo.st_rangos (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_rangos PRIMARY KEY,
    fecha DATE NOT NULL,
    tipo_ticket VARCHAR(30) NOT NULL,
    desde INT NOT NULL,
    hasta INT NOT NULL,
    ultimo INT NOT NULL CONSTRAINT DF_st_rangos_ultimo DEFAULT (0),
    activo BIT NOT NULL CONSTRAINT DF_st_rangos_activo DEFAULT (1),
    creado_por BIGINT NULL,
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_rangos_creado DEFAULT (SYSDATETIME()),
    actualizado_en DATETIME2(0) NULL,
    CONSTRAINT FK_st_rangos_creado_por FOREIGN KEY (creado_por) REFERENCES dbo.core_usuarios(id) ON DELETE SET NULL,
    CONSTRAINT UQ_st_rangos_fecha_tipo UNIQUE (fecha, tipo_ticket),
    CONSTRAINT CK_st_rangos_tipo CHECK (tipo_ticket IN ('Preferencial', 'Normal', 'Procedimiento', 'Emergencia')),
    CONSTRAINT CK_st_rangos_limites CHECK (desde > 0 AND hasta >= desde)
);
GO

CREATE INDEX IX_st_rangos_fecha ON dbo.st_rangos(fecha);
GO

CREATE TABLE dbo.st_tickets (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_tickets PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    fecha DATE NOT NULL,
    hora TIME(0) NOT NULL,
    tipo_ticket VARCHAR(30) NOT NULL,
    prioridad_atencion VARCHAR(20) NULL,
    numero INT NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    tipo_documento VARCHAR(10) NOT NULL,
    numero_documento VARCHAR(20) NOT NULL,
    especialidad_id BIGINT NOT NULL,
    estado VARCHAR(20) NOT NULL CONSTRAINT DF_st_tickets_estado DEFAULT ('emitido'),
    estado_impresion VARCHAR(20) NOT NULL CONSTRAINT DF_st_tickets_estado_impresion DEFAULT ('pendiente'),
    cola_estado VARCHAR(20) NOT NULL CONSTRAINT DF_st_tickets_cola DEFAULT ('sin_cola'),
    reimpresiones INT NOT NULL CONSTRAINT DF_st_tickets_reimpresiones DEFAULT (0),
    creado_por BIGINT NULL,
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_tickets_creado DEFAULT (SYSDATETIME()),
    anulado_en DATETIME2(0) NULL,
    anulado_por BIGINT NULL,
    motivo_anulacion NVARCHAR(255) NULL,
    CONSTRAINT FK_st_tickets_especialidad FOREIGN KEY (especialidad_id) REFERENCES dbo.core_especialidades(id),
    CONSTRAINT FK_st_tickets_creado_por FOREIGN KEY (creado_por) REFERENCES dbo.core_usuarios(id) ON DELETE SET NULL,
    CONSTRAINT FK_st_tickets_anulado_por FOREIGN KEY (anulado_por) REFERENCES dbo.core_usuarios(id),
    CONSTRAINT UQ_st_tickets_uuid UNIQUE (uuid),
    CONSTRAINT UQ_st_tickets_fecha_tipo_numero UNIQUE (fecha, tipo_ticket, numero),
    CONSTRAINT CK_st_tickets_tipo CHECK (tipo_ticket IN ('Preferencial', 'Normal', 'Procedimiento', 'Emergencia')),
    CONSTRAINT CK_st_tickets_documento CHECK (tipo_documento IN ('DNI', 'CE', 'PASAPORTE')),
    CONSTRAINT CK_st_tickets_estado CHECK (estado IN ('emitido', 'anulado', 'en_cola', 'llamando', 'atendiendo', 'atendido', 'ausente')),
    CONSTRAINT CK_st_tickets_impresion CHECK (estado_impresion IN ('pendiente', 'impreso', 'cancelado')),
    CONSTRAINT CK_st_tickets_cola CHECK (cola_estado IN ('sin_cola', 'en_cola', 'llamando', 'atendiendo', 'atendido', 'ausente'))
);
GO

CREATE INDEX IX_st_tickets_fecha ON dbo.st_tickets(fecha);
CREATE INDEX IX_st_tickets_codigo ON dbo.st_tickets(codigo);
CREATE INDEX IX_st_tickets_documento ON dbo.st_tickets(tipo_documento, numero_documento);
CREATE INDEX IX_st_tickets_especialidad ON dbo.st_tickets(especialidad_id);
CREATE INDEX IX_st_tickets_estado ON dbo.st_tickets(estado);
CREATE INDEX IX_st_tickets_estado_impresion ON dbo.st_tickets(estado_impresion);
GO

CREATE TABLE dbo.st_lotes (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_lotes PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    fecha DATE NOT NULL,
    tipo_ticket VARCHAR(30) NOT NULL,
    desde INT NOT NULL,
    hasta INT NOT NULL,
    cantidad INT NOT NULL,
    estado VARCHAR(20) NOT NULL CONSTRAINT DF_st_lotes_estado DEFAULT ('pendiente'),
    ultimo_enviado INT NULL,
    retomar_desde INT NULL,
    creado_por BIGINT NULL,
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_lotes_creado DEFAULT (SYSDATETIME()),
    impreso_en DATETIME2(0) NULL,
    CONSTRAINT FK_st_lotes_creado_por FOREIGN KEY (creado_por) REFERENCES dbo.core_usuarios(id) ON DELETE SET NULL,
    CONSTRAINT UQ_st_lotes_uuid UNIQUE (uuid),
    CONSTRAINT CK_st_lotes_tipo CHECK (tipo_ticket IN ('Preferencial', 'Normal', 'Procedimiento', 'Emergencia')),
    CONSTRAINT CK_st_lotes_estado CHECK (estado IN ('pendiente', 'en_impresion', 'impreso', 'interrumpido', 'cancelado'))
);
GO

CREATE INDEX IX_st_lotes_fecha_tipo ON dbo.st_lotes(fecha, tipo_ticket);
GO

CREATE TABLE dbo.st_impresoras (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_impresoras PRIMARY KEY,
    nombre NVARCHAR(120) NOT NULL,
    modo_impresion VARCHAR(30) NOT NULL CONSTRAINT DF_st_impresoras_modo DEFAULT ('navegador'),
    ancho_mm INT NOT NULL CONSTRAINT DF_st_impresoras_ancho DEFAULT (80),
    alto_mm INT NULL,
    activa BIT NOT NULL CONSTRAINT DF_st_impresoras_activa DEFAULT (1),
    config_json NVARCHAR(MAX) NULL,
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_impresoras_creado DEFAULT (SYSDATETIME()),
    actualizado_en DATETIME2(0) NULL,
    CONSTRAINT UQ_st_impresoras_nombre UNIQUE (nombre),
    CONSTRAINT CK_st_impresoras_modo CHECK (modo_impresion IN ('navegador', 'escpos', 'zpl', 'raw'))
);
GO

CREATE TABLE dbo.st_auditoria (
    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_auditoria PRIMARY KEY,
    usuario_id BIGINT NULL,
    accion VARCHAR(100) NOT NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT NULL,
    detalle NVARCHAR(MAX) NULL,
    ip VARCHAR(45) NULL,
    creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_auditoria_creado DEFAULT (SYSDATETIME()),
    CONSTRAINT FK_st_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.core_usuarios(id) ON DELETE SET NULL
);
GO

CREATE INDEX IX_st_auditoria_usuario ON dbo.st_auditoria(usuario_id);
CREATE INDEX IX_st_auditoria_accion ON dbo.st_auditoria(accion);
CREATE INDEX IX_st_auditoria_entidad ON dbo.st_auditoria(entidad, entidad_id);
CREATE INDEX IX_st_auditoria_creado_en ON dbo.st_auditoria(creado_en);
GO

