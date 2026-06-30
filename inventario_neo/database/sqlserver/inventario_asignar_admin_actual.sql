/*
  Inventario NEO - asignar acceso IAM a un usuario.
  Ejecutar en GESTION_INF produccion si el usuario entra a Inventario,
  pero las APIs devuelven 403 "No tienes permiso para esta accion".

  Cambia @dni si quieres asignar otro usuario.
*/
USE GESTION_INF;
SET NOCOUNT ON;

DECLARE @dni VARCHAR(20) = '72402757';
DECLARE @usuarioId BIGINT;
DECLARE @grupoId BIGINT;

SELECT TOP 1 @usuarioId = id
FROM dbo.core_usuarios
WHERE dni = @dni
  AND estado = 'activo'
ORDER BY id;

SELECT TOP 1 @grupoId = id
FROM dbo.core_grupos
WHERE codigo = 'inventario_admin'
  AND estado = 'activo'
ORDER BY id;

IF @usuarioId IS NULL
BEGIN
    RAISERROR('No se encontro un usuario activo con el DNI indicado.', 16, 1);
    RETURN;
END;

IF @grupoId IS NULL
BEGIN
    RAISERROR('No existe el grupo inventario_admin. Ejecuta primero core_iam_policy_model.sql.', 16, 1);
    RETURN;
END;

MERGE dbo.core_grupo_usuarios AS t
USING (SELECT @usuarioId AS usuario_id, @grupoId AS grupo_id) AS s
ON t.usuario_id = s.usuario_id AND t.grupo_id = s.grupo_id
WHEN MATCHED THEN
    UPDATE SET estado = 'activo', actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (usuario_id, grupo_id, estado, creado_en)
    VALUES (s.usuario_id, s.grupo_id, 'activo', SYSDATETIME());

SELECT
    u.dni,
    CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')) AS usuario,
    g.codigo AS grupo,
    g.nombre AS grupo_nombre,
    gu.estado
FROM dbo.core_grupo_usuarios gu
INNER JOIN dbo.core_usuarios u ON u.id = gu.usuario_id
INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id
WHERE u.id = @usuarioId
  AND g.codigo LIKE 'inventario_%'
ORDER BY g.codigo;
