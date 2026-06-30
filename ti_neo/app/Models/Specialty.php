<?php

declare(strict_types=1);

namespace TiNeo\Models;

use PDO;
use TiNeo\Core\Sql;

final class Specialty
{
    private const INTERNAL_BATCH_SPECIALTY = 'LOTE / REIMPRESION';

    public function __construct(private readonly PDO $db)
    {
    }

    public function active(): array
    {
        $internalFilter = $this->internalSpecialtyFilter();
        $stmt = $this->db->query(
            'SELECT id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE estado = \'activo\'
               ' . $internalFilter . '
             ORDER BY nombre ASC'
        );

        return $stmt->fetchAll();
    }

    public function findActive(int $id): ?array
    {
        $internalFilter = $this->internalSpecialtyFilter();
        $sql = Sql::isSqlServer($this->db)
            ? 'SELECT TOP 1 id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE id = :id AND estado = \'activo\'
               ' . $internalFilter
            : 'SELECT id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE id = :id AND estado = \'activo\'
               ' . $internalFilter . '
             LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $specialty = $stmt->fetch();

        return is_array($specialty) ? $specialty : null;
    }

    private function internalSpecialtyFilter(): string
    {
        $conditions = ["UPPER(nombre) <> '" . self::INTERNAL_BATCH_SPECIALTY . "'"];
        if ($this->columnExists('core_especialidades', 'es_interno')) {
            $conditions[] = 'COALESCE(es_interno, 0) = 0';
        }

        return 'AND ' . implode(' AND ', $conditions);
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            if (Sql::isSqlServer($this->db)) {
                $stmt = $this->db->prepare("SELECT CASE WHEN COL_LENGTH(:table_name, :column_name) IS NULL THEN 0 ELSE 1 END");
                $stmt->execute([
                    'table_name' => 'dbo.' . $table,
                    'column_name' => $column,
                ]);
                return (int) $stmt->fetchColumn() === 1;
            }

            $stmt = $this->db->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
            $stmt->execute(['column_name' => $column]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
