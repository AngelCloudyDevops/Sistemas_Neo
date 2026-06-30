<?php

declare(strict_types=1);

namespace TiNeo\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;
    /** @var array<string, PDO> */
    private static array $namedConnections = [];

    public static function getConnection(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        self::$connection = self::connect($config['database']);

        return self::$connection;
    }

    public static function namedConnection(array $config, string $key): PDO
    {
        if (isset(self::$namedConnections[$key])) {
            return self::$namedConnections[$key];
        }

        if (!isset($config[$key]) || !is_array($config[$key])) {
            throw new RuntimeException(sprintf('No existe la configuracion de base de datos "%s".', $key));
        }

        self::$namedConnections[$key] = self::connect($config[$key]);

        return self::$namedConnections[$key];
    }

    private static function connect(array $db): PDO
    {
        $driver = strtolower((string) ($db['driver'] ?? 'mysql'));
        $isSqlServer = in_array($driver, ['sqlsrv', 'pdo_odbc_sqlsrv', 'odbc_sqlsrv'], true);
        $sqlServerHost = (string) ($db['host'] ?? 'localhost');
        $sqlServerPort = (int) ($db['port'] ?? 0);
        $sqlServerTarget = $sqlServerPort > 0 && !str_contains($sqlServerHost, '\\')
            ? sprintf('%s,%d', $sqlServerHost, $sqlServerPort)
            : $sqlServerHost;
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
            'sqlsrv' => sprintf(
                'sqlsrv:Server=%s;Database=%s%s',
                $sqlServerTarget,
                $db['database'],
                $sqlServerOptions
            ),
            'pdo_odbc_sqlsrv', 'odbc_sqlsrv' => sprintf(
                'odbc:Driver={%s};Server=%s;Database=%s%s',
                (string) ($db['odbc_driver'] ?? 'ODBC Driver 18 for SQL Server'),
                $sqlServerTarget,
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

        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlsrv'
            && defined('PDO::SQLSRV_ATTR_ENCODING')
            && defined('PDO::SQLSRV_ENCODING_UTF8')
        ) {
            $pdoOptions[\PDO::SQLSRV_ATTR_ENCODING] = \PDO::SQLSRV_ENCODING_UTF8;
        }

        try {
            return $usesWindowsAuth
                ? new PDO($dsn, null, null, $pdoOptions)
                : new PDO($dsn, $db['username'], $db['password'], $pdoOptions);
        } catch (PDOException $exception) {
            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $exception);
        }
    }

    public static function connection(array $config): PDO
    {
        return self::getConnection($config);
    }
}
