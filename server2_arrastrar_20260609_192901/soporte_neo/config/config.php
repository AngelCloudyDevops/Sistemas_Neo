<?php

declare(strict_types=1);

$config = [
    'app' => [
        'name' => 'Gestion de Soporte NEO',
        'base_url' => getenv('SOPORTE_NEO_BASE_URL') ?: '',
        'timezone' => 'America/Lima',
        'debug' => false,
    ],
    'database' => [
        'driver' => getenv('SOPORTE_NEO_DB_DRIVER') ?: 'sqlsrv',
        'host' => getenv('SOPORTE_NEO_DB_HOST') ?: '172.16.10.18',
        'port' => (int) (getenv('SOPORTE_NEO_DB_PORT') ?: 1433),
        'database' => getenv('SOPORTE_NEO_DB_NAME') ?: '',
        'username' => getenv('SOPORTE_NEO_DB_USER') ?: '',
        'password' => getenv('SOPORTE_NEO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret' => getenv('SIBD_JWT_SECRET') ?: 'CAMBIAR_ESTA_CLAVE_SECRETA_EN_PRODUCCION',
        'issuer' => 'soporte-neo',
        'audience' => 'hsj-intranet',
        'ttl_seconds' => 3600,
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
        $config['jwt']['issuer'] = 'soporte-neo';
    }
}

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;

