<?php

declare(strict_types=1);

use SoporteNeo\Core\Auth;
use SoporteNeo\Core\Database;
use SoporteNeo\Core\Request;
use SoporteNeo\Core\Response;
use SoporteNeo\Services\JwtService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::error('Metodo no permitido.', 405);
    }

    $input = Request::json();
    $login = strtolower(trim((string) ($input['login'] ?? $input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');

    if ($login === '' || $password === '') {
        Response::error('Ingrese usuario y contrasena validos.', 422);
    }

    $db = Database::connection($config);
    $user = findSupportUser($db, $login);
    if ($user === null || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        Response::error('Credenciales incorrectas.', 401);
    }

    $jwt = new JwtService($config);
    $auth = new Auth($jwt, $db);
    $userId = (int) $user['id'];
    $hasAccess = $auth->canPolicy('sistema.soporte.entrar', 'sistema:soporte', $userId)
        || $auth->canPolicy('sistema.soporte.admin', 'sistema:soporte', $userId)
        || $auth->canPolicy('soporte.tickets.crear', 'sistema:soporte', $userId)
        || $auth->canPolicy('soporte.tickets.asignar', 'sistema:soporte', $userId)
        || $auth->canPolicy('soporte.tickets.resolver', 'sistema:soporte', $userId);

    if (!$hasAccess) {
        Response::error('No tienes permiso para usar Gestion de Soporte NEO.', 403);
    }

    updateLastLogin($db, $userId);
    $token = $jwt->generate($user);
    unset($user['password_hash']);

    Response::success([
        'token_type' => 'Bearer',
        'access_token' => $token,
        'expires_in' => $jwt->ttl(),
        'user' => publicUser($user),
        'permissions' => $auth->supportPermissions($userId),
    ], 'Sesion iniciada.');
} catch (Throwable $exception) {
    error_log('[soporte_neo:login] ' . $exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}

function findSupportUser(PDO $db, string $login): ?array
{
    $hasProfile = tableExists($db, 'dbo.core_usuario_perfiles');
    $profileSelect = $hasProfile
        ? 'p.nombre_visible, p.cargo_area, p.color_principal, p.avatar_url'
        : "CAST(NULL AS NVARCHAR(255)) AS nombre_visible, CAST(NULL AS NVARCHAR(255)) AS cargo_area, CAST(NULL AS NVARCHAR(20)) AS color_principal, CAST(NULL AS NVARCHAR(500)) AS avatar_url";
    $profileJoin = $hasProfile ? 'LEFT JOIN dbo.core_usuario_perfiles p ON p.usuario_id = u.id' : '';

    $stmt = $db->prepare("SELECT TOP 1
            u.id,
            u.personal_id,
            u.rol_id,
            u.dni,
            u.nombres,
            u.apellidos,
            u.email,
            u.password_hash,
            u.must_change_password,
            u.estado,
            r.nombre AS rol_nombre,
            {$profileSelect}
        FROM dbo.core_usuarios u
        LEFT JOIN dbo.core_roles r ON r.id = u.rol_id
        {$profileJoin}
        WHERE (LOWER(u.email) = :login_email OR u.dni = :login_dni)
          AND u.estado = 'activo'");
    $stmt->execute([
        'login_email' => $login,
        'login_dni' => $login,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function updateLastLogin(PDO $db, int $userId): void
{
    try {
        if ((int) $db->query("SELECT CASE WHEN COL_LENGTH('dbo.core_usuarios', 'ultimo_login_en') IS NULL THEN 0 ELSE 1 END")->fetchColumn() !== 1) {
            return;
        }
        $stmt = $db->prepare('UPDATE dbo.core_usuarios SET ultimo_login_en = SYSDATETIME() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    } catch (Throwable) {
        return;
    }
}

function tableExists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END");
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

function publicUser(array $user): array
{
    $fullName = trim((string) (($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? '')));
    $displayName = trim((string) ($user['nombre_visible'] ?? '')) ?: $fullName ?: (string) ($user['email'] ?? 'Usuario');
    $roleArea = trim((string) ($user['cargo_area'] ?? '')) ?: (string) ($user['rol_nombre'] ?? 'Usuario');

    return [
        'id' => (int) $user['id'],
        'email' => (string) ($user['email'] ?? ''),
        'dni' => (string) ($user['dni'] ?? ''),
        'nombre' => $displayName,
        'rol' => (string) ($user['rol_nombre'] ?? ''),
        'perfil' => [
            'nombre_visible' => $displayName,
            'cargo_area' => $roleArea,
            'color_principal' => (string) ($user['color_principal'] ?? '#7c3aed'),
            'avatar_url' => (string) ($user['avatar_url'] ?? ''),
        ],
    ];
}
