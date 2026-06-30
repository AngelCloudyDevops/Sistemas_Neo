<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use TiNeo\Core\Database;

if ($argc < 2) {
    fwrite(STDERR, "Uso: php scripts/apply_sql_file.php ruta/al/script.sql\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "No existe el archivo SQL: {$path}\n");
    exit(1);
}

$config = require BASE_PATH . '/config/config.php';
$sql = file_get_contents($path);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "El archivo SQL esta vacio o no se pudo leer.\n");
    exit(1);
}

try {
    $db = Database::connection($config);
    $db->exec($sql);
    echo "SQL aplicado: {$path}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
