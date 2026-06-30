<?php

declare(strict_types=1);

namespace TiNeo\Core;

use PDO;
use TiNeo\Services\JwtService;

final class Auth
{
    private const ROLE_ALIASES = [
        'admin' => ['admin', 'administrador', 'super_admin', 'super_administrador', 'st_admin'],
        'emision' => ['emision', 'st_emision', 'ventanilla', 'st_ventanilla', 'admin', 'administrador', 'super_admin', 'super_administrador', 'st_admin'],
        'ventanilla' => ['ventanilla', 'st_ventanilla', 'admin', 'administrador', 'super_admin', 'super_administrador', 'st_admin'],
    ];

    public function __construct(private readonly JwtService $jwt, private readonly ?PDO $db = null)
    {
    }

    public function user(): array
    {
        $token = Request::bearerToken();

        if ($token === null) {
            Response::error('Sesion no iniciada.', 401);
        }

        try {
            $payload = $this->jwt->validate($token);
            $user = $payload['user'] ?? null;
            if (!is_array($user)) {
                Response::error('Sesion invalida.', 401);
            }

            return $user;
        } catch (\Throwable $exception) {
            Response::error('Sesion invalida o vencida.', 401);
        }
    }

    public function requireAny(array $roles): array
    {
        $user = $this->user();
        if ($this->roleMatches($user, $roles)) {
            return $user;
        }

        Response::error('No tienes permiso para esta accion.', 403);
    }

    public function requirePolicy(string $action, string $resource = 'sistema:tickets', array $fallbackRoles = []): array
    {
        $user = $this->user();
        if ($this->canPolicyForUser($user, $action, $resource, $fallbackRoles)) {
            return $user;
        }

        Response::error('No tienes permiso para esta accion.', 403);
    }

    public function requireAnyPolicy(array $actions, string $resource = 'sistema:tickets'): array
    {
        $user = $this->user();
        foreach ($actions as $action) {
            if ($this->canPolicyForUser($user, (string) $action, $resource, [])) {
                return $user;
            }
        }

        Response::error('No tienes permiso para esta accion.', 403);
    }

    public function canPolicy(string $action, string $resource = 'sistema:tickets', array $fallbackRoles = []): bool
    {
        return $this->canPolicyForUser($this->user(), $action, $resource, $fallbackRoles);
    }

    public function isTicketsScreenOnlyUser(): bool
    {
        $user = $this->user();
        $userId = (int) ($user['id'] ?? 0);
        if ($this->db === null || $userId <= 0) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT g.codigo
                 FROM dbo.core_grupo_usuarios gu
                 INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id
                 WHERE gu.usuario_id = :user_id
                   AND gu.estado = 'activo'
                   AND g.estado = 'activo'
                   AND (
                        g.codigo LIKE 'tickets_%'
                        OR g.codigo = 'super_admins'
                   )
                 ORDER BY g.codigo"
            );
            $stmt->execute(['user_id' => $userId]);
            $groups = array_map(static fn (array $row): string => (string) $row['codigo'], $stmt->fetchAll());
        } catch (\Throwable) {
            return false;
        }

        return $groups !== [] && $groups === ['tickets_pantalla'];
    }

    private function canPolicyForUser(array $user, string $action, string $resource, array $fallbackRoles = []): bool
    {
        return $this->hasPolicy((int) ($user['id'] ?? 0), $action, $resource);
    }

    private function roleMatches(array $user, array $roles): bool
    {
        $role = $this->normalizeRole((string) ($user['rol'] ?? $user['rol_nombre'] ?? ''));

        foreach ($roles as $requiredRole) {
            $allowed = self::ROLE_ALIASES[$this->normalizeRole((string) $requiredRole)] ?? [$this->normalizeRole((string) $requiredRole)];
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasPolicy(int $userId, string $action, string $resource): bool
    {
        if ($this->db === null || $userId <= 0) {
            return false;
        }

        try {
            $queries = [];
            $params = [
                'user_id_direct' => $userId,
                'user_id_group' => $userId,
                'action_exact' => $action,
                'action_prefix' => $action,
                'resource_exact' => $resource,
            ];

            if ($this->tableExists('dbo.core_usuario_politicas')) {
                $queries[] = "SELECT
                    cp.efecto,
                    cp.accion,
                    cp.recurso,
                    up.permitido
                 FROM dbo.core_usuario_politicas up
                 INNER JOIN dbo.core_politicas cp ON cp.id = up.politica_id
                 WHERE up.usuario_id = :user_id_direct
                   AND up.estado = 'activo'
                   AND cp.estado = 'activo'";
            }

            $queries[] = "SELECT
                    cp.efecto,
                    cp.accion,
                    cp.recurso,
                    gp.permitido
                 FROM dbo.core_grupo_usuarios gu
                 INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id
                 INNER JOIN dbo.core_grupo_politicas gp ON gp.grupo_id = g.id
                 INNER JOIN dbo.core_politicas cp ON cp.id = gp.politica_id
                 WHERE gu.usuario_id = :user_id_group
                   AND gu.estado = 'activo'
                   AND g.estado = 'activo'
                   AND cp.estado = 'activo'
                ";

            $sql = 'SELECT * FROM (' . implode("\nUNION ALL\n", $queries) . ") p
                   WHERE (
                        p.accion = :action_exact
                        OR p.accion = '*'
                        OR p.accion = 'sistema.admin'
                        OR (:action_prefix LIKE 'tickets.%' AND p.accion = 'tickets.*')
                   )
                   AND (
                        p.recurso = :resource_exact
                        OR p.recurso = '*'
                        OR p.recurso = 'sistema:*'
                   )";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return false;
        }

        $allowed = false;
        foreach ($rows as $row) {
            $effect = strtolower((string) ($row['efecto'] ?? 'allow'));
            $permitted = (int) ($row['permitido'] ?? 1) === 1;

            if (!$permitted || $effect === 'deny') {
                return false;
            }

            if ($effect === 'allow') {
                $allowed = true;
            }
        }

        return $allowed;
    }

    private function tableExists(string $table): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END");
            $stmt->execute(['table_name' => $table]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        $role = str_replace([' ', '-'], '_', $role);

        return $role;
    }
}
