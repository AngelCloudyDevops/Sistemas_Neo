<?php

declare(strict_types=1);

namespace Neo\Core;

use PDO;

final class Sql
{
    public static function isSqlServer(PDO $db): bool
    {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlsrv'
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'odbc';
    }

    public static function now(PDO $db): string
    {
        return self::isSqlServer($db) ? 'SYSDATETIME()' : 'NOW()';
    }
}
