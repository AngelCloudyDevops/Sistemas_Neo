<?php

declare(strict_types=1);

namespace GsNeo\Core;

use PDO;
use GsNeo\Services\JwtService;

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

    public function requireSuperAdmin(): array
    {
        return $this->requirePolicy('sistema.gs.admin', 'sistema:gs');
    }

    public function requirePolicy(string $action, string $resource): array
    {
        $user = $this->user();

        if ($this->hasPolicy((int) ($user['id'] ?? 0), $action, $resource)) {
            return $user;
        }

        Response::error('No tienes permiso para usar Gestion de Usuarios.', 403);
    }

    public function canPolicy(string $action, string $resource): bool
    {
        $user = $this->user();

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
                'action_prefix_users' => $action,
                'action_prefix_groups' => $action,
                'action_prefix_policies' => $action,
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
                   AND cp.estado = 'activo'";

            $sql = 'SELECT * FROM (' . implode("\nUNION ALL\n", $queries) . ") p
                    WHERE (
                        p.accion = :action_exact
                        OR p.accion = '*'
                        OR p.accion = 'sistema.admin'
                        OR (:action_prefix_users LIKE 'usuarios.%' AND p.accion = 'usuarios.*')
                        OR (:action_prefix_groups LIKE 'grupos.%' AND p.accion = 'grupos.*')
                        OR (:action_prefix_policies LIKE 'politicas.%' AND p.accion = 'politicas.*')
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
}
