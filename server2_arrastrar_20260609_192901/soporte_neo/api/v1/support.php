<?php

declare(strict_types=1);

use SoporteNeo\Core\Auth;
use SoporteNeo\Core\Database;
use SoporteNeo\Core\Request;
use SoporteNeo\Core\Response;
use SoporteNeo\Services\JwtService;

$config = require __DIR__ . '/../../config/bootstrap.php';

define('SUPPORT_RESOURCE', 'sistema:soporte');

try {
    $db = Database::connection($config);
    $auth = new Auth(new JwtService($config), $db);
    $action = (string) ($_GET['action'] ?? 'dashboard');

    match ($action) {
        'permisos' => permisos($auth),
        'dashboard' => dashboard($db, $auth),
        'perfil' => perfil($db, $auth),
        'guardar-perfil' => guardarPerfil($db, $auth),
        'tickets' => tickets($db, $auth),
        'crear' => crear($db, $auth),
        'asignar' => asignar($db, $auth),
        'resolver' => resolver($db, $auth),
        'eliminar' => eliminar($db, $auth),
        'tecnicos' => tecnicos($db, $auth),
        default => Response::error('Accion no disponible.', 404),
    };
} catch (Throwable $exception) {
    error_log('[soporte_neo] ' . $exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}

function permisos(Auth $auth): void
{
    $user = $auth->requirePolicy('sistema.soporte.entrar', SUPPORT_RESOURCE);
    Response::success([
        'user' => $user,
        'permissions' => $auth->supportPermissions((int) ($user['id'] ?? 0)),
    ]);
}

function dashboard(PDO $db, Auth $auth): void
{
    $user = $auth->requirePolicy('sistema.soporte.entrar', SUPPORT_RESOURCE);
    ensureSchema($db);

    $tickets = fetchTickets($db, 50);
    Response::success([
        'user' => $user,
        'permissions' => $auth->supportPermissions((int) ($user['id'] ?? 0)),
        'stats' => stats($tickets),
        'tickets' => $tickets,
        'technicians' => fetchTechnicians($db),
    ]);
}

function tickets(PDO $db, Auth $auth): void
{
    $auth->requirePolicy('sistema.soporte.entrar', SUPPORT_RESOURCE);
    ensureSchema($db);
    Response::success(['tickets' => fetchTickets($db, 100)]);
}

function crear(PDO $db, Auth $auth): void
{
    $user = $auth->requirePolicy('soporte.tickets.crear', SUPPORT_RESOURCE);
    ensureSchema($db);
    $data = Request::json();

    $usuario = trim((string) ($data['usuario'] ?? ''));
    $area = trim((string) ($data['area'] ?? ''));
    $equipo = trim((string) ($data['equipo'] ?? ''));
    $problema = trim((string) ($data['problema'] ?? ''));
    $prioridad = normalizePriority((string) ($data['prioridad'] ?? 'Media'));

    if ($usuario === '' || $area === '' || $problema === '') {
        Response::error('Completa usuario, area y problema.', 422);
    }

    $db->beginTransaction();
    try {
        $next = nextTicketCode($db);
        $stmt = $db->prepare("INSERT INTO dbo.sn_tickets
            (codigo, usuario_reporta, area, equipo, problema, prioridad, estado, registrado_por, registrado_en)
            VALUES (:codigo, :usuario, :area, :equipo, :problema, :prioridad, 'Nuevo', :registrado_por, SYSDATETIME())");
        $stmt->execute([
            'codigo' => $next,
            'usuario' => $usuario,
            'area' => $area,
            'equipo' => $equipo !== '' ? $equipo : null,
            'problema' => $problema,
            'prioridad' => $prioridad,
            'registrado_por' => (int) ($user['id'] ?? 0) ?: null,
        ]);
        $ticketId = (int) $db->lastInsertId();
        event($db, $ticketId, 'creado', 'Ticket creado.', (int) ($user['id'] ?? 0));
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    Response::success(['ticket' => fetchTicketByCode($db, $next)], 'Ticket registrado.');
}

function asignar(PDO $db, Auth $auth): void
{
    $user = $auth->requirePolicy('soporte.tickets.asignar', SUPPORT_RESOURCE);
    ensureSchema($db);
    $data = Request::json();
    $code = trim((string) ($data['codigo'] ?? ''));
    if ($code === '') {
        Response::error('Ticket no indicado.', 422);
    }

    $technicianId = isset($data['tecnico_id']) && (int) $data['tecnico_id'] > 0
        ? (int) $data['tecnico_id']
        : firstTechnicianId($db);

    if ($technicianId <= 0) {
        Response::error('No hay tecnicos activos configurados.', 422);
    }

    $stmt = $db->prepare("UPDATE dbo.sn_tickets
        SET tecnico_id = :tecnico_id, estado = 'Asignado', asignado_en = COALESCE(asignado_en, SYSDATETIME()), actualizado_en = SYSDATETIME()
        WHERE codigo = :codigo AND estado NOT IN ('Resuelto', 'Cerrado', 'Anulado')");
    $stmt->execute(['tecnico_id' => $technicianId, 'codigo' => $code]);

    $ticket = fetchTicketByCode($db, $code);
    if ($ticket === null) {
        Response::error('Ticket no encontrado o ya cerrado.', 404);
    }

    event($db, (int) $ticket['row_id'], 'asignado', 'Ticket asignado a tecnico.', (int) ($user['id'] ?? 0));
    Response::success(['ticket' => $ticket], 'Ticket asignado.');
}

function resolver(PDO $db, Auth $auth): void
{
    $user = $auth->requirePolicy('soporte.tickets.resolver', SUPPORT_RESOURCE);
    ensureSchema($db);
    $code = trim((string) (Request::json()['codigo'] ?? ''));
    if ($code === '') {
        Response::error('Ticket no indicado.', 422);
    }

    $stmt = $db->prepare("UPDATE dbo.sn_tickets
        SET estado = 'Resuelto', resuelto_en = SYSDATETIME(), actualizado_en = SYSDATETIME()
        WHERE codigo = :codigo AND estado NOT IN ('Resuelto', 'Cerrado', 'Anulado')");
    $stmt->execute(['codigo' => $code]);
    $ticket = fetchTicketByCode($db, $code);
    if ($ticket === null) {
        Response::error('Ticket no encontrado.', 404);
    }

    event($db, (int) $ticket['row_id'], 'resuelto', 'Ticket resuelto.', (int) ($user['id'] ?? 0));
    Response::success(['ticket' => $ticket], 'Ticket resuelto.');
}

function eliminar(PDO $db, Auth $auth): void
{
    $auth->requirePolicy('sistema.soporte.admin', SUPPORT_RESOURCE);
    ensureSchema($db);
    $code = trim((string) (Request::json()['codigo'] ?? ''));
    if ($code === '') {
        Response::error('Ticket no indicado.', 422);
    }

    $ticket = fetchTicketByCode($db, $code);
    if ($ticket === null) {
        Response::error('Ticket no encontrado.', 404);
    }

    $stmt = $db->prepare("UPDATE dbo.sn_tickets SET estado = 'Anulado', actualizado_en = SYSDATETIME() WHERE codigo = :codigo");
    $stmt->execute(['codigo' => $code]);
    Response::success([], 'Ticket anulado.');
}

function tecnicos(PDO $db, Auth $auth): void
{
    $auth->requirePolicy('sistema.soporte.entrar', SUPPORT_RESOURCE);
    ensureSchema($db);
    Response::success(['technicians' => fetchTechnicians($db)]);
}

function perfil(PDO $db, Auth $auth): void
{
    $user = $auth->requirePolicy('sistema.soporte.entrar', SUPPORT_RESOURCE);
    ensureProfileSchema($db);
    $profile = fetchProfile($db, (int) ($user['id'] ?? 0), $user);
    $user['perfil'] = $profile;
    Response::success([
        'user' => $user,
        'perfil' => $profile,
    ]);
}

function guardarPerfil(PDO $db, Auth $auth): void
{
    $user = $auth->requirePolicy('sistema.soporte.entrar', SUPPORT_RESOURCE);
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        Response::error('Sesion invalida.', 401);
    }

    ensureProfileSchema($db);
    $data = Request::json();
    $current = fetchProfile($db, $userId, $user);

    $name = trim((string) ($data['nombre_visible'] ?? $current['nombre_visible'] ?? ''));
    $color = normalizeHexColor((string) ($data['color_principal'] ?? $current['color_principal'] ?? '#dc2626'));
    $avatar = trim((string) ($data['avatar_url'] ?? $current['avatar_url'] ?? ''));

    if ($name === '') {
        $name = (string) ($user['nombre'] ?? $user['email'] ?? $user['dni'] ?? 'Usuario');
    }

    $stmt = $db->prepare("MERGE dbo.core_usuario_perfiles AS target
        USING (SELECT :usuario_id AS usuario_id) AS source
        ON target.usuario_id = source.usuario_id
        WHEN MATCHED THEN UPDATE SET
            nombre_visible = :nombre_visible_update,
            color_principal = :color_principal_update,
            avatar_url = :avatar_url_update,
            actualizado_en = SYSDATETIME()
        WHEN NOT MATCHED THEN INSERT
            (usuario_id, nombre_visible, cargo_area, color_principal, avatar_url, creado_en, actualizado_en)
            VALUES
            (:usuario_id_insert, :nombre_visible_insert, :cargo_area_insert, :color_principal_insert, :avatar_url_insert, SYSDATETIME(), SYSDATETIME());");
    $stmt->execute([
        'usuario_id' => $userId,
        'nombre_visible_update' => clipText($name, 140),
        'color_principal_update' => $color,
        'avatar_url_update' => clipText($avatar, 500),
        'usuario_id_insert' => $userId,
        'nombre_visible_insert' => clipText($name, 140),
        'cargo_area_insert' => (string) ($current['cargo_area'] ?? 'Mesa de ayuda TI'),
        'color_principal_insert' => $color,
        'avatar_url_insert' => clipText($avatar, 500),
    ]);

    $profile = fetchProfile($db, $userId, $user);
    $user['perfil'] = $profile;
    Response::success([
        'user' => $user,
        'perfil' => $profile,
    ], 'Perfil guardado.');
}

function ensureSchema(PDO $db): void
{
    foreach (['dbo.sn_tickets', 'dbo.sn_tecnicos', 'dbo.sn_ticket_eventos'] as $table) {
        $stmt = $db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END");
        $stmt->execute(['table_name' => $table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            Response::error('Faltan tablas de Soporte NEO. Ejecuta database/sqlserver/00_instalar_soporte_neo_GESTION_INF.sql en GESTION_INF.', 503);
        }
    }
}

function ensureProfileSchema(PDO $db): void
{
    if (!tableExists($db, 'dbo.core_usuario_perfiles')) {
        $db->exec("CREATE TABLE dbo.core_usuario_perfiles (
            usuario_id INT NOT NULL PRIMARY KEY,
            nombre_visible NVARCHAR(140) NULL,
            cargo_area NVARCHAR(160) NULL,
            color_principal VARCHAR(7) NULL,
            avatar_url NVARCHAR(500) NULL,
            creado_en DATETIME2(0) NOT NULL CONSTRAINT DF_core_usuario_perfiles_creado DEFAULT SYSDATETIME(),
            actualizado_en DATETIME2(0) NULL
        )");
    }

    $columns = [
        'nombre_visible' => 'NVARCHAR(140) NULL',
        'cargo_area' => 'NVARCHAR(160) NULL',
        'color_principal' => 'VARCHAR(7) NULL',
        'avatar_url' => 'NVARCHAR(500) NULL',
        'creado_en' => 'DATETIME2(0) NULL',
        'actualizado_en' => 'DATETIME2(0) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!columnExists($db, 'core_usuario_perfiles', $column)) {
            $db->exec("ALTER TABLE dbo.core_usuario_perfiles ADD {$column} {$definition}");
        }
    }
}

function fetchProfile(PDO $db, int $userId, array $tokenUser): array
{
    $stmt = $db->prepare("SELECT TOP 1
            u.id,
            u.email,
            u.dni,
            COALESCE(NULLIF(p.nombre_visible, ''), NULLIF(LTRIM(RTRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')))), ''), u.email, u.dni, 'Usuario') AS nombre_visible,
            COALESCE(NULLIF(p.cargo_area, ''), :cargo_area) AS cargo_area,
            COALESCE(NULLIF(p.color_principal, ''), '#dc2626') AS color_principal,
            COALESCE(p.avatar_url, '') AS avatar_url
        FROM dbo.core_usuarios u
        LEFT JOIN dbo.core_usuario_perfiles p ON p.usuario_id = u.id
        WHERE u.id = :usuario_id");
    $stmt->execute([
        'usuario_id' => $userId,
        'cargo_area' => (string) ($tokenUser['cargo'] ?? $tokenUser['rol'] ?? $tokenUser['rol_nombre'] ?? 'Mesa de ayuda TI'),
    ]);
    $row = $stmt->fetch() ?: [];

    return [
        'nombre_visible' => (string) ($row['nombre_visible'] ?? $tokenUser['nombre'] ?? 'Usuario'),
        'cargo_area' => (string) ($row['cargo_area'] ?? 'Mesa de ayuda TI'),
        'color_principal' => normalizeHexColor((string) ($row['color_principal'] ?? '#dc2626')),
        'avatar_url' => (string) ($row['avatar_url'] ?? ''),
    ];
}

function normalizeHexColor(string $value): string
{
    $color = strtolower(trim($value));
    if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
        return '#dc2626';
    }

    return $color;
}

function clipText(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END");
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*)
        FROM sys.columns
        WHERE object_id = OBJECT_ID(:table_name, 'U')
          AND name = :column_name");
    $stmt->execute([
        'table_name' => 'dbo.' . $table,
        'column_name' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function fetchTickets(PDO $db, int $limit): array
{
    $stmt = $db->prepare("SELECT TOP ($limit)
            t.id AS row_id, t.codigo, t.usuario_reporta AS usuario, t.area, t.equipo, t.problema,
            t.prioridad, t.estado, t.registrado_en, t.asignado_en, t.resuelto_en,
            tec.id AS tecnico_id, COALESCE(NULLIF(tec.nombre_visible, ''), NULLIF(LTRIM(RTRIM(CONCAT(COALESCE(cu.nombres, ''), ' ', COALESCE(cu.apellidos, '')))), ''), cu.email, cu.dni, 'Tecnico') AS tecnico
        FROM dbo.sn_tickets t
        LEFT JOIN dbo.sn_tecnicos tec ON tec.id = t.tecnico_id
        LEFT JOIN dbo.core_usuarios cu ON cu.id = tec.usuario_id
        WHERE t.estado <> 'Anulado'
        ORDER BY t.registrado_en DESC, t.id DESC");
    $stmt->execute();
    return array_map('formatTicket', $stmt->fetchAll());
}

function fetchTicketByCode(PDO $db, string $code): ?array
{
    $stmt = $db->prepare("SELECT TOP 1
            t.id AS row_id, t.codigo, t.usuario_reporta AS usuario, t.area, t.equipo, t.problema,
            t.prioridad, t.estado, t.registrado_en, t.asignado_en, t.resuelto_en,
            tec.id AS tecnico_id, COALESCE(NULLIF(tec.nombre_visible, ''), NULLIF(LTRIM(RTRIM(CONCAT(COALESCE(cu.nombres, ''), ' ', COALESCE(cu.apellidos, '')))), ''), cu.email, cu.dni, 'Tecnico') AS tecnico
        FROM dbo.sn_tickets t
        LEFT JOIN dbo.sn_tecnicos tec ON tec.id = t.tecnico_id
        LEFT JOIN dbo.core_usuarios cu ON cu.id = tec.usuario_id
        WHERE t.codigo = :codigo AND t.estado <> 'Anulado'");
    $stmt->execute(['codigo' => $code]);
    $row = $stmt->fetch();
    return $row ? formatTicket($row) : null;
}

function fetchTechnicians(PDO $db): array
{
    syncTechniciansFromGroup($db);

    $stmt = $db->query("SELECT tec.id, tec.usuario_id, COALESCE(NULLIF(tec.nombre_visible, ''), NULLIF(LTRIM(RTRIM(CONCAT(COALESCE(cu.nombres, ''), ' ', COALESCE(cu.apellidos, '')))), ''), cu.email, cu.dni, 'Tecnico') AS nombre, tec.especialidad, tec.estado
        FROM dbo.sn_tecnicos tec
        LEFT JOIN dbo.core_usuarios cu ON cu.id = tec.usuario_id
        WHERE tec.estado = 'activo'
        ORDER BY nombre");
    $items = $stmt->fetchAll();

    if ($items !== []) {
        return $items;
    }

    $sql = "SELECT u.id AS usuario_id, COALESCE(NULLIF(LTRIM(RTRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')))), ''), u.email, u.dni, 'Tecnico') AS nombre
        FROM dbo.core_usuarios u
        INNER JOIN dbo.core_grupo_usuarios gu ON gu.usuario_id = u.id AND gu.estado = 'activo'
        INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id AND g.estado = 'activo'
        WHERE g.codigo = 'soporte_tecnico' AND u.estado = 'activo'
        ORDER BY nombre";
    try {
        return $db->query($sql)->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function syncTechniciansFromGroup(PDO $db): void
{
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM dbo.sn_tecnicos WHERE estado = 'activo'");
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $db->exec("INSERT INTO dbo.sn_tecnicos (usuario_id, nombre_visible, especialidad, estado, creado_en)
            SELECT u.id, COALESCE(NULLIF(LTRIM(RTRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, '')))), ''), u.email, u.dni, 'Tecnico'), N'Soporte TI', 'activo', SYSDATETIME()
            FROM dbo.core_usuarios u
            INNER JOIN dbo.core_grupo_usuarios gu ON gu.usuario_id = u.id AND gu.estado = 'activo'
            INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id AND g.estado = 'activo'
            WHERE g.codigo = 'soporte_tecnico'
              AND u.estado = 'activo'
              AND NOT EXISTS (SELECT 1 FROM dbo.sn_tecnicos st WHERE st.usuario_id = u.id)");
    } catch (Throwable) {
        return;
    }
}
function firstTechnicianId(PDO $db): int
{
    syncTechniciansFromGroup($db);
    $stmt = $db->query("SELECT TOP 1 id FROM dbo.sn_tecnicos WHERE estado = 'activo' ORDER BY id");
    return (int) ($stmt->fetchColumn() ?: 0);
}

function nextTicketCode(PDO $db): string
{
    $stmt = $db->query("SELECT ISNULL(MAX(TRY_CONVERT(INT, REPLACE(codigo, 'TKT-', ''))), 0) + 1 FROM dbo.sn_tickets WITH (UPDLOCK, HOLDLOCK)");
    return 'TKT-' . str_pad((string) ((int) $stmt->fetchColumn()), 4, '0', STR_PAD_LEFT);
}

function event(PDO $db, int $ticketId, string $type, string $detail, int $userId): void
{
    if ($ticketId <= 0) {
        return;
    }

    $stmt = $db->prepare("INSERT INTO dbo.sn_ticket_eventos (ticket_id, tipo, detalle, creado_por, creado_en)
        VALUES (:ticket_id, :tipo, :detalle, :creado_por, SYSDATETIME())");
    $stmt->execute([
        'ticket_id' => $ticketId,
        'tipo' => $type,
        'detalle' => $detail,
        'creado_por' => $userId > 0 ? $userId : null,
    ]);
}

function stats(array $tickets): array
{
    return [
        'Total' => count($tickets),
        'Nuevos' => count(array_filter($tickets, static fn (array $ticket): bool => $ticket['estado'] === 'Nuevo')),
        'Asignados' => count(array_filter($tickets, static fn (array $ticket): bool => $ticket['estado'] === 'Asignado')),
        'En Atencion' => count(array_filter($tickets, static fn (array $ticket): bool => $ticket['estado'] === 'En Atencion')),
        'Resueltos' => count(array_filter($tickets, static fn (array $ticket): bool => $ticket['estado'] === 'Resuelto')),
    ];
}

function formatTicket(array $row): array
{
    return [
        'row_id' => (int) $row['row_id'],
        'id' => (string) $row['codigo'],
        'usuario' => (string) $row['usuario'],
        'area' => (string) $row['area'],
        'equipo' => (string) ($row['equipo'] ?? ''),
        'problema' => (string) $row['problema'],
        'prioridad' => (string) $row['prioridad'],
        'estado' => (string) $row['estado'],
        'tecnico_id' => isset($row['tecnico_id']) ? (int) $row['tecnico_id'] : null,
        'tecnico' => (string) ($row['tecnico'] ?? ''),
        'registrado' => formatDate($row['registrado_en'] ?? null),
        'canal' => 'Formulario',
    ];
}

function normalizePriority(string $value): string
{
    $allowed = ['Urgente', 'Alta', 'Media', 'Baja'];
    return in_array($value, $allowed, true) ? $value : 'Media';
}

function formatDate(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i');
    }
    $text = (string) ($value ?? '');
    return $text !== '' ? substr($text, 0, 16) : '-';
}






