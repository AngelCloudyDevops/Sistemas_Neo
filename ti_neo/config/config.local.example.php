<?php

declare(strict_types=1);

/** Desarrollo local: usa la copia de pruebas del servidor, no produccion. */
return [
    'app' => [
        'base_url' => getenv('TI_NEO_BASE_URL') ?: 'http://localhost/ti_neo/public',
        'debug' => false,
    ],
    'database' => [
        // Usar "pdo_odbc_sqlsrv" si pdo_sqlsrv falla con "Encryption not supported on the client".
        'driver' => getenv('TI_NEO_DB_DRIVER') ?: 'pdo_odbc_sqlsrv',
        'odbc_driver' => getenv('TI_NEO_ODBC_DRIVER') ?: 'ODBC Driver 18 for SQL Server',
        'host' => getenv('TI_NEO_DB_HOST') ?: '172.16.10.18',
        'port' => 1433,
        'database' => getenv('TI_NEO_DB_NAME') ?: 'GESTION_INF1',
        'username' => getenv('TI_NEO_DB_USER') ?: 'zgonzales',
        'password' => getenv('TI_NEO_DB_PASS') ?: '',
        'encrypt' => getenv('TI_NEO_DB_ENCRYPT') ?: 'no',
        'trust_server_certificate' => getenv('TI_NEO_DB_TRUST_CERT') ?: 'yes',
    ],
    'sigh' => [
        'driver' => getenv('TI_NEO_SIGH_DB_DRIVER') ?: (getenv('TI_NEO_DB_DRIVER') ?: 'pdo_odbc_sqlsrv'),
        'odbc_driver' => getenv('TI_NEO_SIGH_ODBC_DRIVER') ?: (getenv('TI_NEO_ODBC_DRIVER') ?: 'ODBC Driver 18 for SQL Server'),
        'host' => getenv('TI_NEO_SIGH_DB_HOST') ?: '172.16.10.18',
        'port' => 1433,
        'database' => getenv('TI_NEO_SIGH_DB_NAME') ?: 'SIGH',
        'username' => getenv('TI_NEO_SIGH_DB_USER') ?: 'webSoporte',
        'password' => getenv('TI_NEO_SIGH_DB_PASS') ?: '',
        'encrypt' => getenv('TI_NEO_SIGH_DB_ENCRYPT') ?: 'no',
        'trust_server_certificate' => getenv('TI_NEO_SIGH_DB_TRUST_CERT') ?: 'yes',
    ],
];
