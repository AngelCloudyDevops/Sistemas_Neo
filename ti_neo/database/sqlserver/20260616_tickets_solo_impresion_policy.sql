/*
  NEO - permiso separado para emision solo impresion.
  No se asigna a Tickets - Emision para no exponer el modo a usuarios normales  esto debo borrar para poder seguir yo angel moreano pacaya
  aif obejet recuerdaspra finvuones del marco fse dbeADRE FUERAZA IF objetc_id (´dbo.core_grupos,modelo iam primero ver politicas throw 50003 falta) .
*/

IF OBJECT_ID('dbo.core_grupos', 'U') IS NULL
    THROW 50001, 'Falta dbo.core_grupos. Ejecuta el modelo IAM primero.', 1;

IF OBJECT_ID('dbo.core_politicas', 'U') IS NULL
    THROW 50002, 'Falta dbo.core_politicas. Ejecuta el modelo IAM primero.', 1;

IF OBJECT_ID('dbo.core_grupo_politicas', 'U') IS NULL
    THROW 50003, 'Falta dbo.core_grupo_politicas. Ejecuta el modelo IAM primero.', 1;

IF COL_LENGTH('dbo.core_grupos', 'actualizado_en') IS NULL
BEGIN
    ALTER TABLE dbo.core_grupos ADD actualizado_en DATETIME2(0) NULL;
END;

IF COL_LENGTH('dbo.core_politicas', 'actualizado_en') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas ADD actualizado_en DATETIME2(0) NULL;
END;

IF COL_LENGTH('dbo.core_politicas', 'efecto') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD efecto VARCHAR(10) NOT NULL CONSTRAINT DF_core_politicas_efecto DEFAULT ('Allow');
END;

IF COL_LENGTH('dbo.core_politicas', 'accion') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD accion VARCHAR(120) NOT NULL CONSTRAINT DF_core_politicas_accion DEFAULT ('*');
END;

IF COL_LENGTH('dbo.core_politicas', 'recurso') IS NULL
BEGIN
    ALTER TABLE dbo.core_politicas
    ADD recurso VARCHAR(160) NOT NULL CONSTRAINT DF_core_politicas_recurso DEFAULT ('*');
END;

IF COL_LENGTH('dbo.core_grupo_politicas', 'permitido') IS NULL
BEGIN
    ALTER TABLE dbo.core_grupo_politicas
    ADD permitido BIT NOT NULL CONSTRAINT DF_core_grupo_politicas_permitido DEFAULT (1);
END;

IF COL_LENGTH('dbo.core_grupo_politicas', 'actualizado_en') IS NULL
BEGIN
    ALTER TABLE dbo.core_grupo_politicas ADD actualizado_en DATETIME2(0) NULL;
END;

MERGE dbo.core_grupos AS t
USING (SELECT
    'tickets_solo_impresion' AS codigo,
    N'Tickets - Solo impresion' AS nombre,
    N'Permite emitir tickets impresos sin ingresar al turnero.' AS descripcion,
    'activo' AS estado
) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, descripcion = s.descripcion, estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, descripcion, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.descripcion, s.estado, SYSDATETIME());

MERGE dbo.core_politicas AS t
USING (SELECT
    'tickets.emitir.solo_impresion' AS codigo,
    N'Emitir solo impresion' AS nombre,
    'tickets' AS sistema_codigo,
    N'Permite emitir tickets impresos sin reservar cupo ni enviarlos al turnero.' AS descripcion,
    'permiso' AS tipo,
    'Allow' AS efecto,
    'tickets.emitir.solo_impresion' AS accion,
    'sistema:tickets' AS recurso,
    'activo' AS estado
) AS s
ON t.codigo = s.codigo
WHEN MATCHED THEN
    UPDATE SET nombre = s.nombre, sistema_codigo = s.sistema_codigo, descripcion = s.descripcion,
               tipo = s.tipo, efecto = s.efecto, accion = s.accion, recurso = s.recurso,
               estado = s.estado, actualizado_en = SYSDATETIME()
WHEN NOT MATCHED THEN
    INSERT (codigo, nombre, sistema_codigo, descripcion, tipo, efecto, accion, recurso, estado, creado_en)
    VALUES (s.codigo, s.nombre, s.sistema_codigo, s.descripcion, s.tipo, s.efecto, s.accion, s.recurso, s.estado, SYSDATETIME());

DECLARE @ticketsSoloImpresion BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_solo_impresion');
DECLARE @ticketsAdmin BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'tickets_admin');
DECLARE @superAdmins BIGINT = (SELECT TOP 1 id FROM dbo.core_grupos WHERE codigo = 'super_admins');

IF @ticketsSoloImpresion IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsSoloImpresion AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo IN (
            'sistema.tickets.entrar',
            'tickets.emision.ver',
            'tickets.perfil.ver',
            'tickets.emitir.solo_impresion',
            'tickets.reimprimir.dia'
        )
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

IF @ticketsAdmin IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @ticketsAdmin AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo = 'tickets.emitir.solo_impresion'
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

IF @superAdmins IS NOT NULL
    MERGE dbo.core_grupo_politicas AS t
    USING (
        SELECT @superAdmins AS grupo_id, id AS politica_id, CAST(1 AS BIT) AS permitido
        FROM dbo.core_politicas
        WHERE codigo = 'tickets.emitir.solo_impresion'
    ) AS s
    ON t.grupo_id = s.grupo_id AND t.politica_id = s.politica_id
    WHEN MATCHED THEN UPDATE SET permitido = s.permitido, actualizado_en = SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT (grupo_id, politica_id, permitido, creado_en)
        VALUES (s.grupo_id, s.politica_id, s.permitido, SYSDATETIME());

PRINT N'Permiso Tickets - Solo impresion actualizado.';
