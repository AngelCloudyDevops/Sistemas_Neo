<?php

declare(strict_types=1);

namespace TiNeo\Services;

use PDO;
use TiNeo\Core\Request;

final class AuditService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function log(?int $userId, string $action, string $entity, ?int $entityId = null, mixed $detail = null): void
    {
        try {
            if (is_array($detail) || is_object($detail)) {
                $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $stmt = $this->db->prepare(
                'INSERT INTO st_auditoria (usuario_id, accion, entidad, entidad_id, detalle, ip)
                 VALUES (:usuario_id, :accion, :entidad, :entidad_id, :detalle, :ip)'
            );

            $stmt->execute([
                'usuario_id' => $userId,
                'accion' => $action,
                'entidad' => $entity,
                'entidad_id' => $entityId,
                'detalle' => $detail === null ? null : (string) $detail,
                'ip' => Request::clientIp(),
            ]);
        } catch (\Throwable) {
            // La auditoria no debe bloquear operaciones de ventanilla.
        }
    }
}
