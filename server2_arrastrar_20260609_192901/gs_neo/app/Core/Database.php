<?php

declare(strict_types=1);

namespace GsNeo\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $db = $config['database'];
        $driver = strtolower((string) ($db['driver'] ?? 'sqlsrv'));
        $isSqlServer = in_array($driver, ['sqlsrv', 'pdo_odbc_sqlsrv', 'odbc_sqlsrv'], true);
        $host = (string) ($db['host'] ?? 'localhost');
        $port = (int) ($db['port'] ?? 0);
        $target = $port > 0 && !str_contains($host, '\\') ? sprintf('%s,%d', $host, $port) : $host;
        $usesWindowsAuth = $isSqlServer
            && (string) ($db['username'] ?? '') === ''
            && (string) ($db['password'] ?? '') === '';

        $sqlServerOptions = '';
        if ($isSqlServer) {
            if (array_key_exists('encrypt', $db)) {
                $sqlServerOptions .= ';Encrypt=' . (string) $db['encrypt'];
            }

            if (array_key_exists('trust_server_certificate', $db)) {
                $sqlServerOptions .= ';TrustServerCertificate=' . (string) $db['trust_server_certificate'];
            }
        }

        $dsn = match ($driver) {
            'sqlsrv' => sprintf('sqlsrv:Server=%s;Database=%s%s', $target, $db['database'], $sqlServerOptions),
            'pdo_odbc_sqlsrv', 'odbc_sqlsrv' => sprintf(
                'odbc:Driver={%s};Server=%s;Database=%s%s',
                (string) ($db['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server'),
                $target,
                $db['database'],
                $sqlServerOptions
            ),
            default => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['database'],
                $db['charset']
            ),
        };

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            self::$connection = $usesWindowsAuth
                ? new PDO($dsn, null, null, $options)
                : new PDO($dsn, $db['username'], $db['password'], $options);

            return self::$connection;
        } catch (PDOException $exception) {
            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $exception);
        }
    }
}
