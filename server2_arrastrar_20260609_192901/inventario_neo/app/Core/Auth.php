<?php

declare(strict_types=1);

namespace InventarioNeo\Core;

use PDO;
use InventarioNeo\Services\JwtService;

final class Auth
{
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
        } catch (\Throwable) {
            Response::error('Sesion invalida o vencida.', 401);
        }
    }

    public function requirePolicy(string $action, string $resource = 'sistema:inventario'): array
    {
        $user = $this->user();
        if ($this->canPolicyForUser($user, $action, $resource)) {
            return $user;
        }

        Response::error('No tienes permiso para esta accion.', 403);
    }

    public function requireAnyPolicy(array $actions, string $resource = 'sistema:inventario'): array
    {
        $user = $this->user();
        foreach ($actions as $action) {
            if ($this->canPolicyForUser($user, (string) $action, $resource)) {
                return $user;
            }
        }

        Response::error('No tienes permiso para esta accion.', 403);
    }

    private function canPolicyForUser(array $user, string $action, string $resource): bool
    {
        return $this->hasPolicy((int) ($user['id'] ?? 0), $action, $resource);
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

            if ($this->tableExists(Sql::isSqlServer($this->db) ? 'dbo.core_usuario_politicas' : 'core_usuario_politicas')) {
                $queries[] = Sql::isSqlServer($this->db)
                    ? "SELECT cp.efecto, cp.accion, cp.recurso, up.permitido
                       FROM dbo.core_usuario_politicas up
                       INNER JOIN dbo.core_politicas cp ON cp.id = up.politica_id
                       WHERE up.usuario_id = :user_id_direct
                         AND up.estado = 'activo'
                         AND cp.estado = 'activo'"
                    : "SELECT cp.efecto, cp.accion, cp.recurso, up.permitido
                       FROM core_usuario_politicas up
                       INNER JOIN core_politicas cp ON cp.id = up.politica_id
                       WHERE up.usuario_id = :user_id_direct
                         AND up.estado = 'activo'
                         AND cp.estado = 'activo'";
            }

            $queries[] = Sql::isSqlServer($this->db)
                ? "SELECT cp.efecto, cp.accion, cp.recurso, gp.permitido
                   FROM dbo.core_grupo_usuarios gu
                   INNER JOIN dbo.core_grupos g ON g.id = gu.grupo_id
                   INNER JOIN dbo.core_grupo_politicas gp ON gp.grupo_id = g.id
                   INNER JOIN dbo.core_politicas cp ON cp.id = gp.politica_id
                   WHERE gu.usuario_id = :user_id_group
                     AND gu.estado = 'activo'
                     AND g.estado = 'activo'
                     AND cp.estado = 'activo'"
                : "SELECT cp.efecto, cp.accion, cp.recurso, gp.permitido
                   FROM core_grupo_usuarios gu
                   INNER JOIN core_grupos g ON g.id = gu.grupo_id
                   INNER JOIN core_grupo_politicas gp ON gp.grupo_id = g.id
                   INNER JOIN core_politicas cp ON cp.id = gp.politica_id
                   WHERE gu.usuario_id = :user_id_group
                     AND gu.estado = 'activo'
                     AND g.estado = 'activo'
                     AND cp.estado = 'activo'";

            $sql = 'SELECT * FROM (' . implode("\nUNION ALL\n", $queries) . ") p
                   WHERE (
                        p.accion = :action_exact
                        OR p.accion = '*'
                        OR p.accion = 'sistema.admin'
                        OR (:action_prefix LIKE 'inventario.%' AND p.accion = 'inventario.*')
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
            if (Sql::isSqlServer($this->db)) {
                $stmt = $this->db->prepare("SELECT CASE WHEN OBJECT_ID(:table_name, 'U') IS NULL THEN 0 ELSE 1 END");
                $stmt->execute(['table_name' => $table]);
                return (int) $stmt->fetchColumn() === 1;
            }

            $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
            $stmt->execute(['table_name' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
