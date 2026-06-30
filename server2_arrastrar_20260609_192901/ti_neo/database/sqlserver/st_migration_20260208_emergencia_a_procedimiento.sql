-- Reemplaza Emergencia por Procedimiento en GESTION_INF (SQL Server).

-- Orden: 1) quitar CHECK viejo  2) actualizar datos  3) crear CHECK nuevo

-- Ejecutar todo en Navicat (Ctrl+A → Run).



USE GESTION_INF;

GO



DECLARE @table SYSNAME;

DECLARE @constraint SYSNAME;

DECLARE @sql NVARCHAR(MAX);



DECLARE tables CURSOR LOCAL FAST_FORWARD FOR

    SELECT name

    FROM sys.tables

    WHERE name IN ('st_rangos', 'st_tickets', 'st_lotes');



OPEN tables;

FETCH NEXT FROM tables INTO @table;



WHILE @@FETCH_STATUS = 0

BEGIN

    DECLARE constraints CURSOR LOCAL FAST_FORWARD FOR

        SELECT cc.name

        FROM sys.check_constraints cc

        WHERE cc.parent_object_id = OBJECT_ID('dbo.' + @table)

          AND (

              cc.definition LIKE '%Emergencia%'

              OR cc.name LIKE '%tipo%'

          );



    OPEN constraints;

    FETCH NEXT FROM constraints INTO @constraint;



    WHILE @@FETCH_STATUS = 0

    BEGIN

        SET @sql = N'ALTER TABLE dbo.' + QUOTENAME(@table)

            + N' DROP CONSTRAINT ' + QUOTENAME(@constraint);

        EXEC sp_executesql @sql;

        FETCH NEXT FROM constraints INTO @constraint;

    END;



    CLOSE constraints;

    DEALLOCATE constraints;



    FETCH NEXT FROM tables INTO @table;

END;



CLOSE tables;

DEALLOCATE tables;

GO



UPDATE dbo.st_rangos SET tipo_ticket = N'Procedimiento' WHERE tipo_ticket = N'Emergencia';

UPDATE dbo.st_tickets SET tipo_ticket = N'Procedimiento' WHERE tipo_ticket = N'Emergencia';

UPDATE dbo.st_lotes SET tipo_ticket = N'Procedimiento' WHERE tipo_ticket = N'Emergencia';

GO



ALTER TABLE dbo.st_rangos

    ADD CONSTRAINT CK_st_rangos_tipo

    CHECK (tipo_ticket IN (N'Preferencial', N'Normal', N'Procedimiento'));

GO



ALTER TABLE dbo.st_tickets

    ADD CONSTRAINT CK_st_tickets_tipo

    CHECK (tipo_ticket IN (N'Preferencial', N'Normal', N'Procedimiento'));

GO



ALTER TABLE dbo.st_lotes

    ADD CONSTRAINT CK_st_lotes_tipo

    CHECK (tipo_ticket IN (N'Preferencial', N'Normal', N'Procedimiento'));

GO

