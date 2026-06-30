<?php

declare(strict_types=1);

namespace Neo\Models;

use Neo\Core\Sql;
use PDO;

final class Portal
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function systemsForUser(array $user): array
    {
        if (!$this->hasIamPolicyShape()) {
            return [];
        }

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

    private function effectivePolicies(int $userId): array
    {
        $queries = [];
        $params = [];

        if ($this->tableExists('dbo.core_usuario_politicas')) {
            $queries[] = "SELECT
                    p.codigo,
                    CASE WHEN p.efecto = 'Deny' THEN 'Deny' ELSE 'Allow' END AS efecto,
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

    private function allActiveSystems(): array
    {
        $visualClassSelect = $this->columnExists('dbo.neo_sistemas', 'clase_visual')
            ? 'clase_visual'
            : "'' AS clase_visual";
        $sql = "SELECT id, codigo, nombre, descripcion, url_base, icono, {$visualClassSelect}, orden, CAST(1 AS bit) AS puede_admin
                FROM dbo.neo_sistemas
                WHERE estado = 'activo'
                ORDER BY orden ASC, nombre ASC";

        if (!Sql::isSqlServer($this->db)) {
            $sql = str_replace('dbo.', '', $sql);
        }

        return $this->db->query($sql)->fetchAll() ?: [];
    }

    private function hasIamPolicyShape(): bool
    {
        return $this->tableExists('dbo.core_politicas')
            && $this->columnExists('dbo.core_politicas', 'efecto')
            && $this->columnExists('dbo.core_politicas', 'accion')
            && $this->columnExists('dbo.core_politicas', 'recurso');
    }

    private function tableExists(string $table): bool
    {
        try {
            $name = str_replace('dbo.', '', $table);
            $stmt = $this->db->prepare("SELECT 1 FROM sys.tables WHERE name = :name");
            $stmt->execute(['name' => $name]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $name = str_replace('dbo.', '', $table);
            $stmt = $this->db->prepare(
                "SELECT 1
                 FROM sys.columns c
                 INNER JOIN sys.tables t ON t.object_id = c.object_id
                 WHERE t.name = :table_name AND c.name = :column_name"
            );
            $stmt->execute(['table_name' => $name, 'column_name' => $column]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
