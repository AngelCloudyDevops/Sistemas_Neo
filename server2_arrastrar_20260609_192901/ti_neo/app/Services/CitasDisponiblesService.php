<?php

declare(strict_types=1);

namespace TiNeo\Services;

use PDO;
use RuntimeException;

final class CitasDisponiblesService
{
    public function __construct(
        private readonly PDO $ticketsDb,
        private readonly PDO $sighDb
    ) {
    }

    public function disponiblesHoy(): array
    {
        return $this->disponiblesMes(date('Y-m-d'));
    }

    public function disponiblesMes(?string $fecha = null): array
    {
        try {
            $referenceDate = $this->normalizeDate($fecha ?: date('Y-m-d'));
            $monthStart = date('Y-m-01', strtotime($referenceDate));
            $monthEnd = date('Y-m-t', strtotime($referenceDate));
            $this->releaseExpiredReservations($referenceDate);
            $operationalStart = $referenceDate;
            $operationalEnd = date('Y-m-t', strtotime(date('Y-m-01', strtotime($operationalStart)) . ' +1 month'));
            $sighRows = $this->aggregateAvailabilityRows($this->fetchSighDisponiblesPorFecha($monthStart, $monthEnd));
            $operationalRows = $this->aggregateAvailabilityRows($this->fetchSighDisponiblesPorFecha($operationalStart, $operationalEnd));
            $coreRows = $this->fetchCoreEspecialidades();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'No se pudo consultar la disponibilidad de citas en SIGH: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $coreByName = [];
        $coreBySighId = [];
        foreach ($coreRows as $row) {
            $core = [
                'id' => (int) $row['id'],
                'nombre' => (string) $row['nombre'],
            ];
            $coreByName[$this->normalizeName((string) $row['nombre'])] = $core;

            $sighId = (int) ($row['sigh_especialidad_id'] ?? 0);
            if ($sighId > 0) {
                $coreBySighId[$sighId] = $core;
            }
        }

        $reservasPorSighId = $this->activeReservationsBySighIdRange($monthStart, $monthEnd);
        $reservasOperativasPorSighId = $this->activeReservationsBySighIdRange($operationalStart, $operationalEnd);
        $reservasOperativasPorFecha = $this->activeReservationsBySighIdAndDate($operationalStart, $operationalEnd);
        $rowsBySighId = [];
        foreach ($sighRows as $row) {
            $rowsBySighId[(int) $row['IdEspecialidad']] = $row;
        }
        foreach ($operationalRows as $row) {
            $sighId = (int) $row['IdEspecialidad'];
            if (!isset($rowsBySighId[$sighId])) {
                $rowsBySighId[$sighId] = [
                    'IdEspecialidad' => $sighId,
                    'Especialidad' => (string) $row['Especialidad'],
                    'TotalCitas' => 0,
                    'CitasEntregadas' => 0,
                    'CitasPerdidas' => 0,
                    'CitasDisponibles' => 0,
                    'PrimeraFechaDisponible' => null,
                ];
            }
        }
        $operationalBySighId = [];
        foreach ($operationalRows as $row) {
            $operationalBySighId[(int) $row['IdEspecialidad']] = $row;
        }

        $items = array_map(function (array $row) use ($coreByName, $coreBySighId, $reservasPorSighId, $reservasOperativasPorSighId, $reservasOperativasPorFecha, $operationalBySighId): array {
            $sighId = (int) $row['IdEspecialidad'];
            $name = (string) $row['Especialidad'];
            $match = $coreBySighId[$sighId] ?? $coreByName[$this->normalizeName($name)] ?? null;
            $sighDisponibles = max(0, (int) $row['CitasDisponibles']);
            $reservasActivas = (int) ($reservasPorSighId[$sighId] ?? 0);
            $disponiblesTiNeo = max(0, $sighDisponibles - $reservasActivas);
            $operational = $operationalBySighId[$sighId] ?? [];
            $sighOperativos = max(0, (int) ($operational['CitasDisponibles'] ?? 0));
            $reservasOperativas = (int) ($reservasOperativasPorSighId[$sighId] ?? 0);
            $disponiblesOperativos = max(0, $sighOperativos - $reservasOperativas);
            $primeraFecha = $this->firstAvailableDateForSighId(
                (array) ($operational['Dias'] ?? []),
                $reservasOperativasPorFecha[$sighId] ?? []
            );

            return [
                'sigh_id' => $sighId,
                'sigh_nombre' => $name,
                'total_citas' => (int) $row['TotalCitas'],
                'citas_entregadas' => (int) $row['CitasEntregadas'],
                'citas_perdidas' => (int) $row['CitasPerdidas'],
                'citas_disponibles_sigh' => $sighDisponibles,
                'reservas_activas_ti_neo' => $reservasActivas,
                'citas_disponibles' => $disponiblesTiNeo,
                'citas_operativas_sigh' => $sighOperativos,
                'reservas_operativas_ti_neo' => $reservasOperativas,
                'citas_operativas' => $disponiblesOperativos,
                'cupos_fuera_periodo' => max(0, $disponiblesOperativos - $disponiblesTiNeo),
                'primera_fecha_disponible' => $primeraFecha,
                'core_especialidad_id' => $match['id'] ?? null,
                'core_nombre' => $match['nombre'] ?? null,
                'mapeado' => $match !== null,
            ];
        }, array_values($rowsBySighId));

        return [
            'fecha' => $referenceDate,
            'periodo' => date('Y-m', strtotime($referenceDate)),
            'fecha_inicio' => $monthStart,
            'fecha_fin' => $monthEnd,
            'fecha_operativa_inicio' => $operationalStart,
            'fecha_operativa_fin' => $operationalEnd,
            'total_sigh' => count($items),
            'total_mapeadas' => count(array_filter($items, static fn (array $item): bool => $item['mapeado'])),
            'especialidades' => $items,
        ];
    }

    public function cuposDisponiblesTiNeo(int $sighEspecialidadId, string $fecha): int
    {
        return (int) $this->cupoOperativoTiNeo($sighEspecialidadId, $fecha)['disponibles'];
    }

    public function cupoOperativoTiNeo(int $sighEspecialidadId, string $fecha): array
    {
        if ($sighEspecialidadId < 1) {
            return ['disponibles' => 0, 'fecha' => null];
        }

        $referenceDate = $this->normalizeDate($fecha);
        $rangeEnd = date('Y-m-t', strtotime(date('Y-m-01', strtotime($referenceDate)) . ' +1 month'));
        $this->releaseExpiredReservations($referenceDate);
        $dailyRows = array_values(array_filter(
            $this->fetchSighDisponiblesPorFecha($referenceDate, $rangeEnd),
            static fn (array $row): bool => (int) $row['IdEspecialidad'] === $sighEspecialidadId
        ));
        $reservasPorFecha = $this->activeReservationsBySighIdAndDate($referenceDate, $rangeEnd)[$sighEspecialidadId] ?? [];

        $disponibles = 0;
        foreach ($dailyRows as $row) {
            $day = (string) $row['Fecha'];
            $disponibles += max(0, (int) $row['CitasDisponibles'] - (int) ($reservasPorFecha[$day] ?? 0));
        }

        return [
            'disponibles' => max(0, $disponibles),
            'fecha' => $this->firstAvailableDateForSighId($dailyRows, $reservasPorFecha),
        ];
    }

    private function fetchSighDisponiblesPorFecha(string $monthStart, string $monthEnd): array
    {
        $sql = "
            DECLARE @Inicio DATE = CAST(:inicio AS DATE);
            DECLARE @Fin DATE = CAST(:fin AS DATE);
            DECLARE @Hoy DATE = CAST(GETDATE() AS DATE);
            DECLARE @Ahora DATETIME = GETDATE();
            DECLARE @AhoraTime TIME = CAST(GETDATE() AS TIME);

            ;WITH ProgramacionesBase AS (
                SELECT
                    p.IdProgramacion,
                    p.IdEspecialidad,
                    p.Fecha,
                    p.HoraInicio,
                    p.HoraFin,
                    p.TiempoPromedioAtencion,
                    CONVERT(DATETIME, CONVERT(CHAR(10), p.Fecha, 120) + ' ' + CONVERT(CHAR(8), p.HoraInicio, 108), 120) AS InicioDateTime,
                    CONVERT(DATETIME, CONVERT(CHAR(10), p.Fecha, 120) + ' ' + CONVERT(CHAR(8), p.HoraFin, 108), 120) AS FinDateTime
                FROM ProgramacionMedica p
                WHERE p.Fecha >= @Inicio
                  AND p.Fecha <= @Fin
                  AND (p.Fecha > @Hoy OR (p.Fecha = @Hoy AND p.HoraFin > @AhoraTime))
            ),
            ProgCalc AS (
                SELECT
                    pb.IdProgramacion,
                    pb.IdEspecialidad,
                    pb.Fecha,
                    pb.HoraInicio,
                    pb.HoraFin,
                    pb.TiempoPromedioAtencion,
                    CASE WHEN NULLIF(pb.TiempoPromedioAtencion, 0) IS NULL THEN 0
                        ELSE FLOOR(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, pb.FinDateTime) AS FLOAT) / CAST(pb.TiempoPromedioAtencion AS FLOAT))
                    END AS SlotsTotales,
                    CASE
                        WHEN pb.Fecha > @Hoy THEN 0
                        WHEN pb.Fecha < @Hoy THEN FLOOR(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, pb.FinDateTime) AS FLOAT) / CAST(NULLIF(pb.TiempoPromedioAtencion, 0) AS FLOAT))
                        ELSE
                            CASE
                                WHEN @Ahora < pb.InicioDateTime THEN 0
                                WHEN @Ahora >= pb.FinDateTime THEN FLOOR(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, pb.FinDateTime) AS FLOAT) / CAST(NULLIF(pb.TiempoPromedioAtencion, 0) AS FLOAT))
                                ELSE
                                    CASE WHEN NULLIF(pb.TiempoPromedioAtencion, 0) IS NULL THEN 0
                                    ELSE
                                        CASE
                                            WHEN CEILING(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, @Ahora) AS FLOAT) / CAST(pb.TiempoPromedioAtencion AS FLOAT))
                                                > FLOOR(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, pb.FinDateTime) AS FLOAT) / CAST(NULLIF(pb.TiempoPromedioAtencion, 0) AS FLOAT))
                                            THEN FLOOR(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, pb.FinDateTime) AS FLOAT) / CAST(NULLIF(pb.TiempoPromedioAtencion, 0) AS FLOAT))
                                            ELSE CEILING(CAST(DATEDIFF(MINUTE, pb.InicioDateTime, @Ahora) AS FLOAT) / CAST(pb.TiempoPromedioAtencion AS FLOAT))
                                        END
                                    END
                            END
                    END AS SlotsElapsed
                FROM ProgramacionesBase pb
            ),
            DeliveredPerProg AS (
                SELECT
                    p.IdProgramacion,
                    COUNT(*) AS DeliveredSlots
                FROM Citas c
                INNER JOIN ProgramacionMedica p
                    ON p.IdProgramacion = c.IdProgramacion
                    AND p.Fecha = c.Fecha
                WHERE c.Fecha >= @Inicio
                  AND c.Fecha <= @Fin
                  AND p.TiempoPromedioAtencion > 0
                  AND c.HoraInicio >= p.HoraInicio
                  AND c.HoraInicio < p.HoraFin
                  AND (DATEDIFF(MINUTE, p.HoraInicio, c.HoraInicio) % p.TiempoPromedioAtencion) = 0
                GROUP BY p.IdProgramacion
            )
            SELECT
                e.IdEspecialidad,
                e.Nombre AS Especialidad,
                pc.Fecha,
                SUM(pc.SlotsTotales) AS TotalCitas,
                SUM(ISNULL(dp.DeliveredSlots, 0)) AS CitasEntregadas,
                SUM(CASE WHEN pc.SlotsElapsed - ISNULL(dp.DeliveredSlots, 0) > 0 THEN pc.SlotsElapsed - ISNULL(dp.DeliveredSlots, 0) ELSE 0 END) AS CitasPerdidas,
                SUM(pc.SlotsTotales)
                    - SUM(ISNULL(dp.DeliveredSlots, 0))
                    - SUM(CASE WHEN pc.SlotsElapsed - ISNULL(dp.DeliveredSlots, 0) > 0 THEN pc.SlotsElapsed - ISNULL(dp.DeliveredSlots, 0) ELSE 0 END) AS CitasDisponibles
            FROM ProgCalc pc
            INNER JOIN Especialidades e ON e.IdEspecialidad = pc.IdEspecialidad
            LEFT JOIN DeliveredPerProg dp ON dp.IdProgramacion = pc.IdProgramacion
            GROUP BY e.IdEspecialidad, e.Nombre, pc.Fecha
            ORDER BY e.Nombre ASC, pc.Fecha ASC;
        ";

        $stmt = $this->sighDb->prepare($sql);
        $stmt->execute([
            'inicio' => $monthStart,
            'fin' => $monthEnd,
        ]);

        return $stmt->fetchAll();
    }

    private function aggregateAvailabilityRows(array $dailyRows): array
    {
        $groups = [];
        foreach ($dailyRows as $row) {
            $sighId = (int) $row['IdEspecialidad'];
            $available = max(0, (int) $row['CitasDisponibles']);
            if (!isset($groups[$sighId])) {
                $groups[$sighId] = [
                    'IdEspecialidad' => $sighId,
                    'Especialidad' => (string) $row['Especialidad'],
                    'TotalCitas' => 0,
                    'CitasEntregadas' => 0,
                    'CitasPerdidas' => 0,
                    'CitasDisponibles' => 0,
                    'PrimeraFechaDisponible' => null,
                    'Dias' => [],
                ];
            }

            $groups[$sighId]['TotalCitas'] += max(0, (int) $row['TotalCitas']);
            $groups[$sighId]['CitasEntregadas'] += max(0, (int) $row['CitasEntregadas']);
            $groups[$sighId]['CitasPerdidas'] += max(0, (int) $row['CitasPerdidas']);
            $groups[$sighId]['CitasDisponibles'] += $available;
            $groups[$sighId]['Dias'][] = $row;
            if ($available > 0 && $groups[$sighId]['PrimeraFechaDisponible'] === null) {
                $groups[$sighId]['PrimeraFechaDisponible'] = (string) $row['Fecha'];
            }
        }

        return array_values($groups);
    }

    private function fetchCoreEspecialidades(): array
    {
        $stmt = $this->ticketsDb->query(
            "SELECT id, nombre, sigh_especialidad_id
             FROM core_especialidades
             WHERE estado = 'activo'"
        );

        return $stmt->fetchAll();
    }

    private function activeReservationsBySighIdRange(string $start, string $end): array
    {
        $this->ensureReservationTable();
        $stmt = $this->ticketsDb->prepare(
            "SELECT sigh_especialidad_id, COUNT(*) AS total
             FROM st_cupo_reservas
             WHERE fecha >= :inicio
               AND fecha <= :fin
               AND estado = 'reservada'
             GROUP BY sigh_especialidad_id"
        );
        $stmt->execute([
            'inicio' => $start,
            'fin' => $end,
        ]);

        $reservas = [];
        foreach ($stmt->fetchAll() as $row) {
            $reservas[(int) $row['sigh_especialidad_id']] = (int) $row['total'];
        }

        return $reservas;
    }

    private function activeReservationsBySighIdAndDate(string $start, string $end): array
    {
        $this->ensureReservationTable();
        $stmt = $this->ticketsDb->prepare(
            "SELECT sigh_especialidad_id, fecha, COUNT(*) AS total
             FROM st_cupo_reservas
             WHERE fecha >= :inicio
               AND fecha <= :fin
               AND estado = 'reservada'
             GROUP BY sigh_especialidad_id, fecha"
        );
        $stmt->execute([
            'inicio' => $start,
            'fin' => $end,
        ]);

        $reservas = [];
        foreach ($stmt->fetchAll() as $row) {
            $sighId = (int) $row['sigh_especialidad_id'];
            $reservas[$sighId][date('Y-m-d', strtotime((string) $row['fecha']))] = (int) $row['total'];
        }

        return $reservas;
    }

    private function firstAvailableDateForSighId(array $dailyRows, array $reservasPorFecha): ?string
    {
        usort($dailyRows, static fn (array $a, array $b): int => strcmp((string) $a['Fecha'], (string) $b['Fecha']));
        foreach ($dailyRows as $row) {
            $day = date('Y-m-d', strtotime((string) $row['Fecha']));
            $available = max(0, (int) $row['CitasDisponibles'] - (int) ($reservasPorFecha[$day] ?? 0));
            if ($available > 0) {
                return $day;
            }
        }

        return null;
    }

    private function releaseExpiredReservations(string $referenceDate): void
    {
        $this->ensureReservationTable();

        if ($this->isSqlServer()) {
            $sql = "UPDATE st_cupo_reservas
                    SET estado = 'liberada',
                        liberado_en = SYSDATETIME(),
                        liberado_motivo = 'cierre_dia'
                    WHERE estado = 'reservada'
                      AND fecha < :fecha";
        } else {
            $sql = "UPDATE st_cupo_reservas
                    SET estado = 'liberada',
                        liberado_en = CURRENT_TIMESTAMP,
                        liberado_motivo = 'cierre_dia'
                    WHERE estado = 'reservada'
                      AND fecha < :fecha";
        }

        $stmt = $this->ticketsDb->prepare($sql);
        $stmt->execute(['fecha' => $referenceDate]);

        if ($this->isSqlServer()) {
            $sql = "UPDATE r
                    SET estado = 'liberada',
                        liberado_en = SYSDATETIME(),
                        liberado_motivo = 'ticket_dia_vencido'
                    FROM st_cupo_reservas r
                    INNER JOIN st_tickets t ON t.id = r.ticket_id
                    WHERE r.estado = 'reservada'
                      AND t.fecha < :fecha";
        } else {
            $sql = "UPDATE st_cupo_reservas r
                    INNER JOIN st_tickets t ON t.id = r.ticket_id
                    SET r.estado = 'liberada',
                        r.liberado_en = CURRENT_TIMESTAMP,
                        r.liberado_motivo = 'ticket_dia_vencido'
                    WHERE r.estado = 'reservada'
                      AND t.fecha < :fecha";
        }

        $stmt = $this->ticketsDb->prepare($sql);
        $stmt->execute(['fecha' => $referenceDate]);
    }

    private function normalizeDate(string $fecha): string
    {
        $timestamp = strtotime($fecha);
        if ($timestamp === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $timestamp);
    }

    private function ensureReservationTable(): void
    {
        if ($this->tableExists('st_cupo_reservas')) {
            return;
        }

        if ($this->isSqlServer()) {
            $this->ticketsDb->exec(
                "CREATE TABLE dbo.st_cupo_reservas (
                    id BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_st_cupo_reservas PRIMARY KEY,
                    ticket_id BIGINT NOT NULL,
                    core_especialidad_id BIGINT NOT NULL,
                    sigh_especialidad_id INT NOT NULL,
                    fecha DATE NOT NULL,
                    estado VARCHAR(20) NOT NULL CONSTRAINT DF_st_cupo_reservas_estado DEFAULT ('reservada'),
                    reservado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_cupo_reservas_reservado DEFAULT (SYSDATETIME()),
                    liberado_en DATETIME2(0) NULL,
                    liberado_motivo VARCHAR(40) NULL,
                    CONSTRAINT UQ_st_cupo_reservas_ticket UNIQUE (ticket_id),
                    CONSTRAINT CK_st_cupo_reservas_estado CHECK (estado IN ('reservada', 'liberada'))
                );
                CREATE INDEX IX_st_cupo_reservas_activa
                ON dbo.st_cupo_reservas(fecha, sigh_especialidad_id, estado);"
            );

            return;
        }

        $this->ticketsDb->exec(
            "CREATE TABLE IF NOT EXISTS st_cupo_reservas (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                core_especialidad_id BIGINT UNSIGNED NOT NULL,
                sigh_especialidad_id INT UNSIGNED NOT NULL,
                fecha DATE NOT NULL,
                estado ENUM('reservada', 'liberada') NOT NULL DEFAULT 'reservada',
                reservado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                liberado_en TIMESTAMP NULL DEFAULT NULL,
                liberado_motivo VARCHAR(40) NULL,
                UNIQUE KEY uk_st_cupo_reservas_ticket (ticket_id),
                KEY idx_st_cupo_reservas_activa (fecha, sigh_especialidad_id, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function tableExists(string $table): bool
    {
        if ($this->isSqlServer()) {
            $stmt = $this->ticketsDb->prepare("SELECT OBJECT_ID(:name, 'U') AS table_id");
            $stmt->execute(['name' => 'dbo.' . $table]);

            return $stmt->fetchColumn() !== null;
        }

        $stmt = $this->ticketsDb->prepare("SHOW TABLES LIKE :table");
        $stmt->execute(['table' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    private function isSqlServer(): bool
    {
        return in_array($this->ticketsDb->getAttribute(PDO::ATTR_DRIVER_NAME), ['sqlsrv', 'dblib', 'odbc'], true);
    }

    private function normalizeName(string $name): string
    {
        $normalized = strtoupper(trim($name));
        $normalized = strtr($normalized, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
            'Ñ' => 'N',
        ]);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
