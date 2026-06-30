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

    public function systemsForRole(int $roleId): array
    {
        $sql = 'SELECT
                    s.id,
                    s.codigo,
                    s.nombre,
                    s.descripcion,
                    s.url_base,
                    s.icono,
                    s.orden,
                    p.puede_admin
                FROM dbo.neo_permisos_sistema p
                INNER JOIN dbo.neo_sistemas s ON s.id = p.sistema_id
                WHERE p.rol_id = :role_id
                  AND p.puede_ver = 1
                  AND s.estado = \'activo\'
                ORDER BY s.orden ASC, s.nombre ASC';

        if (!Sql::isSqlServer($this->db)) {
            $sql = str_replace('dbo.', '', $sql);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['role_id' => $roleId]);

        return $stmt->fetchAll() ?: [];
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
