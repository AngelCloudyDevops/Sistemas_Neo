<?php

declare(strict_types=1);

namespace InventarioNeo\Core;

use PDO;

final class Sql
{
    public static function isSqlServer(PDO $db): bool
    {
        $driver = strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME));
        return in_array($driver, ['sqlsrv', 'odbc', 'dblib'], true);
    }

    public static function now(PDO $db): string
    {
        return self::isSqlServer($db) ? 'SYSDATETIME()' : 'NOW()';
    }

    public static function limit(PDO $db, int $limit): string
    {
        return self::isSqlServer($db) ? 'TOP ' . max(1, $limit) : '';
    }
}
