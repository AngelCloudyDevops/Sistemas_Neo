<?php

declare(strict_types=1);

/**
 * Migra datos ti_neo desde MySQL (sibd_firmas) a SQL Server (GESTION_INF).
 * Usa cliente mysql.exe si no hay pdo_mysql.
 *
 * Uso: php scripts/migrate_mysql_to_gestion_inf.php
 */

$mysqlBin = 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe';
if (!is_file($mysqlBin)) {
    $mysqlBin = 'C:\\xampp\\mysql\\bin\\mysql.exe';
}

$sibdLocal = dirname(__DIR__, 2) . '/SIBD/config/config.local.php';
if (!is_file($sibdLocal)) {
    fwrite(STDERR, "No se encontro SIBD/config/config.local.php\n");
    exit(1);
}

$mysqlCfg = (require $sibdLocal)['database'];
$sqlCfg = (require dirname(__DIR__) . '/config/config.php')['database'];

function mysqlRows(string $bin, array $cfg, string $sql): array
{
    $cmd = sprintf(
        '"%s" -h %s -P %d -u %s -p%s -D %s -N -B -e %s 2>NUL',
        $bin,
        $cfg['host'],
        (int) $cfg['port'],
        $cfg['username'],
        $cfg['password'],
        $cfg['database'],
        escapeshellarg($sql)
    );

    $output = shell_exec($cmd);
    if ($output === null || $output === '') {
        return [];
    }

    $lines = array_filter(array_map('trim', explode("\n", trim($output))));
    if ($lines === []) {
        return [];
    }

    $rows = [];
    foreach ($lines as $line) {
        $cols = explode("\t", $line);
        $rows[] = array_map(static function (string $v): ?string {
            if ($v === '\\N' || strtoupper($v) === 'NULL') {
                return null;
            }

            return $v;
        }, $cols);
    }

    return $rows;
}

function connectSqlServer(array $cfg): PDO
{
    $dsn = sprintf(
        'sqlsrv:Server=%s,%d;Database=%s',
        $cfg['host'],
        $cfg['port'] ?: 1433,
        $cfg['database']
    );

    return new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/** @return list<array{name: string, type: string}> */
function sqlServerColumnMeta(PDO $pdo, string $table): array
{
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME, DATA_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = " . $pdo->quote($table) . '
         ORDER BY ORDINAL_POSITION'
    );

    $meta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta[] = ['name' => (string) $row['COLUMN_NAME'], 'type' => strtolower((string) $row['DATA_TYPE'])];
    }

    return $meta;
}

function normalizeValue(?string $value, string $dataType): mixed
{
    if ($value === null || $value === '') {
        return null;
    }

    if ($dataType === 'bit') {
        return in_array(strtolower($value), ['1', 'true', 'yes'], true) ? 1 : 0;
    }

    if (in_array($dataType, ['date', 'datetime', 'datetime2', 'smalldatetime', 'time'], true)) {
        if (str_starts_with($value, '0000-00-00')) {
            return null;
        }
    }

    return $value;
}

function copyTable(
    string $mysqlBin,
    array $mysqlCfg,
    PDO $dst,
    string $table,
    string $selectSql,
    bool $identityInsert = true,
    bool $truncateBefore = true
): int {
    $rows = mysqlRows($mysqlBin, $mysqlCfg, $selectSql);
    if ($rows === []) {
        echo "  {$table}: 0 filas (origen vacio)\n";

        return 0;
    }

    $meta = sqlServerColumnMeta($dst, $table);
    $columns = array_column($meta, 'name');
    $colList = implode(', ', $columns);
    $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns));

    if ($truncateBefore) {
        $dst->exec('DELETE FROM dbo.' . $table);
    }

    if ($identityInsert) {
        $dst->exec('SET IDENTITY_INSERT dbo.' . $table . ' ON');
    }

    $sql = "INSERT INTO dbo.{$table} ({$colList}) VALUES ({$placeholders})";
    $stmt = $dst->prepare($sql);

    $count = 0;
    foreach ($rows as $row) {
        if (count($row) !== count($columns)) {
            throw new RuntimeException("Columnas no coinciden en {$table}");
        }
        $params = [];
        foreach ($columns as $i => $col) {
            $params[$col] = normalizeValue($row[$i], $meta[$i]['type']);
        }
        $stmt->execute($params);
        $count++;
    }

    if ($identityInsert) {
        $dst->exec('SET IDENTITY_INSERT dbo.' . $table . ' OFF');
    }

    echo "  {$table}: {$count} filas\n";

    return $count;
}

echo "MySQL: {$mysqlCfg['host']}/{$mysqlCfg['database']}\n";
echo "SQL Server: {$sqlCfg['host']}/{$sqlCfg['database']}\n\n";

if (!is_file($mysqlBin)) {
    fwrite(STDERR, "No se encontro mysql.exe\n");
    exit(1);
}

try {
    $dst = connectSqlServer($sqlCfg);
} catch (Throwable $e) {
    fwrite(STDERR, 'SQL Server: ' . $e->getMessage() . "\n");
    exit(1);
}

$dst->beginTransaction();

try {
    echo "Vaciando tablas destino...\n";
    foreach ([
        'st_auditoria',
        'st_tickets',
        'st_lotes',
        'st_rangos',
        'st_impresoras',
        'st_configuracion',
        'core_usuarios',
        'core_especialidades',
        'core_roles',
    ] as $table) {
        $dst->exec('DELETE FROM dbo.' . $table);
    }

    echo "Migrando...\n";

    copyTable($mysqlBin, $mysqlCfg, $dst, 'core_roles', 'SELECT id, nombre, descripcion, estado, creado_en, actualizado_en FROM core_roles ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'core_usuarios', 'SELECT id, rol_id, dni, nombres, apellidos, email, password_hash, must_change_password, area, cargo, telefono, origen, usuario_externo_id, ultimo_login_en, estado, creado_en, actualizado_en FROM core_usuarios ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'core_especialidades', 'SELECT id, nombre, estado, orden, creado_en, actualizado_en FROM core_especialidades ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'st_configuracion', 'SELECT clave, valor, actualizado_en FROM st_configuracion ORDER BY clave', false, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'st_impresoras', 'SELECT id, nombre, modo_impresion, ancho_mm, alto_mm, activa, config_json, creado_en, actualizado_en FROM st_impresoras ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'st_rangos', 'SELECT id, fecha, tipo_ticket, desde, hasta, ultimo, activo, creado_por, creado_en, actualizado_en FROM st_rangos ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'st_lotes', 'SELECT id, uuid, fecha, tipo_ticket, desde, hasta, cantidad, estado, ultimo_enviado, retomar_desde, creado_por, creado_en, impreso_en FROM st_lotes ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'st_tickets', 'SELECT id, uuid, fecha, hora, tipo_ticket, numero, codigo, tipo_documento, numero_documento, especialidad_id, estado, estado_impresion, cola_estado, reimpresiones, creado_por, creado_en, anulado_en, anulado_por, motivo_anulacion FROM st_tickets ORDER BY id', true, false);
    copyTable($mysqlBin, $mysqlCfg, $dst, 'st_auditoria', 'SELECT id, usuario_id, accion, entidad, entidad_id, detalle, ip, creado_en FROM st_auditoria ORDER BY id', true, false);

    $dst->commit();
    echo "\nMigracion completada.\n";
} catch (Throwable $e) {
    $dst->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
