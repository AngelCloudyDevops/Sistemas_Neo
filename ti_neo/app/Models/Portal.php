<?php

declare(strict_types=1);

namespace TiNeo\Models;

use PDO;
use TiNeo\Core\Sql;

final class Portal
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function systemsForUser(array $user): array
    {
        $systems = $this->allActiveSystems();
        $policies = $this->effectivePolicies((int) ($user['id'] ?? 0));
        $allowed = [];

        foreach ($systems as $system) {
            $resource = 'sistema:' . (string) $system['codigo'];
            if (!$this->isAllowed($policies, 'sistema.entrar', $resource)) {
                continue;
            }

            $system['puede_admin'] = $this->isAllowed($policies, 'sistema.admin', $resource) ? 1 : 0;
            $allowed[] = $system;
        }

        return $allowed;
    }

    private function allActiveSystems(): array
    {
        $sql = 'SELECT id, codigo, nombre, descripcion, url_base, icono, orden
                FROM dbo.neo_sistemas
                WHERE estado = \'activo\'
                ORDER BY orden ASC, nombre ASC';

        if (!Sql::isSqlServer($this->db)) {
            $sql = str_replace('dbo.', '', $sql);
        }

        return $this->db->query($sql)->fetchAll() ?: [];
    }

    private function effectivePolicies(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $queries = [];
        $params = [];

        if ($this->tableExists('dbo.core_usuario_politicas')) {
            $queries[] = "SELECT
                    p.codigo,
                    CASE WHEN up.permitido = 0 OR p.efecto = 'Deny' THEN 'Deny' ELSE 'Allow' END AS efecto,
                    p.accion,
                    p.recurso
                FROM dbo.core_usuario_politicas up
                INNER JOIN dbo.core_politicas p ON p.id = up.politica_id
                WHERE up.usuario_id = :usuario_id_direct
                  AND up.estado = 'activo'
                  AND p.estado = 'activo'";
            $params['usuario_id_direct'] = $userId;
        }

        $queries[] = "SELECT
                p.codigo,
                CASE WHEN gp.permitido = 0 OR p.efecto = 'Deny' THEN 'Deny' ELSE 'Allow' END AS efecto,
                p.accion,
                p.recurso
            FROM dbo.core_grupo_usuarios gu
            INNER JOIN dbo.core_grupo_politicas gp ON gp.grupo_id = gu.grupo_id
            INNER JOIN dbo.core_politicas p ON p.id = gp.politica_id
            WHERE gu.usuario_id = :usuario_id_group_policy
              AND gu.estado = 'activo'
              AND p.estado = 'activo'";
        $params['usuario_id_group_policy'] = $userId;

        $sql = implode("\nUNION ALL\n", $queries);
        if (!Sql::isSqlServer($this->db)) {
            $sql = str_replace('dbo.', '', $sql);
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function isAllowed(array $policies, string $action, string $resource): bool
    {
        $hasAllow = false;

        foreach ($policies as $policy) {
            if (!$this->matches((string) ($policy['accion'] ?? ''), $action)) {
                continue;
            }

            if (!$this->matches((string) ($policy['recurso'] ?? ''), $resource)) {
                continue;
            }

            if ((string) ($policy['efecto'] ?? '') === 'Deny') {
                return false;
            }

            if ((string) ($policy['efecto'] ?? '') === 'Allow') {
                $hasAllow = true;
            }
        }

        return $hasAllow;
    }

    private function matches(string $pattern, string $value): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '*' || $pattern === $value) {
            return true;
        }

        if (str_ends_with($pattern, '*')) {
            return str_starts_with($value, rtrim($pattern, '*'));
        }

        return false;
    }

    private function tableExists(string $table): bool
    {
        $safeTable = str_replace("'", "''", $table);

        try {
            if (Sql::isSqlServer($this->db)) {
                $stmt = $this->db->query("SELECT OBJECT_ID(N'{$safeTable}', N'U')");
                return $stmt->fetchColumn() !== null;
            }

            $stmt = $this->db->query("SHOW TABLES LIKE '{$safeTable}'");
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function logAccess(?int $userId, ?int $systemId, string $action, ?string $ip, ?string $userAgent): void
    {
        $sql = 'INSERT INTO dbo.neo_logs_acceso (usuario_id, sistema_id, accion, ip, user_agent, creado_en)
                VALUES (:usuario_id, :sistema_id, :accion, :ip, :user_agent, ' . Sql::now($this->db) . ')';

        if (!Sql::isSqlServer($this->db)) {
            $sql = str_replace('dbo.', '', $sql);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'usuario_id' => $userId,
            'sistema_id' => $systemId,
            'accion' => $action,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
