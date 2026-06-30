<?php

declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'http://localhost/inventario_neo/public',
        'debug' => true,
    ],
    'database' => [
        'driver' => 'sqlsrv',
        'host' => '172.16.10.18',
        'port' => 1433,
        'database' => 'GESTION_INF1',
        'username' => '',
        'password' => '',
        'encrypt' => 'no',
        'trust_server_certificate' => 'yes',
    ],
];
