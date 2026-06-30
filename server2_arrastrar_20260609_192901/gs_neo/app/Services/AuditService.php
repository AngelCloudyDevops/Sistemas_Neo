<?php

declare(strict_types=1);

namespace GsNeo\Services;

use PDO;
use GsNeo\Core\Request;
use GsNeo\Core\Sql;

final class AuditService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function log(?int $userId, string $action, string $description): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO dbo.core_logs_auditoria
                (usuario_id, accion, modulo, entidad, descripcion, ip, equipo_cliente, user_agent, creado_en)
             VALUES
                (:usuario_id, :accion, :modulo, :entidad, :descripcion, :ip, :equipo_cliente, :user_agent, ' . Sql::now($this->db) . ')'
        );

        $stmt->execute([
            'usuario_id' => $userId,
            'accion' => $action,
            'modulo' => 'gs_neo',
            'entidad' => 'core_usuarios',
            'descripcion' => $description,
            'ip' => Request::clientIp(),
            'equipo_cliente' => gethostname() ?: null,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    private function tableExists(): bool
    {
        $stmt = $this->db->query("SELECT OBJECT_ID(N'dbo.core_logs_auditoria', N'U')");
        return $stmt->fetchColumn() !== null;
    }
}
