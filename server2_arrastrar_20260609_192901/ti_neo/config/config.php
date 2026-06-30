<?php

declare(strict_types=1);

$config = [
    'app' => [
        'name' => 'Tickets HSJ',
        'base_url' => 'http://localhost/ti_neo/public',
        'timezone' => 'America/Lima',
        'debug' => false,
    ],
    'database' => [
        'driver' => getenv('TI_NEO_DB_DRIVER') ?: 'sqlsrv',
        'host' => getenv('TI_NEO_DB_HOST') ?: '172.16.10.18',
        'port' => (int) (getenv('TI_NEO_DB_PORT') ?: 1433),
        'database' => getenv('TI_NEO_DB_NAME') ?: '',
        'username' => getenv('TI_NEO_DB_USER') ?: '',
        'password' => getenv('TI_NEO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'sigh' => [
        'driver' => getenv('TI_NEO_SIGH_DB_DRIVER') ?: (getenv('TI_NEO_DB_DRIVER') ?: 'sqlsrv'),
        'odbc_driver' => getenv('TI_NEO_SIGH_ODBC_DRIVER') ?: (getenv('TI_NEO_ODBC_DRIVER') ?: 'ODBC Driver 18 for SQL Server'),
        'host' => getenv('TI_NEO_SIGH_DB_HOST') ?: '172.16.10.18',
        'port' => (int) (getenv('TI_NEO_SIGH_DB_PORT') ?: 1433),
        'database' => getenv('TI_NEO_SIGH_DB_NAME') ?: 'SIGH',
        'username' => getenv('TI_NEO_SIGH_DB_USER') ?: '',
        'password' => getenv('TI_NEO_SIGH_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'encrypt' => getenv('TI_NEO_SIGH_DB_ENCRYPT') ?: (getenv('TI_NEO_DB_ENCRYPT') ?: 'no'),
        'trust_server_certificate' => getenv('TI_NEO_SIGH_DB_TRUST_CERT') ?: (getenv('TI_NEO_DB_TRUST_CERT') ?: 'yes'),
    ],
    'jwt' => [
        'secret' => getenv('SIBD_JWT_SECRET') ?: 'CAMBIAR_ESTA_CLAVE_SECRETA_EN_PRODUCCION',
        'issuer' => 'ti-neo',
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

$localConfig = __DIR__ . '/config.local.php';
$tiNeoLocalLoaded = is_file($localConfig);

$sibdLocal = dirname(__DIR__, 2) . '/SIBD/config/config.local.php';
if (is_file($sibdLocal)) {
    $sibdConfig = require $sibdLocal;
    unset($sibdConfig['database']);
    $config = array_replace_recursive($config, $sibdConfig);
}

if ($tiNeoLocalLoaded) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;
