-- Valores base de impresion (plantilla predeterminada).
USE GESTION_INF;
GO

MERGE dbo.st_configuracion AS t
USING (SELECT clave, valor FROM (VALUES
    (N'ticket_papel_mm', N'80'),
    (N'ticket_contenido_mm', N'80'),
    (N'ticket_padding_mm', N'5'),
    (N'ticket_logo_mm', N'16'),
    (N'ticket_header_px', N'15'),
    (N'ticket_codigo_px', N'54'),
    (N'ticket_documento_px', N'19'),
    (N'ticket_especialidad_px', N'19'),
    (N'ticket_fecha_px', N'31'),
    (N'ticket_codigo_margen_mm', N'0'),
    (N'ticket_linea_margen_mm', N'0'),
    (N'ticket_fecha_margen_mm', N'0'),
    (N'ticket_offset_x_mm', N'0'),
    (N'ticket_offset_y_mm', N'0'),
    (N'ticket_hospital_x_mm', N'0'),
    (N'ticket_hospital_y_mm', N'0'),
    (N'ticket_logo_left_x_mm', N'0'),
    (N'ticket_logo_left_y_mm', N'0'),
    (N'ticket_logo_right_x_mm', N'0'),
    (N'ticket_logo_right_y_mm', N'0'),
    (N'ticket_code_x_mm', N'0'),
    (N'ticket_code_y_mm', N'0'),
    (N'ticket_doc_x_mm', N'0'),
    (N'ticket_doc_y_mm', N'0'),
    (N'ticket_specialty_x_mm', N'0'),
    (N'ticket_specialty_y_mm', N'0'),
    (N'ticket_date_x_mm', N'0'),
    (N'ticket_date_y_mm', N'0')
) AS v(clave, valor)) AS s ON t.clave = s.clave
WHEN MATCHED THEN
    UPDATE SET valor = s.valor, actualizado_en = GETDATE()
WHEN NOT MATCHED THEN
    INSERT (clave, valor, actualizado_en) VALUES (s.clave, s.valor, GETDATE());
GO
