<?php

declare(strict_types=1);

$config = [
    'app' => [
        'name' => 'Gestion de Usuarios NEO',
        'base_url' => 'http://localhost/gs_neo/public',
        'timezone' => 'America/Lima',
        'debug' => false,
    ],
    'database' => [
        'driver' => getenv('GS_NEO_DB_DRIVER') ?: 'sqlsrv',
        'host' => getenv('GS_NEO_DB_HOST') ?: '172.16.10.18',
        'port' => (int) (getenv('GS_NEO_DB_PORT') ?: 1433),
        'database' => getenv('GS_NEO_DB_NAME') ?: '',
        'username' => getenv('GS_NEO_DB_USER') ?: '',
        'password' => getenv('GS_NEO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret' => getenv('SIBD_JWT_SECRET') ?: 'CAMBIAR_ESTA_CLAVE_SECRETA_EN_PRODUCCION',
        'issuer' => 'gs-neo',
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
        $config['jwt']['issuer'] = 'gs-neo';
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
