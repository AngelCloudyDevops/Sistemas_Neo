USE GESTION_INF_DEV;
MERGE dbo.core_roles AS t
USING (SELECT nombre, descripcion, estado FROM (VALUES
    (N'Administrador', N'Administrador general del sistema', 'activo'),
    (N'Ventanilla', N'Usuario que emite tickets', 'activo'),
    (N'Consulta', N'Usuario de solo consulta', 'activo')
) AS v(nombre, descripcion, estado)) AS s
ON t.nombre = s.nombre
WHEN MATCHED THEN
    UPDATE SET descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (nombre, descripcion, estado, creado_en) VALUES (s.nombre, s.descripcion, s.estado, SYSDATETIME());
DECLARE @adminRol BIGINT = (SELECT TOP 1 id FROM dbo.core_roles WHERE nombre = N'Administrador');

MERGE dbo.core_usuarios AS t
USING (
    SELECT
        @adminRol AS rol_id,
        '00000001' AS dni,
        N'Administrador' AS nombres,
        N'Sistema' AS apellidos,
        N'admin@sibd.local' AS email,
        N'$2y$10$pSkORVtGITCQU0vU/AIEXu9eTHO6V/xCNalGvY.nGgXhViGGdStCW' AS password_hash,
        CAST(0 AS BIT) AS must_change_password,
        N'TI' AS area,
        N'Administrador del sistema' AS cargo,
        'local' AS origen,
        'activo' AS estado
) AS s
ON t.email = s.email
WHEN MATCHED THEN
    UPDATE SET
        rol_id = s.rol_id,
        dni = s.dni,
        nombres = s.nombres,
        apellidos = s.apellidos,
        password_hash = s.password_hash,
        must_change_password = s.must_change_password,
        area = s.area,
        cargo = s.cargo,
        origen = s.origen,
        estado = s.estado,
        actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (
        rol_id, dni, nombres, apellidos, email, password_hash,
        must_change_password, area, cargo, origen, estado, creado_en
    )
    VALUES (
        s.rol_id, s.dni, s.nombres, s.apellidos, s.email, s.password_hash,
        s.must_change_password, s.area, s.cargo, s.origen, s.estado, SYSDATETIME()
    );
MERGE dbo.core_especialidades AS t
USING (SELECT nombre, estado, orden FROM (VALUES
    (N'ANESTESIOLOGIA', 'activo', 10),
    (N'CARDIOLOGIA', 'activo', 20),
    (N'CIRUGIA GENERAL', 'activo', 30),
    (N'CRED', 'activo', 40),
    (N'DERMATOLOGIA', 'activo', 50),
    (N'ENDOCRINOLOGIA', 'activo', 60),
    (N'GASTROENTEROLOGIA', 'activo', 70),
    (N'GINECOLOGIA', 'activo', 80),
    (N'INFECTOLOGIA', 'activo', 90),
    (N'MEDICINA ALTERNATIVA', 'activo', 100),
    (N'MEDICINA FAMILIAR', 'activo', 110),
    (N'MEDICINA FISICA', 'activo', 120),
    (N'MEDICINA GENERAL', 'activo', 130),
    (N'MEDICINA INTERNA', 'activo', 140),
    (N'NEUMOLOGIA', 'activo', 150),
    (N'NEUROLOGIA', 'activo', 160),
    (N'OBSTETRICIA', 'activo', 170),
    (N'ODONTOLOGIA', 'activo', 180),
    (N'OFTALMOLOGIA', 'activo', 190),
    (N'ONCOLOGIA', 'activo', 200),
    (N'OTORRINO', 'activo', 210),
    (N'PEDIATRIA GENERAL', 'activo', 220),
    (N'PSICOLOGIA', 'activo', 230),
    (N'PSIQUIATRIA', 'activo', 240),
    (N'REUMATOLOGIA', 'activo', 250),
    (N'TRAUMATOLOGIA', 'activo', 260),
    (N'UROLOGIA', 'activo', 270)
) AS v(nombre, estado, orden)) AS s
ON t.nombre = s.nombre
WHEN MATCHED THEN
    UPDATE SET estado = s.estado, orden = s.orden, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (nombre, estado, orden, creado_en) VALUES (s.nombre, s.estado, s.orden, SYSDATETIME());
MERGE dbo.st_configuracion AS t
USING (SELECT clave, valor FROM (VALUES
    ('ticket_titulo', N'HOSPITAL SAN\nJOSE DEL CALLAO'),
    ('logo_izquierdo_url', N'https://thumbs4.imagebam.com/a4/33/1a/ME1CROZK_t.png'),
    ('logo_derecho_url', N'https://thumbs4.imagebam.com/bd/2a/44/ME1CROVG_t.png'),
    ('impresion_modo', N'navegador'),
    ('impresion_ancho_mm', N'80'),
    ('impresion_codigo_px', N'48'),
    ('impresion_fecha_px', N'28'),
    ('impresion_documento_px', N'17'),
    ('impresion_icono_px', N'54'),
    ('impresion_padding_mm', N'5'),
    ('impresion_automatica', N'SI'),
    ('impresion_retraso_ms', N'120'),
    ('ticket_papel_mm', N'80'),
    ('ticket_contenido_mm', N'80'),
    ('ticket_padding_mm', N'5'),
    ('ticket_logo_mm', N'16'),
    ('ticket_header_px', N'15'),
    ('ticket_codigo_px', N'54'),
    ('ticket_documento_px', N'19'),
    ('ticket_especialidad_px', N'19'),
    ('ticket_fecha_px', N'31'),
    ('ticket_codigo_margen_mm', N'0'),
    ('ticket_linea_margen_mm', N'0'),
    ('ticket_fecha_margen_mm', N'0'),
    ('ticket_offset_x_mm', N'3'),
    ('ticket_hospital_x_mm', N'0'),
    ('ticket_hospital_y_mm', N'0'),
    ('ticket_logo_left_x_mm', N'0'),
    ('ticket_logo_left_y_mm', N'0'),
    ('ticket_logo_right_x_mm', N'0'),
    ('ticket_logo_right_y_mm', N'0'),
    ('ticket_code_x_mm', N'0'),
    ('ticket_code_y_mm', N'0'),
    ('ticket_doc_x_mm', N'0'),
    ('ticket_doc_y_mm', N'0'),
    ('ticket_specialty_x_mm', N'0'),
    ('ticket_specialty_y_mm', N'0'),
    ('ticket_date_x_mm', N'0'),
    ('ticket_date_y_mm', N'0')
) AS v(clave, valor)) AS s
ON t.clave = s.clave
WHEN MATCHED THEN
    UPDATE SET valor = s.valor, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (clave, valor, actualizado_en) VALUES (s.clave, s.valor, SYSDATETIME());
MERGE dbo.st_impresoras AS t
USING (
    SELECT N'Navegador / Chrome kiosk' AS nombre, 'navegador' AS modo_impresion, 80 AS ancho_mm, CAST(1 AS BIT) AS activa
) AS s
ON t.nombre = s.nombre
WHEN MATCHED THEN
    UPDATE SET modo_impresion = s.modo_impresion, ancho_mm = s.ancho_mm, activa = s.activa, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (nombre, modo_impresion, ancho_mm, activa, creado_en) VALUES (s.nombre, s.modo_impresion, s.ancho_mm, s.activa, SYSDATETIME());

