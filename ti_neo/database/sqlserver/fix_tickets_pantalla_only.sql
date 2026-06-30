SET NOCOUNT ON;

DECLARE @ticketsPantalla BIGINT = (
    SELECT TOP 1 id
    FROM dbo.core_grupos
    WHERE codigo = 'tickets_pantalla'
);

IF @ticketsPantalla IS NULL
BEGIN
    THROW 50001, 'No existe el grupo tickets_pantalla.', 1;
END;

UPDATE dbo.core_grupos
SET descripcion = N'Muestra la pantalla publica de llamados sin operar tickets ni administrar el sistema.',
    actualizado_en = SYSDATETIME()
WHERE id = @ticketsPantalla;

DELETE gp
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE gp.grupo_id = @ticketsPantalla
  AND (
      p.codigo LIKE 'tickets.%'
      OR p.codigo LIKE 'sistema.tickets.%'
  );

MERGE dbo.core_grupo_politicas AS t
USING (
    SELECT @ticketsPantalla AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
    FROM dbo.core_politicas
    WHERE codigo IN (
        'sistema.tickets.entrar',
        'tickets.pantalla.ver'
    )
) AS s
ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
WHEN MATCHED THEN
    UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (grupo_id, politica_id, permitido, creado_en)
    VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

SELECT
    g.codigo AS grupo_codigo,
    p.codigo AS politica_codigo,
    p.accion,
    p.recurso,
    gp.permitido
FROM dbo.core_grupo_politicas gp
INNER JOIN dbo.core_grupos g ON g.id = gp.grupo_id
INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
WHERE g.codigo = 'tickets_pantalla'
ORDER BY p.codigo;
