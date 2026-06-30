<?php

declare(strict_types=1);

namespace TiNeo\Services;

use PDO;

final class MonthlyAppointmentsService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function summary(?string $mode = 'mes'): array
    {
        $mode = strtolower(trim((string) $mode));
        if ($mode !== 'hoy') {
            $mode = 'mes';
        }

        $stmt = $this->db->prepare($this->query());
        $stmt->execute(['modo' => $mode]);

        $groups = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }

            $doctor = mb_strtoupper(trim((string) ($row['doctor'] ?? '')), 'UTF-8');
            $specialty = trim((string) ($row['especialidad'] ?? ''));
            $hourRange = trim((string) ($row['hora'] ?? ''));
            $hour = (int) substr($hourRange, 0, 2);
            $shift = $hour < 13 ? 'manana' : 'tarde';
            $key = $specialty . '|' . $doctor . '|' . $shift;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'especialidad' => $specialty,
                    'doctor' => $doctor,
                    'turno' => $shift,
                    'cupos' => 0,
                ];
            }

            $groups[$key]['cupos'] += max(0, (int) ($row['CitasDisponibles'] ?? 0));
        }

        $items = array_values($groups);
        usort($items, static function (array $a, array $b): int {
            return [$a['turno'], $a['especialidad'], $a['doctor']] <=> [$b['turno'], $b['especialidad'], $b['doctor']];
        });

        $month = new \DateTimeImmutable('first day of this month');

        return [
            'modo' => $mode,
            'mes' => $month->format('Y-m'),
            'titulo' => 'Citas del mes de ' . $this->monthName((int) $month->format('n')) . ' de ' . $month->format('Y'),
            'manana' => array_values(array_filter($items, static fn (array $item): bool => $item['turno'] === 'manana')),
            'tarde' => array_values(array_filter($items, static fn (array $item): bool => $item['turno'] === 'tarde')),
        ];
    }

    private function query(): string
    {
        return "
            DECLARE @Hoy DATE = CAST(GETDATE() AS DATE);
            DECLARE @Ahora DATETIME = GETDATE();
            DECLARE @AhoraTime TIME = CAST(GETDATE() AS TIME);
            DECLARE @IdEspecialidad INT = NULL;
            DECLARE @MODO VARCHAR(10) = :modo;

            SELECT
                e.Nombre AS especialidad,
                e.IdEspecialidad,
                (em.ApellidoPaterno + ' ' + em.ApellidoMaterno) AS doctor,
                p.Fecha,
                CONVERT(VARCHAR(5), p.HoraInicio, 108) + ' - ' + CONVERT(VARCHAR(5), p.HoraFin, 108) AS hora,
                ca.SlotsTotales AS TotalCitas,
                ISNULL(ds.DeliveredSlots, 0) AS CitasEntregadas,
                CASE WHEN ca.SlotsElapsed - ISNULL(ds.DeliveredSlots, 0) > 0
                    THEN ca.SlotsElapsed - ISNULL(ds.DeliveredSlots, 0) ELSE 0 END AS CitasPerdidas,
                CASE
                    WHEN (ca.SlotsTotales - ISNULL(ds.DeliveredSlots, 0)
                        - CASE WHEN ca.SlotsElapsed - ISNULL(ds.DeliveredSlots, 0) > 0
                            THEN ca.SlotsElapsed - ISNULL(ds.DeliveredSlots, 0) ELSE 0 END) < 0
                    THEN 0
                    ELSE (ca.SlotsTotales - ISNULL(ds.DeliveredSlots, 0)
                        - CASE WHEN ca.SlotsElapsed - ISNULL(ds.DeliveredSlots, 0) > 0
                            THEN ca.SlotsElapsed - ISNULL(ds.DeliveredSlots, 0) ELSE 0 END)
                END AS CitasDisponibles
            FROM ProgramacionMedica p
            INNER JOIN Medicos m ON p.IdMedico = m.IdMedico
            INNER JOIN Empleados em ON em.IdEmpleado = m.IdEmpleado
            INNER JOIN Especialidades e ON p.IdEspecialidad = e.IdEspecialidad
            INNER JOIN Servicios s ON p.IdServicio = s.IdServicio
            OUTER APPLY (
                SELECT COUNT(*) AS DeliveredSlots
                FROM Citas c
                WHERE c.IdProgramacion = p.IdProgramacion
                  AND c.Fecha = p.Fecha
                  AND c.HoraInicio >= p.HoraInicio
                  AND c.HoraInicio < p.HoraFin
                  AND p.TiempoPromedioAtencion > 0
                  AND (DATEDIFF(MINUTE, p.HoraInicio, c.HoraInicio) % p.TiempoPromedioAtencion) = 0
            ) ds
            CROSS APPLY (
                SELECT
                    CASE WHEN NULLIF(p.TiempoPromedioAtencion, 0) IS NULL THEN 0
                        ELSE FLOOR(CAST(DATEDIFF(MINUTE, p.HoraInicio, p.HoraFin) AS FLOAT) / CAST(p.TiempoPromedioAtencion AS FLOAT))
                    END AS SlotsTotales,
                    CASE
                        WHEN p.Fecha > @Hoy THEN 0
                        WHEN p.Fecha < @Hoy THEN
                            FLOOR(CAST(DATEDIFF(MINUTE, p.HoraInicio, p.HoraFin) AS FLOAT) / CAST(NULLIF(p.TiempoPromedioAtencion, 0) AS FLOAT))
                        ELSE
                            CASE
                                WHEN @Ahora < (CAST(p.Fecha AS DATETIME) + p.HoraInicio) THEN 0
                                WHEN @Ahora >= (CAST(p.Fecha AS DATETIME) + p.HoraFin) THEN
                                    FLOOR(CAST(DATEDIFF(MINUTE, p.HoraInicio, p.HoraFin) AS FLOAT) / CAST(NULLIF(p.TiempoPromedioAtencion, 0) AS FLOAT))
                                ELSE
                                    CASE
                                        WHEN NULLIF(p.TiempoPromedioAtencion, 0) IS NULL THEN 0
                                        ELSE CASE
                                            WHEN CEILING(CAST(DATEDIFF(MINUTE, CAST(p.Fecha AS DATETIME) + p.HoraInicio, @Ahora) AS FLOAT)
                                                    / CAST(p.TiempoPromedioAtencion AS FLOAT))
                                                > FLOOR(CAST(DATEDIFF(MINUTE, p.HoraInicio, p.HoraFin) AS FLOAT) / CAST(NULLIF(p.TiempoPromedioAtencion, 0) AS FLOAT))
                                            THEN FLOOR(CAST(DATEDIFF(MINUTE, p.HoraInicio, p.HoraFin) AS FLOAT) / CAST(NULLIF(p.TiempoPromedioAtencion, 0) AS FLOAT))
                                            ELSE CEILING(CAST(DATEDIFF(MINUTE, CAST(p.Fecha AS DATETIME) + p.HoraInicio, @Ahora) AS FLOAT)
                                                    / CAST(p.TiempoPromedioAtencion AS FLOAT))
                                        END
                                    END
                            END
                    END AS SlotsElapsed
            ) ca
            WHERE p.Fecha BETWEEN
                CASE WHEN @MODO = 'hoy' THEN @Hoy ELSE @Hoy END
                AND CASE WHEN @MODO = 'hoy' THEN @Hoy ELSE EOMONTH(@Hoy) END
              AND (p.Fecha > @Hoy OR (p.Fecha = @Hoy AND p.HoraFin > @AhoraTime))
              AND (@IdEspecialidad IS NULL OR e.IdEspecialidad = @IdEspecialidad)
            ORDER BY e.Nombre, p.Fecha, p.HoraInicio
        ";
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ][$month] ?? '';
    }
}
