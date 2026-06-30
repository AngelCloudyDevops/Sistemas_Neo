<?php

declare(strict_types=1);

namespace InventarioNeo\Services;

use InventarioNeo\Core\Sql;
use PDO;

final class AuditService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function log(?int $userId, string $action, string $description, ?int $systemId = null): void
    {
        try {
            $sql = 'INSERT INTO dbo.neo_logs_acceso
                    (usuario_id, sistema_id, accion, ip, user_agent, creado_en)
                    VALUES (:usuario_id, :sistema_id, :accion, :ip, :user_agent, ' . Sql::now($this->db) . ')';

            if (!Sql::isSqlServer($this->db)) {
                $sql = str_replace('dbo.', '', $sql);
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'usuario_id' => $userId,
                'sistema_id' => $systemId,
                'accion' => $action . ': ' . mb_substr($description, 0, 180),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (\Throwable) {
            // La auditoria no debe tumbar el login del portal.
        }
    }
}
