<?php

declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'http://localhost/gs_neo/public',
        'debug' => true,
    ],
    'database' => [
        'driver' => 'pdo_odbc_sqlsrv',
        'odbc_driver' => 'ODBC Driver 18 for SQL Server',
        'host' => '172.16.10.18',
        'port' => 1433,
        'database' => 'GESTION_INF1',
        'username' => '',
        'password' => '',
        'encrypt' => 'no',
        'trust_server_certificate' => 'yes',
    ],
];
