<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';
$db = $config['database'];

$dsn = sprintf(
    'sqlsrv:Server=%s,%d;Database=%s',
    $db['host'],
    $db['port'] ?: 1433,
    $db['database']
);

$pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tables = [
    'core_roles',
    'core_usuarios',
    'core_especialidades',
    'st_configuracion',
    'st_rangos',
    'st_tickets',
    'st_auditoria',
];

foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM dbo.{$table}")->fetchColumn();
        echo "{$table}: {$count}\n";
    } catch (Throwable $e) {
        echo "{$table}: (no existe o error)\n";
    }
}
