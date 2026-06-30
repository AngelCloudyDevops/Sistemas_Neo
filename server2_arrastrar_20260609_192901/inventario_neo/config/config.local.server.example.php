<?php

declare(strict_types=1);

/** Produccion: usa la base real. No usar en desarrollo local. */
return [
    'app' => [
        'base_url' => 'http://srvbd02:8080/inventario_neo/public',
        'debug' => false,
    ],
    'database' => [
        'driver' => 'sqlsrv',
        'host' => '172.16.10.18',
        'port' => 1433,
        'database' => 'GESTION_INF',
        'username' => 'USUARIO_PRODUCCION',
        'password' => 'CAMBIAR_PASSWORD',
        'encrypt' => 'no',
        'trust_server_certificate' => 'yes',
    ],
];
