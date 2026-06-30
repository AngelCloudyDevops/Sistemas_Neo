<?php

declare(strict_types=1);

namespace TiNeo\Core;

use PDO;

final class Sql
{
    public static function isSqlServer(PDO $db): bool
    {
        return in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlsrv', 'dblib', 'odbc'], true);
    }

    public static function now(PDO $db): string
    {
        return self::isSqlServer($db) ? 'GETDATE()' : 'NOW()';
    }

    public static function lastInsertId(PDO $db): int
    {
        if (!self::isSqlServer($db)) {
            return (int) $db->lastInsertId();
        }

        return (int) $db->query('SELECT CONVERT(BIGINT, SCOPE_IDENTITY())')->fetchColumn();
    }

    public static function top(PDO $db, int $count): string
    {
        return self::isSqlServer($db) && $count > 0 ? 'TOP ' . $count . ' ' : '';
    }

    public static function limit(PDO $db, int $count, int $offset = 0): string
    {
        if ($count < 1) {
            return '';
        }

        if (self::isSqlServer($db)) {
            return $offset > 0 ? ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $count . ' ROWS ONLY' : '';
        }

        return $offset > 0 ? ' LIMIT ' . $offset . ', ' . $count : ' LIMIT ' . $count;
    }

    public static function pageLimit(PDO $db, int $count, int $offset): string
    {
        if ($count < 1) {
            return '';
        }

        $offset = max(0, $offset);

        if (self::isSqlServer($db)) {
            return ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $count . ' ROWS ONLY';
        }

        return $offset > 0 ? ' LIMIT ' . $offset . ', ' . $count : ' LIMIT ' . $count;
    }

    public static function lockSuffix(PDO $db): string
    {
        return self::isSqlServer($db) ? '' : ' FOR UPDATE';
    }

    public static function ticketsFrom(PDO $db, string $alias = ''): string
    {
        $alias = trim($alias);
        $aliasSql = $alias !== '' ? ' ' . $alias : '';

        return self::isSqlServer($db)
            ? 'st_tickets' . $aliasSql . ' WITH (UPDLOCK, ROWLOCK)'
            : 'st_tickets' . $aliasSql;
    }

    public static function rangosFrom(PDO $db): string
    {
        return self::isSqlServer($db) ? 'st_rangos WITH (UPDLOCK, ROWLOCK)' : 'st_rangos';
    }

    public static function orderTicketTypes(): string
    {
        return "CASE tipo_ticket WHEN 'Preferencial' THEN 1 WHEN 'Normal' THEN 2 WHEN 'Procedimiento' THEN 3 WHEN 'Emergencia' THEN 3 ELSE 4 END";
    }

    public static function upsertConfig(PDO $db, string $clave, string $valor): void
    {
        if (self::isSqlServer($db)) {
            $stmt = $db->prepare(
                'MERGE dbo.st_configuracion AS t
                 USING (SELECT :clave AS clave, :valor AS valor) AS s
                 ON t.clave = s.clave
                 WHEN MATCHED THEN
                     UPDATE SET valor = s.valor, actualizado_en = GETDATE()
                 WHEN NOT MATCHED THEN
                     INSERT (clave, valor, actualizado_en) VALUES (s.clave, s.valor, GETDATE());'
            );
            $stmt->execute(['clave' => $clave, 'valor' => $valor]);

            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO st_configuracion (clave, valor, actualizado_en)
             VALUES (:clave, :valor, NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_en = NOW()'
        );
        $stmt->execute(['clave' => $clave, 'valor' => $valor]);
    }
}
