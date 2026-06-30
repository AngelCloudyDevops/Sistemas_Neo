<?php

declare(strict_types=1);

namespace TiNeo\Models;

use PDO;
use TiNeo\Core\Sql;

final class Specialty
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function active(): array
    {
        $stmt = $this->db->query(
            'SELECT id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE estado = \'activo\'
             ORDER BY nombre ASC'
        );

        return $stmt->fetchAll();
    }

    public function findActive(int $id): ?array
    {
        $sql = Sql::isSqlServer($this->db)
            ? 'SELECT TOP 1 id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE id = :id AND estado = \'activo\''
            : 'SELECT id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE id = :id AND estado = \'activo\'
             LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $specialty = $stmt->fetch();

        return is_array($specialty) ? $specialty : null;
    }
}
