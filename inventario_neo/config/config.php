<?php

declare(strict_types=1);

$config = [
    'app' => [
        'name' => 'Inventario NEO',
        'base_url' => 'http://localhost/inventario_neo/public',
        'timezone' => 'America/Lima',
        'debug' => false,
    ],
    'database' => [
        'driver' => getenv('INVENTARIO_NEO_DB_DRIVER') ?: 'sqlsrv',
        'host' => getenv('INVENTARIO_NEO_DB_HOST') ?: '172.16.10.18',
        'port' => (int) (getenv('INVENTARIO_NEO_DB_PORT') ?: 1433),
        'database' => getenv('INVENTARIO_NEO_DB_NAME') ?: '',
        'username' => getenv('INVENTARIO_NEO_DB_USER') ?: '',
        'password' => getenv('INVENTARIO_NEO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'encrypt' => getenv('INVENTARIO_NEO_DB_ENCRYPT') ?: 'no',
        'trust_server_certificate' => getenv('INVENTARIO_NEO_DB_TRUST_CERT') ?: 'yes',
        'odbc_driver' => getenv('INVENTARIO_NEO_DB_ODBC_DRIVER') ?: 'ODBC Driver 17 for SQL Server',
    ],
    'jwt' => [
        'secret' => getenv('SIBD_JWT_SECRET') ?: 'CAMBIAR_ESTA_CLAVE_SECRETA_EN_PRODUCCION',
        'issuer' => 'inventario-neo',
        'audience' => 'hsj-intranet',
        'ttl_seconds' => 1800,
    ],
    'security' => [
        'login_max_attempts' => 5,
        'login_lock_seconds' => 900,
        'refresh_ttl_seconds' => 43200,
        'session_inactivity_seconds' => 3600,
    ],
];

$tiNeoLocal = dirname(__DIR__, 2) . '/ti_neo/config/config.local.php';
if (is_file($tiNeoLocal)) {
    $tiConfig = require $tiNeoLocal;
    if (isset($tiConfig['database'])) {
        $config['database'] = array_replace_recursive($config['database'], $tiConfig['database']);
    }

    if (isset($tiConfig['jwt'])) {
        $config['jwt'] = array_replace_recursive($config['jwt'], $tiConfig['jwt']);
        $config['jwt']['issuer'] = 'inventario-neo';
    }

    if (isset($tiConfig['security'])) {
        $config['security'] = array_replace_recursive($config['security'], $tiConfig['security']);
    }
}

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
