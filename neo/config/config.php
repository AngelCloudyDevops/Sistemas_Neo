<?php

declare(strict_types=1);

$config = [
    'app' => [
        'name' => 'Portal NEO',
        'base_url' => getenv('NEO_BASE_URL') ?: 'http://localhost/neo/public',
        'timezone' => 'America/Lima',
        'debug' => false,
    ],
    'database' => [
        'driver' => getenv('NEO_DB_DRIVER') ?: 'sqlsrv',
        'host' => getenv('NEO_DB_HOST') ?: '172.16.10.18',
        'port' => getenv('NEO_DB_PORT') !== false ? (int) getenv('NEO_DB_PORT') : 1433,
        'database' => getenv('NEO_DB_NAME') ?: '',
        'username' => getenv('NEO_DB_USER') ?: '',
        'password' => getenv('NEO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'encrypt' => getenv('NEO_DB_ENCRYPT') ?: 'no',
        'trust_server_certificate' => getenv('NEO_DB_TRUST_CERT') ?: 'yes',
        'odbc_driver' => getenv('NEO_DB_ODBC_DRIVER') ?: 'ODBC Driver 17 for SQL Server',
    ],
    'jwt' => [
        'secret' => getenv('SIBD_JWT_SECRET') ?: 'CAMBIAR_ESTA_CLAVE_SECRETA_EN_PRODUCCION',
        'issuer' => 'neo-portal',
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

$tiNeoLocal = dirname(__DIR__, 1) . '/../ti_neo/config/config.local.php';
if (is_file($tiNeoLocal)) {
    $tiConfig = require $tiNeoLocal;
    if (isset($tiConfig['database'])) {
        $config['database'] = array_replace_recursive($config['database'], $tiConfig['database']);
    }

    if (isset($tiConfig['jwt'])) {
        $config['jwt'] = array_replace_recursive($config['jwt'], $tiConfig['jwt']);
        $config['jwt']['issuer'] = 'neo-portal';
    }
}

$sibdLocal = dirname(__DIR__, 1) . '/../SIBD/config/config.local.php';
if (is_file($sibdLocal)) {
    $sibdConfig = require $sibdLocal;
    if (isset($sibdConfig['jwt'])) {
        $config['jwt'] = array_replace_recursive($config['jwt'], $sibdConfig['jwt']);
        $config['jwt']['issuer'] = 'neo-portal';
    }
}

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
