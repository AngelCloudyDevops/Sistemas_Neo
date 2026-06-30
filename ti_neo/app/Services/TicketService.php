<?php

declare(strict_types=1);

namespace TiNeo\Services;

use PDO;
use RuntimeException;
use TiNeo\Core\Sql;
use TiNeo\Models\Specialty;

final class TicketService
{
    private const TYPES = ['Preferencial', 'Normal', 'Procedimiento'];
    private const PREFIXES = ['Preferencial' => 'P', 'Normal' => 'N', 'Procedimiento' => 'PR'];
    private const DEFAULT_RANGE_END = 300;
    private const MAX_DAILY_DOCUMENT_SPECIALTIES = 3;
    private const WINDOW_QUEUE_MODES = ['automatico', 'preferencial', 'normal', 'manual'];

    public function __construct(
        private readonly PDO $db,
        private readonly Specialty $specialties,
        private readonly AuditService $audit,
        private readonly ?\Closure $citasDisponiblesFactory = null
    ) {
    }

    public function summary(): array
    {
        $today = date('Y-m-d');

        $stmt = $this->db->prepare(
            'SELECT tipo_ticket, COUNT(*) AS total
             FROM st_tickets
             WHERE fecha = :fecha AND estado <> \'anulado\' AND estado_impresion = \'impreso\'
             GROUP BY tipo_ticket'
        );
        $stmt->execute(['fecha' => $today]);

        $byType = ['Preferencial' => 0, 'Normal' => 0, 'Procedimiento' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $type = $this->displayTicketType((string) $row['tipo_ticket']);
            if (!isset($byType[$type])) {
                continue;
            }
            $byType[$type] += (int) $row['total'];
        }

        $last = $this->db->query(
            'SELECT ' . Sql::top($this->db, 1) . 'codigo, tipo_ticket, fecha, hora
             FROM st_tickets
             WHERE estado <> \'anulado\' AND estado_impresion = \'impreso\'
             ORDER BY creado_en DESC, id DESC' . Sql::limit($this->db, 1)
        )->fetch();

        return [
            'fecha' => $today,
            'total_hoy' => array_sum($byType),
            'por_tipo' => $byType,
            'ultimo' => is_array($last) ? $last : null,
            'rangos' => $this->rangesForDate($today),
            'especialidades_frecuentes' => $this->frequentSpecialties($today),
            'impresion' => $this->printSettings(),
        ];
    }

    public function frequentSpecialties(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 8) . 'e.id, e.nombre, COUNT(*) AS total
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.estado <> \'anulado\'
               AND e.estado = \'activo\'
             GROUP BY e.id, e.nombre
             ORDER BY total DESC, e.nombre ASC' . Sql::limit($this->db, 8)
        );
        $stmt->execute(['fecha' => $date]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'total' => (int) $row['total'],
        ], $stmt->fetchAll());
    }

    public function printSettings(): array
    {
        $defaults = [
            'automatica' => 'SI',
            'retraso_ms' => 120,
            'titulo' => 'HOSPITAL SAN' . "\n" . 'JOSE DEL CALLAO',
            'logo_izquierdo_url' => 'https://thumbs4.imagebam.com/a4/33/1a/ME1CROZK_t.png',
            'logo_derecho_url' => 'https://thumbs4.imagebam.com/bd/2a/44/ME1CROVG_t.png',
            'layout' => $this->defaultPrintLayout(),
        ];

        $stmt = $this->db->query(
            'SELECT clave, valor
             FROM st_configuracion
             WHERE clave IN (
                \'ticket_titulo\', \'logo_izquierdo_url\', \'logo_derecho_url\',
                \'impresion_automatica\', \'impresion_retraso_ms\',
                \'ticket_papel_mm\', \'ticket_contenido_mm\', \'ticket_padding_mm\',
                \'ticket_logo_mm\', \'ticket_header_px\', \'ticket_codigo_px\',
                \'ticket_documento_px\', \'ticket_especialidad_px\', \'ticket_fecha_px\',
                \'ticket_codigo_margen_mm\', \'ticket_linea_margen_mm\', \'ticket_fecha_margen_mm\',
                \'ticket_offset_x_mm\', \'ticket_offset_y_mm\', \'ticket_hospital_x_mm\', \'ticket_hospital_y_mm\',
                \'ticket_logo_left_x_mm\', \'ticket_logo_left_y_mm\',
                \'ticket_logo_right_x_mm\', \'ticket_logo_right_y_mm\',
                \'ticket_code_x_mm\', \'ticket_code_y_mm\', \'ticket_doc_x_mm\', \'ticket_doc_y_mm\',
                \'ticket_specialty_x_mm\', \'ticket_specialty_y_mm\', \'ticket_date_x_mm\', \'ticket_date_y_mm\'
             )'
        );

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['clave'];
            $value = (string) $row['valor'];

            if ($row['clave'] === 'impresion_automatica') {
                $defaults['automatica'] = strtoupper(trim($value)) === 'SI' ? 'SI' : 'NO';
            }

            if ($row['clave'] === 'impresion_retraso_ms') {
                $defaults['retraso_ms'] = max(100, (int) $value);
            }

            if ($row['clave'] === 'ticket_titulo' && trim($value) !== '') {
                $defaults['titulo'] = str_replace('\n', "\n", $value);
            }

            if ($row['clave'] === 'logo_izquierdo_url' && trim($value) !== '') {
                $defaults['logo_izquierdo_url'] = $value;
            }

            if ($row['clave'] === 'logo_derecho_url' && trim($value) !== '') {
                $defaults['logo_derecho_url'] = $value;
            }

            if (str_starts_with($key, 'ticket_')) {
                $layoutKey = substr($key, 7);
                if (array_key_exists($layoutKey, $defaults['layout'])) {
                    $defaults['layout'][$layoutKey] = (float) $value;
                }
            }
        }

        return $defaults;
    }

    public function savePrintLayout(array $input, array $user): array
    {
        $layout = $this->sanitizePrintLayout($input);
        $this->db->beginTransaction();

        try {
            foreach ($layout as $key => $value) {
                Sql::upsertConfig($this->db, 'ticket_' . $key, (string) $value);
            }

            $this->audit->log((int) $user['id'], 'GUARDAR_CONFIG_IMPRESION', 'configuracion', null, $layout);
            $this->db->commit();

            return ['impresion' => $this->printSettings()];
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function rangesForDate(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT tipo_ticket, desde, hasta, ultimo, activo
             FROM st_rangos
             WHERE fecha = :fecha
             ORDER BY ' . Sql::orderTicketTypes()
        );
        $stmt->execute(['fecha' => $date]);

        return array_map(static function (array $row): array {
            $hasta = (int) $row['hasta'];
            $ultimo = (int) $row['ultimo'];

            return [
                'tipo' => $row['tipo_ticket'],
                'desde' => (int) $row['desde'],
                'hasta' => $hasta,
                'ultimo' => $ultimo,
                'disponible' => max(0, $hasta - $ultimo),
                'activo' => (int) $row['activo'] === 1 ? 'SI' : 'NO',
            ];
        }, $stmt->fetchAll());
    }

    public function saveRange(array $input, array $user): array
    {
        $date = $this->normalizeDate((string) ($input['fecha'] ?? date('Y-m-d')));
        $type = $this->normalizeType((string) ($input['tipo_ticket'] ?? ''));
        $desde = (int) ($input['desde'] ?? 0);
        $hasta = (int) ($input['hasta'] ?? 0);

        if ($desde < 1 || $hasta < $desde) {
            throw new RuntimeException('El rango ingresado no es valido.');
        }

        $this->db->beginTransaction();

        try {
            $range = $this->lockRange($date, $type);
            if ($range !== null && (int) $range['ultimo'] >= (int) $range['desde']) {
                $minHasta = (int) $range['ultimo'];
                if ($hasta < $minHasta) {
                    throw new RuntimeException('No puedes reducir Hasta por debajo del ultimo ticket emitido.');
                }

                $stmt = $this->db->prepare(
                    'UPDATE st_rangos
                     SET hasta = :hasta, activo = 1, actualizado_en = ' . Sql::now($this->db) . '
                     WHERE id = :id'
                );
                $stmt->execute(['hasta' => $hasta, 'id' => (int) $range['id']]);
            } elseif ($range !== null) {
                $stmt = $this->db->prepare(
                    'UPDATE st_rangos
                     SET desde = :desde, hasta = :hasta, ultimo = :ultimo, activo = 1, actualizado_en = ' . Sql::now($this->db) . '
                     WHERE id = :id'
                );
                $stmt->execute([
                    'desde' => $desde,
                    'hasta' => $hasta,
                    'ultimo' => $desde - 1,
                    'id' => (int) $range['id'],
                ]);
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO st_rangos (fecha, tipo_ticket, desde, hasta, ultimo, activo, creado_por, creado_en)
                     VALUES (:fecha, :tipo_ticket, :desde, :hasta, :ultimo, 1, :creado_por, ' . Sql::now($this->db) . ')'
                );
                $stmt->execute([
                    'fecha' => $date,
                    'tipo_ticket' => $type,
                    'desde' => $desde,
                    'hasta' => $hasta,
                    'ultimo' => $desde - 1,
                    'creado_por' => (int) $user['id'],
                ]);
            }

            $this->audit->log((int) $user['id'], 'GUARDAR_RANGO', 'rango', null, [
                'fecha' => $date,
                'tipo' => $type,
                'desde' => $desde,
                'hasta' => $hasta,
            ]);

            $this->db->commit();

            return ['fecha' => $date, 'rangos' => $this->rangesForDate($date)];
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function rangeCalendar(array $input): array
    {
        $year = (int) ($input['anio'] ?? date('Y'));
        $month = (int) ($input['mes'] ?? date('n'));

        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new RuntimeException('Mes no valido.');
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-d', strtotime($start . ' +1 month'));
        $rangesByDate = [];
        $batchesByDate = [];

        $stmt = $this->db->prepare(
            'SELECT fecha, tipo_ticket, desde, hasta, ultimo, activo
             FROM st_rangos
             WHERE fecha >= :start_date AND fecha < :end_date
             ORDER BY fecha ASC, ' . Sql::orderTicketTypes()
        );
        $stmt->execute(['start_date' => $start, 'end_date' => $end]);
        foreach ($stmt->fetchAll() as $row) {
            $date = (string) $row['fecha'];
            $rangesByDate[$date] ??= [];
            $rangesByDate[$date][] = $this->formatRange($row);
        }

        $stmt = $this->db->prepare(
            'SELECT id, uuid, fecha, tipo_ticket, desde, hasta, cantidad, estado
             FROM st_lotes
             WHERE fecha >= :start_date AND fecha < :end_date
             ORDER BY fecha ASC, tipo_ticket ASC, desde ASC'
        );
        $stmt->execute(['start_date' => $start, 'end_date' => $end]);
        foreach ($stmt->fetchAll() as $row) {
            $date = (string) $row['fecha'];
            $batchesByDate[$date] ??= [];
            $batchesByDate[$date][] = [
                'id' => (int) $row['id'],
                'uuid' => (string) $row['uuid'],
                'tipo' => $this->displayTicketType((string) $row['tipo_ticket']),
                'desde' => (int) $row['desde'],
                'hasta' => (int) $row['hasta'],
                'cantidad' => (int) $row['cantidad'],
                'estado' => (string) $row['estado'],
            ];
        }

        $dates = array_values(array_unique(array_merge(array_keys($rangesByDate), array_keys($batchesByDate))));
        sort($dates);

        return [
            'anio' => $year,
            'mes' => $month,
            'dias' => array_map(static fn (string $date): array => [
                'fecha' => $date,
                'rangos' => $rangesByDate[$date] ?? [],
                'lotes' => $batchesByDate[$date] ?? [],
            ], $dates),
        ];
    }

    public function generateBatch(array $input, array $user): array
    {
        $date = $this->normalizeDate((string) ($input['fecha'] ?? date('Y-m-d')));
        $type = $this->normalizeType((string) ($input['tipo'] ?? $input['tipo_ticket'] ?? ''));
        $requestedFrom = (int) ($input['desde'] ?? 0);
        $requestedTo = (int) ($input['hasta'] ?? 0);
        $userId = (int) $user['id'];
        $time = date('H:i:s');

        $this->db->beginTransaction();

        try {
            $range = $this->lockOrCreateRange($date, $type, $userId);
            if ((int) $range['activo'] !== 1) {
                throw new RuntimeException('El rango seleccionado no esta activo.');
            }

            $start = (int) $range['ultimo'] < (int) $range['desde']
                ? (int) $range['desde']
                : (int) $range['ultimo'] + 1;
            $end = $requestedTo > 0 ? $requestedTo : (int) $range['hasta'];

            if ($requestedFrom > 0 && $requestedFrom !== $start) {
                throw new RuntimeException('La impresion parcial debe iniciar en el siguiente disponible: ' . $start . '.');
            }

            if ($end < $start) {
                throw new RuntimeException('No hay tickets disponibles para imprimir en este rango.');
            }

            if ($end > (int) $range['hasta']) {
                throw new RuntimeException('Hasta no puede superar el fin del rango configurado (' . (int) $range['hasta'] . ').');
            }

            $quantity = $end - $start + 1;
            if ($quantity > 600) {
                throw new RuntimeException('El lote supera 600 tickets. Divide el rango para evitar bloqueos del navegador.');
            }

            $specialtyId = $this->batchSpecialtyId();
            $batchUuid = $this->uuid();
            $tickets = [];

            for ($number = $start; $number <= $end; $number++) {
                $code = self::PREFIXES[$type] . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
                $ticketUuid = $this->uuid();
                $params = [
                    'uuid' => $ticketUuid,
                    'fecha' => $date,
                    'hora' => $time,
                    'tipo_ticket' => $type,
                    'numero' => $number,
                    'codigo' => $code,
                    'tipo_documento' => 'DNI',
                    'numero_documento' => '',
                    'especialidad_id' => $specialtyId,
                    'creado_por' => $userId,
                ];

                if (Sql::isSqlServer($this->db)) {
                    $stmt = $this->db->prepare(
                        'INSERT INTO st_tickets (
                            uuid, fecha, hora, tipo_ticket, numero, codigo,
                            tipo_documento, numero_documento, especialidad_id,
                            estado, estado_impresion, cola_estado, reimpresiones, creado_por, creado_en
                         )
                         OUTPUT INSERTED.id AS inserted_id
                         VALUES (
                            :uuid, :fecha, :hora, :tipo_ticket, :numero, :codigo,
                            :tipo_documento, :numero_documento, :especialidad_id,
                            \'emitido\', \'pendiente\', \'sin_cola\', 0, :creado_por, ' . Sql::now($this->db) . '
                         )'
                    );
                    $stmt->execute($params);
                    $inserted = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $ticketId = (int) ($inserted['inserted_id'] ?? $inserted['INSERTED_ID'] ?? 0);
                } else {
                    $stmt = $this->db->prepare(
                        'INSERT INTO st_tickets (
                            uuid, fecha, hora, tipo_ticket, numero, codigo,
                            tipo_documento, numero_documento, especialidad_id,
                            estado, estado_impresion, cola_estado, reimpresiones, creado_por, creado_en
                         ) VALUES (
                            :uuid, :fecha, :hora, :tipo_ticket, :numero, :codigo,
                            :tipo_documento, :numero_documento, :especialidad_id,
                            \'emitido\', \'pendiente\', \'sin_cola\', 0, :creado_por, ' . Sql::now($this->db) . '
                         )'
                    );
                    $stmt->execute($params);
                    $ticketId = Sql::lastInsertId($this->db);
                }

                $tickets[] = [
                    'id' => $ticketId,
                    'uuid' => $ticketUuid,
                    'fecha' => $date,
                    'hora' => $time,
                    'tipo_ticket' => $type,
                    'tipo' => $this->displayTicketType($type),
                    'numero' => $number,
                    'codigo' => $code,
                    'tipo_documento' => '',
                    'numero_documento' => '',
                    'especialidad' => '',
                ];
            }

            $stmt = $this->db->prepare(
                'UPDATE st_rangos
                 SET ultimo = :ultimo, actualizado_en = ' . Sql::now($this->db) . '
                 WHERE id = :id'
            );
            $stmt->execute(['ultimo' => $end, 'id' => (int) $range['id']]);

            $batchParams = [
                'uuid' => $batchUuid,
                'fecha' => $date,
                'tipo_ticket' => $type,
                'desde' => $start,
                'hasta' => $end,
                'cantidad' => $quantity,
                'creado_por' => $userId,
                'retomar_desde' => $start,
            ];
            if (Sql::isSqlServer($this->db)) {
                $stmt = $this->db->prepare(
                    'INSERT INTO st_lotes (
                        uuid, fecha, tipo_ticket, desde, hasta, cantidad,
                        estado, ultimo_enviado, retomar_desde, creado_por, creado_en
                     )
                     OUTPUT INSERTED.id AS inserted_id
                     VALUES (
                        :uuid, :fecha, :tipo_ticket, :desde, :hasta, :cantidad,
                        \'pendiente\', NULL, :retomar_desde, :creado_por, ' . Sql::now($this->db) . '
                     )'
                );
                $stmt->execute($batchParams);
                $inserted = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $batchId = (int) ($inserted['inserted_id'] ?? $inserted['INSERTED_ID'] ?? 0);
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO st_lotes (
                        uuid, fecha, tipo_ticket, desde, hasta, cantidad,
                        estado, ultimo_enviado, retomar_desde, creado_por, creado_en
                     ) VALUES (
                        :uuid, :fecha, :tipo_ticket, :desde, :hasta, :cantidad,
                        \'pendiente\', NULL, :retomar_desde, :creado_por, ' . Sql::now($this->db) . '
                     )'
                );
                $stmt->execute($batchParams);
                $batchId = Sql::lastInsertId($this->db);
            }

            $this->audit->log($userId, 'GENERAR_LOTE', 'lote', $batchId, [
                'fecha' => $date,
                'tipo' => $type,
                'desde' => $start,
                'hasta' => $end,
            ]);

            $this->db->commit();

            return [
                'loteId' => $batchId,
                'uuid' => $batchUuid,
                'fecha' => $date,
                'tipo' => $this->displayTicketType($type),
                'tipo_ticket' => $type,
                'desde' => $start,
                'hasta' => $end,
                'cantidad' => $quantity,
                'tickets' => $tickets,
            ];
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function pendingBatches(array $filters = []): array
    {
        $params = [];
        $where = ['estado <> \'impreso\''];
        if (!empty($filters['fecha'])) {
            $where[] = 'fecha = :fecha';
            $params['fecha'] = $this->normalizeDate((string) $filters['fecha']);
        }

        $stmt = $this->db->prepare(
            'SELECT id, uuid, fecha, tipo_ticket, desde, hasta, cantidad, estado, ultimo_enviado, retomar_desde
             FROM st_lotes
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY fecha DESC, tipo_ticket ASC, desde DESC'
        );
        $stmt->execute($params);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'fecha' => (string) $row['fecha'],
            'tipo' => $this->displayTicketType((string) $row['tipo_ticket']),
            'tipo_ticket' => (string) $row['tipo_ticket'],
            'desde' => (int) $row['desde'],
            'hasta' => (int) $row['hasta'],
            'cantidad' => (int) $row['cantidad'],
            'estado' => (string) $row['estado'],
            'ultimoEnviado' => (int) ($row['ultimo_enviado'] ?? 0),
            'retomarDesde' => (int) ($row['retomar_desde'] ?? 0),
        ], $stmt->fetchAll());
    }

    public function batchForReprint(array $input, array $user): array
    {
        $batchId = (int) ($input['loteId'] ?? $input['id'] ?? 0);
        $resumeFrom = (int) ($input['retomarDesde'] ?? 0);
        if ($batchId < 1) {
            throw new RuntimeException('ID de lote obligatorio.');
        }

        $stmt = $this->db->prepare(
            'SELECT id, uuid, fecha, tipo_ticket, desde, hasta, cantidad, retomar_desde
             FROM st_lotes
             WHERE id = :id'
        );
        $stmt->execute(['id' => $batchId]);
        $batch = $stmt->fetch();
        if (!is_array($batch)) {
            throw new RuntimeException('No se encontro el lote.');
        }

        $from = (int) $batch['desde'];
        $to = (int) $batch['hasta'];
        $start = $resumeFrom > 0 ? $resumeFrom : (int) ($batch['retomar_desde'] ?? 0);
        $start = max($from, min($to, $start > 0 ? $start : $from));

        $stmt = $this->db->prepare(
            'SELECT id, uuid, fecha, hora, tipo_ticket, numero, codigo,
                    tipo_documento, numero_documento, reimpresiones
             FROM st_tickets
             WHERE fecha = :fecha
               AND tipo_ticket = :tipo_ticket
               AND numero BETWEEN :desde AND :hasta
               AND estado <> \'anulado\'
             ORDER BY numero ASC'
        );
        $stmt->execute([
            'fecha' => (string) $batch['fecha'],
            'tipo_ticket' => (string) $batch['tipo_ticket'],
            'desde' => $start,
            'hasta' => $to,
        ]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            throw new RuntimeException('No se encontraron tickets del lote para reimprimir.');
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $this->incrementReprints($ids);

        $this->audit->log((int) $user['id'], 'REIMPRIMIR_LOTE', 'lote', $batchId, [
            'desde' => $start,
            'hasta' => $to,
        ]);

        return [
            'loteId' => $batchId,
            'uuid' => (string) $batch['uuid'],
            'fecha' => (string) $batch['fecha'],
            'tipo' => $this->displayTicketType((string) $batch['tipo_ticket']),
            'tipo_ticket' => (string) $batch['tipo_ticket'],
            'desde' => $from,
            'inicioImpresion' => $start,
            'hasta' => $to,
            'cantidad' => count($rows),
            'tickets' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'uuid' => (string) $row['uuid'],
                'fecha' => (string) $row['fecha'],
                'hora' => (string) $row['hora'],
                'tipo_ticket' => (string) $row['tipo_ticket'],
                'tipo' => $this->displayTicketType((string) $row['tipo_ticket']),
                'numero' => (int) $row['numero'],
                'codigo' => (string) $row['codigo'],
                'tipo_documento' => '',
                'numero_documento' => '',
                'especialidad' => '',
                'reimpresiones' => (int) $row['reimpresiones'] + 1,
            ], $rows),
        ];
    }

    public function updateBatchProgress(array $input, array $user): array
    {
        $batchId = (int) ($input['loteId'] ?? $input['id'] ?? 0);
        $lastSent = (int) ($input['ultimoEnviado'] ?? 0);
        if ($batchId < 1 || $lastSent < 1) {
            throw new RuntimeException('Avance de lote invalido.');
        }

        $stmt = $this->db->prepare('SELECT hasta FROM st_lotes WHERE id = :id');
        $stmt->execute(['id' => $batchId]);
        $to = (int) ($stmt->fetchColumn() ?: 0);
        if ($to < 1) {
            throw new RuntimeException('No se encontro el lote.');
        }

        $resumeFrom = $lastSent >= $to ? null : $lastSent + 1;
        $stmt = $this->db->prepare(
            'UPDATE st_lotes
             SET ultimo_enviado = :ultimo_enviado,
                 retomar_desde = :retomar_desde,
                 estado = :estado
             WHERE id = :id'
        );
        $stmt->execute([
            'ultimo_enviado' => $lastSent,
            'retomar_desde' => $resumeFrom,
            'estado' => $resumeFrom === null ? 'pendiente' : 'en_impresion',
            'id' => $batchId,
        ]);

        $this->audit->log((int) $user['id'], 'ACTUALIZAR_AVANCE_LOTE', 'lote', $batchId, [
            'ultimo_enviado' => $lastSent,
            'retomar_desde' => $resumeFrom,
        ]);

        return ['retomarDesde' => $resumeFrom];
    }

    public function markBatchPrinted(array $input, array $user): array
    {
        $batchId = (int) ($input['loteId'] ?? $input['id'] ?? 0);
        $printed = (bool) ($input['impreso'] ?? true);
        if ($batchId < 1) {
            throw new RuntimeException('ID de lote obligatorio.');
        }

        $stmt = $this->db->prepare('SELECT fecha, tipo_ticket, desde, hasta FROM st_lotes WHERE id = :id');
        $stmt->execute(['id' => $batchId]);
        $batch = $stmt->fetch();
        if (!is_array($batch)) {
            throw new RuntimeException('No se encontro el lote.');
        }

        $stmt = $this->db->prepare(
            'UPDATE st_lotes
             SET estado = :estado,
                 ultimo_enviado = :ultimo_enviado,
                 retomar_desde = :retomar_desde,
                 impreso_en = ' . ($printed ? Sql::now($this->db) : 'impreso_en') . '
             WHERE id = :id'
        );
        $stmt->execute([
            'estado' => $printed ? 'impreso' : 'pendiente',
            'ultimo_enviado' => $printed ? (int) $batch['hasta'] : null,
            'retomar_desde' => $printed ? null : (int) $batch['desde'],
            'id' => $batchId,
        ]);

        if ($printed) {
            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado_impresion = \'impreso\'
                 WHERE fecha = :fecha
                   AND tipo_ticket = :tipo_ticket
                   AND numero BETWEEN :desde AND :hasta
                   AND estado <> \'anulado\''
            );
            $stmt->execute([
                'fecha' => (string) $batch['fecha'],
                'tipo_ticket' => (string) $batch['tipo_ticket'],
                'desde' => (int) $batch['desde'],
                'hasta' => (int) $batch['hasta'],
            ]);
        }

        $this->audit->log((int) $user['id'], 'MARCAR_LOTE', 'lote', $batchId, ['impreso' => $printed]);

        return ['id' => $batchId, 'estado' => $printed ? 'impreso' : 'pendiente'];
    }

    public function history(array $filters = []): array
    {
        $this->ensureTicketPriorityColumn();
        $params = [];
        $where = ['1 = 1'];
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['por_pagina'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        if (!empty($filters['fecha'])) {
            $where[] = 't.fecha = :fecha';
            $params['fecha'] = $this->normalizeDate((string) $filters['fecha']);
        }

        if (!empty($filters['tipo_ticket'])) {
            $where[] = 't.tipo_ticket = :tipo_ticket';
            $params['tipo_ticket'] = $this->normalizeType((string) $filters['tipo_ticket']);
        }

        if (!empty($filters['codigo'])) {
            $search = '%' . trim((string) $filters['codigo']) . '%';
            $where[] = '(t.codigo LIKE :codigo_texto OR t.numero_documento LIKE :documento_texto)';
            $params['codigo_texto'] = $search;
            $params['documento_texto'] = $search;
        }

        if (!empty($filters['estado'])) {
            $estado = strtolower(trim((string) $filters['estado']));
            if ($estado === 'emitido') {
                $where[] = 't.estado <> \'anulado\'';
            } elseif ($estado === 'anulado') {
                $where[] = 't.estado = \'anulado\'';
            }
        }

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) AS total
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE ' . implode(' AND ', $where)
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT t.id, t.fecha, t.hora, t.tipo_ticket,
                COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                t.codigo, t.tipo_documento,
                t.numero_documento, t.estado, t.estado_impresion, t.reimpresiones, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.creado_en DESC, t.id DESC' . Sql::pageLimit($this->db, $perPage, $offset)
        );
        $stmt->execute($params);

        return [
            'tickets' => $stmt->fetchAll(),
            'pagina' => $page,
            'por_pagina' => $perPage,
            'total' => $total,
            'total_paginas' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function dailyReprintSearch(array $filters = []): array
    {
        $query = trim((string) ($filters['codigo'] ?? ''));
        if ($query === '') {
            return [
                'tickets' => [],
                'pagina' => 1,
                'por_pagina' => 10,
                'total' => 0,
                'total_paginas' => 1,
            ];
        }

        return $this->history([
            'fecha' => date('Y-m-d'),
            'codigo' => $query,
            'estado' => 'emitido',
            'pagina' => 1,
            'por_pagina' => min(10, max(1, (int) ($filters['por_pagina'] ?? 10))),
        ]);
    }

    public function report(array $filters = []): array
    {
        $this->ensureTicketPriorityColumn();
        $today = date('Y-m-d');
        $from = $filters['desde'] !== '' ? $this->normalizeDate((string) $filters['desde']) : date('Y-m-01');
        $to = $filters['hasta'] !== '' ? $this->normalizeDate((string) $filters['hasta']) : $today;
        $params = ['desde' => $from, 'hasta' => $to];
        $where = ['t.fecha >= :desde', 't.fecha <= :hasta'];
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['por_pagina'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        if (!empty($filters['tipo_ticket'])) {
            $where[] = 't.tipo_ticket = :tipo_ticket';
            $params['tipo_ticket'] = $this->normalizeType((string) $filters['tipo_ticket']);
        }

        $estado = strtolower(trim((string) ($filters['estado'] ?? '')));
        if ($estado === 'emitido') {
            $where[] = 't.estado <> \'anulado\'';
        } elseif ($estado === 'anulado') {
            $where[] = 't.estado = \'anulado\'';
        }

        $whereSql = implode(' AND ', $where);
        $summaryStmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN t.estado = \'anulado\' THEN 0 ELSE 1 END) AS validos,
                SUM(CASE WHEN t.estado = \'anulado\' THEN 1 ELSE 0 END) AS anulados,
                SUM(CASE WHEN t.estado <> \'anulado\' AND t.tipo_ticket = \'Preferencial\' THEN 1 ELSE 0 END) AS preferenciales,
                SUM(CASE WHEN t.estado <> \'anulado\' AND t.tipo_ticket = \'Normal\' THEN 1 ELSE 0 END) AS normales,
                SUM(CASE WHEN t.estado <> \'anulado\' AND t.tipo_ticket IN (\'Procedimiento\', \'Emergencia\') THEN 1 ELSE 0 END) AS procedimientos,
                COALESCE(SUM(t.reimpresiones), 0) AS reimpresiones
             FROM st_tickets t
             WHERE ' . $whereSql
        );
        $summaryStmt->execute($params);
        $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $summary = [
            'total' => (int) ($summaryRow['total'] ?? 0),
            'validos' => (int) ($summaryRow['validos'] ?? 0),
            'anulados' => (int) ($summaryRow['anulados'] ?? 0),
            'preferenciales' => (int) ($summaryRow['preferenciales'] ?? 0),
            'normales' => (int) ($summaryRow['normales'] ?? 0),
            'procedimientos' => (int) ($summaryRow['procedimientos'] ?? 0),
            'reimpresiones' => (int) ($summaryRow['reimpresiones'] ?? 0),
        ];

        $typeStmt = $this->db->prepare(
            'SELECT t.tipo_ticket, COUNT(*) AS total
             FROM st_tickets t
             WHERE ' . $whereSql . '
             GROUP BY t.tipo_ticket'
        );
        $typeStmt->execute($params);
        $byType = ['Preferencial' => 0, 'Normal' => 0, 'Procedimiento' => 0];
        foreach ($typeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = $this->displayTicketType((string) $row['tipo_ticket']);
            $byType[$type] = ($byType[$type] ?? 0) + (int) $row['total'];
        }

        $statusStmt = $this->db->prepare(
            'SELECT t.estado, COUNT(*) AS total
             FROM st_tickets t
             WHERE ' . $whereSql . '
             GROUP BY t.estado'
        );
        $statusStmt->execute($params);
        $byStatus = ['Emitidos' => 0, 'Anulados' => 0];
        foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) $row['estado'] === 'anulado' ? 'Anulados' : 'Emitidos';
            $byStatus[$status] += (int) $row['total'];
        }

        $dayStmt = $this->db->prepare(
            'SELECT t.fecha, COUNT(*) AS total
             FROM st_tickets t
             WHERE ' . $whereSql . '
             GROUP BY t.fecha
             ORDER BY t.fecha ASC'
        );
        $dayStmt->execute($params);
        $byDay = $dayStmt->fetchAll(PDO::FETCH_ASSOC);

        $hourColumn = Sql::isSqlServer($this->db) ? 'DATEPART(hour, t.hora)' : 'HOUR(t.hora)';
        $hourStmt = $this->db->prepare(
            'SELECT ' . $hourColumn . ' AS hora, COUNT(*) AS total
             FROM st_tickets t
             WHERE ' . $whereSql . '
             GROUP BY ' . $hourColumn . '
             ORDER BY hora ASC'
        );
        $hourStmt->execute($params);
        $byHour = array_map(static fn (array $row): array => [
            'hora' => str_pad((string) (int) $row['hora'], 2, '0', STR_PAD_LEFT) . ':00',
            'total' => (int) $row['total'],
        ], $hourStmt->fetchAll(PDO::FETCH_ASSOC));

        $detailStmt = $this->db->prepare(
            'SELECT t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.codigo, t.estado, t.estado_impresion,
                    t.reimpresiones, t.tipo_documento, t.numero_documento, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE ' . $whereSql . '
             ORDER BY t.fecha DESC, t.hora DESC, t.id DESC' . Sql::pageLimit($this->db, $perPage, $offset)
        );
        $detailStmt->execute($params);

        return [
            'filtros' => [
                'desde' => $from,
                'hasta' => $to,
                'tipo_ticket' => (string) ($filters['tipo_ticket'] ?? ''),
                'estado' => (string) ($filters['estado'] ?? ''),
            ],
            'resumen' => $summary,
            'porTipo' => array_map(static fn (string $type, int $total): array => [
                'tipo' => $type,
                'total' => $total,
            ], array_keys($byType), $byType),
            'porEstado' => array_map(static fn (string $status, int $total): array => [
                'tipo' => $status,
                'total' => $total,
            ], array_keys($byStatus), $byStatus),
            'porDia' => array_map(static fn (array $row): array => [
                'fecha' => (string) $row['fecha'],
                'total' => (int) $row['total'],
            ], $byDay),
            'porHora' => $byHour,
            'detalle' => $detailStmt->fetchAll(),
            'pagina' => $page,
            'por_pagina' => $perPage,
            'total' => $summary['total'],
            'total_paginas' => max(1, (int) ceil($summary['total'] / $perPage)),
        ];
    }

    public function queue(array $filters = []): array
    {
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();
        $date = $this->normalizeDate((string) ($filters['fecha'] ?? date('Y-m-d')));
        $specialtyId = (int) ($filters['especialidad_id'] ?? 0);
        $params = ['fecha' => $date];
        $specialtyWhere = '';

        if ($specialtyId > 0) {
            $specialtyWhere = ' AND t.especialidad_id = :especialidad_id';
            $params['especialidad_id'] = $specialtyId;
        }

        $stmt = $this->db->prepare(
            'SELECT t.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por, t.asignado_en,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.estado <> \'anulado\'
               AND t.estado_impresion = \'impreso\'
               AND t.cola_estado IN (\'en_cola\', \'llamando\', \'atendiendo\')'
               . $specialtyWhere . '
             ORDER BY CASE t.cola_estado WHEN \'llamando\' THEN 1 WHEN \'atendiendo\' THEN 2 ELSE 3 END,
                      ' . str_replace('tipo_ticket', 't.tipo_ticket', Sql::orderTicketTypes()) . ',
                      COALESCE(t.asignado_en, t.creado_en) ASC, t.id ASC'
        );
        $stmt->execute($params);
        $active = array_map(fn (array $row): array => $this->formatQueueTicket($row), $stmt->fetchAll());

        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 60) . 't.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por, t.asignado_en,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.estado <> \'anulado\'
               AND t.estado_impresion = \'impreso\'
               AND t.cola_estado = \'sin_cola\''
               . $specialtyWhere . '
             ORDER BY t.creado_en DESC, t.id DESC'
               . Sql::limit($this->db, 60)
        );
        $stmt->execute($params);
        $available = array_map(fn (array $row): array => $this->formatQueueTicket($row), $stmt->fetchAll());

        return [
            'fecha' => $date,
            'activos' => $active,
            'disponibles' => $available,
            'resumen' => $this->queueSummary($date),
        ];
    }

    public function enqueue(array $input, array $user): array
    {
        $this->ensureTurneroColumns();
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Selecciona un ticket valido.');
        }

        return $this->runQueueTransactionWithRetry(function () use ($id, $user): array {
            $ticket = $this->lockTicketForQueue($id);
            if ($ticket === null) {
                throw new RuntimeException('Ticket no encontrado.');
            }

            if ((string) $ticket['estado'] === 'anulado') {
                throw new RuntimeException('No se puede enviar a cola un ticket anulado.');
            }

            if ((string) $ticket['estado_impresion'] !== 'impreso') {
                throw new RuntimeException('Solo se pueden enviar a cola tickets impresos.');
            }

            if (!in_array((string) $ticket['cola_estado'], ['sin_cola', 'ausente'], true)) {
                throw new RuntimeException('Este ticket ya esta en proceso de atencion.');
            }

            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado = \'en_cola\',
                     cola_estado = \'en_cola\',
                     ventanilla_numero = NULL,
                     asignado_por = NULL,
                     asignado_en = ' . $this->turneroTimestampSql() . '
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);

            $this->audit->log((int) $user['id'], 'TURNERO_ENCOLAR', 'ticket', $id, ['codigo' => $ticket['codigo']]);

            return $this->ticketById($id);
        });
    }

    public function callNext(array $input, array $user): array
    {
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();
        $window = (int) ($input['ventanilla'] ?? 0);
        if ($window < 1 || $window > 9) {
            throw new RuntimeException('Selecciona una ventanilla valida.');
        }

        $specialtyId = (int) ($input['especialidad_id'] ?? 0);
        $date = $this->normalizeDate((string) ($input['fecha'] ?? date('Y-m-d')));
        $params = ['fecha' => $date];
        $specialtyWhere = '';

        if ($specialtyId > 0) {
            $specialtyWhere = ' AND especialidad_id = :especialidad_id';
            $params['especialidad_id'] = $specialtyId;
        }

        return $this->runQueueTransactionWithRetry(function () use ($date, $window, $specialtyWhere, $params, $user): array {
            $id = $this->claimNextTicketIdForWindow($date, $window, $specialtyWhere, $params, (int) $user['id']);
            if ($id < 1) {
                throw new RuntimeException('No hay tickets en cola para llamar.');
            }

            $ticket = $this->ticketById($id);
            $this->audit->log((int) $user['id'], 'TURNERO_LLAMAR', 'ticket', $id, [
                'codigo' => $ticket['codigo'],
                'ventanilla' => $window,
            ]);
            $this->refreshScreenState($date);

            return $ticket;
        });
    }

    public function recall(array $input, array $user): array
    {
        $this->ensureTurneroColumns();
        $id = (int) ($input['id'] ?? 0);
        $window = (int) ($input['ventanilla'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);

        if ($id < 1 || $window < 1 || $window > 9 || $userId < 1) {
            throw new RuntimeException('Ticket o ventanilla no validos para re-llamar.');
        }

        return $this->runQueueTransactionWithRetry(function () use ($id, $window, $userId): array {
            $ticket = $this->lockTicketForQueue($id);
            if ($ticket === null || (string) $ticket['estado'] === 'anulado') {
                throw new RuntimeException('Ticket no encontrado.');
            }

            if (!in_array((string) $ticket['cola_estado'], ['llamando', 'atendiendo'], true)) {
                throw new RuntimeException('Solo se puede re-llamar un ticket activo.');
            }

            if ((int) ($ticket['ventanilla_numero'] ?? 0) !== $window || (int) ($ticket['asignado_por'] ?? 0) !== $userId) {
                throw new RuntimeException('Este ticket no esta asignado a tu ventanilla.');
            }

            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado = \'llamando\',
                     cola_estado = \'llamando\',
                     asignado_en = ' . $this->turneroTimestampSql() . '
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);

            $this->audit->log($userId, 'TURNERO_RELLAMAR', 'ticket', $id, [
                'codigo' => $ticket['codigo'],
                'ventanilla' => $window,
            ]);
            $this->refreshScreenState((string) $ticket['fecha']);

            return $this->ticketById($id);
        });
    }

    public function updateQueueState(array $input, array $user): array
    {
        $this->ensureTurneroColumns();
        $id = (int) ($input['id'] ?? 0);
        $state = strtolower(trim((string) ($input['estado'] ?? '')));
        $relatedIds = $this->normalizeRelatedTicketIds($input['relacionados_atendidos'] ?? []);
        $validStates = ['en_cola', 'llamando', 'atendiendo', 'atendido', 'ausente'];

        if ($id < 1 || !in_array($state, $validStates, true)) {
            throw new RuntimeException('Estado de turnero no valido.');
        }

        return $this->runQueueTransactionWithRetry(function () use ($id, $state, $relatedIds, $user): array {
            $ticket = $this->lockTicketForQueue($id);
            if ($ticket === null || (string) $ticket['estado'] === 'anulado') {
                throw new RuntimeException('Ticket no encontrado.');
            }

            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado = :estado, cola_estado = :cola_estado
                 WHERE id = :id'
            );
            $stmt->execute([
                'estado' => $state,
                'cola_estado' => $state,
                'id' => $id,
            ]);

            $this->audit->log((int) $user['id'], 'TURNERO_ESTADO', 'ticket', $id, [
                'codigo' => $ticket['codigo'],
                'estado' => $state,
            ]);
            if (in_array($state, ['atendido', 'ausente'], true)) {
                $this->releaseCupoReservation($id, $state);
                $this->consumeSelectedRelatedDocumentTickets($ticket, $state, (int) $user['id'], $relatedIds);
            }
            $this->refreshScreenState((string) $ticket['fecha']);

            return $this->ticketById($id);
        });
    }

    public function screen(array $filters = []): array
    {
        $this->ensureScreenStateTable();
        $date = $this->normalizeDate((string) ($filters['fecha'] ?? date('Y-m-d')));

        return $this->screenState($date);
    }

    private function screenSnapshotFromTickets(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 18) . 't.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por, t.asignado_en,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.estado <> \'anulado\'
               AND t.cola_estado IN (\'llamando\', \'atendiendo\')
             ORDER BY CASE t.cola_estado WHEN \'llamando\' THEN 1 ELSE 2 END,
                      t.asignado_en DESC, t.id DESC'
               . Sql::limit($this->db, 18)
        );
        $stmt->execute(['fecha' => $date]);

        return [
            'fecha' => $date,
            'llamados' => array_map(fn (array $row): array => $this->formatScreenTicket($row), $stmt->fetchAll()),
            'resumen' => $this->queueSummary($date),
            'actualizado_en' => $this->screenPayloadTimestamp(),
        ];
    }

    public function assignedWindowForUser(int $userId): ?array
    {
        $this->ensureWindowAssignmentsTable();
        $stmt = $this->db->prepare(
            'SELECT ventanilla_numero, estado, asignado_en
             FROM st_usuario_ventanillas
             WHERE usuario_id = :usuario_id AND estado = \'activo\''
        );
        $stmt->execute(['usuario_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $ticket = $this->currentTicketForWindowUser((int) $row['ventanilla_numero'], $userId);

        return [
            'numero' => (int) $row['ventanilla_numero'],
            'modo' => $this->windowMode((int) $row['ventanilla_numero']),
            'modo_cola' => $this->windowQueueMode((int) $row['ventanilla_numero']),
            'modo_cola_label' => $this->windowQueueModeLabel(
                $this->windowQueueMode((int) $row['ventanilla_numero']),
                (int) $row['ventanilla_numero']
            ),
            'automatico' => $this->windowAutoCalls((int) $row['ventanilla_numero']),
            'estado' => (string) $row['estado'],
            'asignado_en' => (string) $row['asignado_en'],
            'ticket' => $ticket,
        ];
    }

    public function windowsAdmin(): array
    {
        $this->ensureWindowAssignmentsTable();
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();

        $assignments = [];
        $stmt = $this->db->query(
            'SELECT v.usuario_id, v.ventanilla_numero, v.estado, v.asignado_en,
                    u.nombres, u.apellidos, u.dni, u.email
             FROM st_usuario_ventanillas v
             INNER JOIN core_usuarios u ON u.id = v.usuario_id
             WHERE v.estado = \'activo\''
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            $dni = trim((string) ($row['dni'] ?? ''));
            $syntheticEmail = $this->isSyntheticDniEmail($email);
            $assignments[(int) $row['ventanilla_numero']] = [
                'usuario_id' => (int) $row['usuario_id'],
                'usuario' => trim((string) (($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? ''))) ?: $email,
                'email' => $syntheticEmail ? '' : $email,
                'dni' => $dni,
                'login_label' => $syntheticEmail ? ($dni !== '' ? 'DNI ' . $dni : '') : $email,
                'asignado_en' => (string) $row['asignado_en'],
            ];
        }
        $activeTickets = $this->activeTicketsByWindow(date('Y-m-d'));

        $windows = [];
        for ($number = 1; $number <= 9; $number++) {
            $ticket = $activeTickets[$number] ?? null;
            $windows[] = [
                'numero' => $number,
                'modo' => $this->windowMode($number),
                'modo_cola' => $this->windowQueueMode($number),
                'modo_cola_label' => $this->windowQueueModeLabel($this->windowQueueMode($number), $number),
                'automatico' => $this->windowAutoCalls($number),
                'asignacion' => $assignments[$number] ?? null,
                'ticket' => $ticket,
                'estado' => $ticket['cola_estado'] ?? (($assignments[$number] ?? null) ? 'disponible' : 'sin_asignar'),
            ];
        }

        return [
            'ventanillas' => $windows,
            'usuarios' => $this->activeUsersForWindowAssignment(),
        ];
    }

    public function assignWindow(array $input, array $admin): array
    {
        $this->ensureWindowAssignmentsTable();
        $userId = (int) ($input['usuario_id'] ?? 0);
        $window = (int) ($input['ventanilla'] ?? 0);

        if ($userId < 1 || $window < 1 || $window > 9) {
            throw new RuntimeException('Selecciona usuario y ventanilla validos.');
        }

        $this->db->beginTransaction();
        try {
            if ($this->currentTicketForWindow($window) !== null) {
                throw new RuntimeException('No se puede reasignar una ventanilla con ticket activo.');
            }

            $this->deactivateWindowAssignmentByUser($userId);
            $this->deactivateWindowAssignmentByWindow($window);

            $params = [
                'usuario_id' => $userId,
                'ventanilla' => $window,
                'asignado_por' => (int) $admin['id'],
            ];

            $stmt = $this->db->prepare(
                'UPDATE st_usuario_ventanillas
                 SET ventanilla_numero = :ventanilla,
                     estado = \'activo\',
                     asignado_por = :asignado_por,
                     asignado_en = ' . Sql::now($this->db) . ',
                     liberado_por = NULL,
                     liberado_en = NULL
                 WHERE usuario_id = :usuario_id'
            );
            $stmt->execute($params);

            if ($stmt->rowCount() < 1) {
                $stmt = $this->db->prepare(
                    'INSERT INTO st_usuario_ventanillas (usuario_id, ventanilla_numero, estado, asignado_por, asignado_en)
                     VALUES (:usuario_id, :ventanilla, \'activo\', :asignado_por, ' . Sql::now($this->db) . ')'
                );
                $stmt->execute($params);
            }

            $this->audit->log((int) $admin['id'], 'ASIGNAR_VENTANILLA', 'usuario', $userId, [
                'ventanilla' => $window,
            ]);
            $this->db->commit();

            return $this->windowsAdmin();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function releaseWindow(array $input, array $admin): array
    {
        $this->ensureWindowAssignmentsTable();
        $window = (int) ($input['ventanilla'] ?? 0);
        if ($window < 1 || $window > 9) {
            throw new RuntimeException('Selecciona una ventanilla valida.');
        }

        if ($this->currentTicketForWindow($window) !== null) {
            throw new RuntimeException('No se puede liberar una ventanilla con ticket activo.');
        }

        $this->deactivateWindowAssignmentByWindow($window, (int) $admin['id']);
        $this->audit->log((int) $admin['id'], 'LIBERAR_VENTANILLA', 'ventanilla', null, [
            'ventanilla' => $window,
        ]);

        return $this->windowsAdmin();
    }

    public function updateWindowQueueMode(array $input, array $admin): array
    {
        $window = (int) ($input['ventanilla'] ?? 0);
        $mode = $this->normalizeWindowQueueMode((string) ($input['modo'] ?? 'automatico'));

        if ($window < 1 || $window > 9) {
            throw new RuntimeException('Selecciona una ventanilla valida.');
        }

        Sql::upsertConfig($this->db, $this->windowQueueModeKey($window), $mode);
        $this->audit->log((int) $admin['id'], 'CONFIGURAR_COLA_VENTANILLA', 'ventanilla', null, [
            'ventanilla' => $window,
            'modo' => $mode,
        ]);

        return $this->windowsAdmin();
    }

    public function getTicket(int $id): array
    {
        $this->ensureTicketPriorityColumn();
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . '
                t.id, t.uuid, t.fecha, t.hora, t.tipo_ticket,
                COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                t.numero, t.codigo,
                t.tipo_documento, t.numero_documento, t.estado, t.estado_impresion, t.reimpresiones,
                COALESCE(e.nombre, \'Sin especialidad\') AS especialidad
             FROM st_tickets t
             LEFT JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.id = :id' . Sql::limit($this->db, 1)
        );
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($ticket)) {
            throw new RuntimeException('Ticket no encontrado.');
        }

        return $ticket;
    }

    public function resolveTicketIdByCodigo(string $codigo, ?string $fecha = null): int
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return 0;
        }

        $fecha = $fecha ?? date('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 'id
             FROM st_tickets
             WHERE codigo = :codigo AND fecha = :fecha' . Sql::limit($this->db, 1)
        );
        $stmt->execute(['codigo' => $codigo, 'fecha' => $fecha]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function reprint(int $id, array $user, string $scope = 'historial'): array
    {
        $this->ensureTicketPriorityColumn();
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT ' . Sql::top($this->db, 1) . '
                    t.id, t.uuid, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo,
                    t.tipo_documento, t.numero_documento, t.estado, t.estado_impresion, t.reimpresiones,
                    e.nombre AS especialidad
                 FROM ' . Sql::ticketsFrom($this->db, 't') . '
                 INNER JOIN core_especialidades e ON e.id = t.especialidad_id
                 WHERE t.id = :id' . Sql::limit($this->db, 1) . Sql::lockSuffix($this->db)
            );
            $stmt->execute(['id' => $id]);
            $ticket = $stmt->fetch();

            if (!is_array($ticket)) {
                throw new RuntimeException('Ticket no encontrado.');
            }

            if ((string) $ticket['estado'] === 'anulado') {
                throw new RuntimeException('No se puede reimprimir un ticket anulado.');
            }

            if ($scope === 'dia' && (string) $ticket['fecha'] !== date('Y-m-d')) {
                throw new RuntimeException('Este permiso solo permite reimprimir tickets emitidos hoy.');
            }

            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET reimpresiones = reimpresiones + 1
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);

            $ticket['reimpresiones'] = (int) $ticket['reimpresiones'] + 1;
            $this->audit->log((int) $user['id'], 'REIMPRIMIR_TICKET', 'ticket', $id, [
                'codigo' => $ticket['codigo'],
                'reimpresiones' => $ticket['reimpresiones'],
            ]);

            $this->db->commit();

            return $ticket;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function cancel(int $id, string $reason, array $user): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Ingrese el motivo de anulacion.');
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'SELECT ' . Sql::top($this->db, 1) . 'id, codigo, estado
                 FROM ' . Sql::ticketsFrom($this->db) . '
                 WHERE id = :id' . Sql::limit($this->db, 1) . Sql::lockSuffix($this->db)
            );
            $stmt->execute(['id' => $id]);
            $ticket = $stmt->fetch();

            if (!is_array($ticket)) {
                throw new RuntimeException('Ticket no encontrado.');
            }

            if ((string) $ticket['estado'] === 'anulado') {
                throw new RuntimeException('El ticket ya esta anulado.');
            }

            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado = \'anulado\',
                     cola_estado = \'sin_cola\',
                     anulado_en = ' . Sql::now($this->db) . ',
                     anulado_por = :anulado_por,
                     motivo_anulacion = :motivo
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'anulado_por' => (int) $user['id'],
                'motivo' => $reason,
            ]);

            $this->audit->log((int) $user['id'], 'ANULAR_TICKET', 'ticket', $id, [
                'codigo' => $ticket['codigo'],
                'motivo' => $reason,
            ]);
            $this->releaseCupoReservation($id, 'anulado');

            $this->db->commit();

            return ['id' => $id, 'codigo' => $ticket['codigo'], 'estado' => 'anulado'];
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function markPrinted(int $id, string $codigo, array $user, bool $printOnly = false): array
    {
        $this->ensureTicketPriorityColumn();
        $id = (int) $id;
        if ($id < 1 && $codigo !== '') {
            $id = $this->resolveTicketIdByCodigo($codigo);
        }

        if ($id < 1) {
            throw new RuntimeException('Ticket no encontrado.');
        }

        $ticket = $this->getTicket($id);
        $priority = in_array((string) ($ticket['prioridad_atencion'] ?? ''), ['Preferencial', 'Normal'], true)
            ? (string) $ticket['prioridad_atencion']
            : null;

        if ($priority !== null && !$printOnly) {
            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado_impresion = \'impreso\',
                     estado = CASE WHEN estado = \'emitido\' THEN \'en_cola\' ELSE estado END,
                     cola_estado = CASE WHEN cola_estado = \'sin_cola\' THEN \'en_cola\' ELSE cola_estado END,
                     prioridad_atencion = :prioridad_atencion
                 WHERE id = :id AND estado <> \'anulado\''
            );
            $stmt->execute(['id' => $id, 'prioridad_atencion' => $priority]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado_impresion = \'impreso\',
                     cola_estado = \'sin_cola\'
                 WHERE id = :id AND estado <> \'anulado\''
            );
            $stmt->execute(['id' => $id]);
        }

        $this->audit->log((int) $user['id'], 'CONFIRMAR_IMPRESION', 'ticket', $id, [
            'codigo' => $codigo,
            'cola' => $printOnly ? null : $priority,
            'modo_emision' => $printOnly ? 'solo_impresion' : 'normal',
        ]);

        return [
            'id' => $id,
            'codigo' => $codigo,
            'estado_impresion' => 'impreso',
            'cola_estado' => $priority === null || $printOnly ? 'sin_cola' : 'en_cola',
            'prioridad_atencion' => $priority,
            'modo_emision' => $printOnly ? 'solo_impresion' : 'normal',
        ];
    }

    public function markPrintCanceled(int $id, string $codigo, array $user): array
    {
        $id = (int) $id;
        if ($id < 1 && $codigo !== '') {
            $id = $this->resolveTicketIdByCodigo($codigo);
        }

        if ($id < 1) {
            throw new RuntimeException('Ticket no encontrado.');
        }

        $stmt = $this->db->prepare(
            'UPDATE st_tickets
             SET estado_impresion = \'cancelado\'
             WHERE id = :id AND estado <> \'anulado\' AND estado_impresion = \'pendiente\''
        );
        $stmt->execute(['id' => $id]);

        $this->audit->log((int) $user['id'], 'CANCELAR_IMPRESION', 'ticket', $id, ['codigo' => $codigo]);
        $this->releaseCupoReservation($id, 'impresion_cancelada');

        return [
            'id' => $id,
            'codigo' => $codigo,
            'estado_impresion' => 'cancelado',
        ];
    }

    public function emit(array $input, array $user): array
    {
        $this->ensureTicketPriorityColumn();
        $type = $this->normalizeType((string) ($input['tipo_ticket'] ?? ''));
        $priority = $type === 'Procedimiento' ? null : $type;
        $documentType = strtoupper(trim((string) ($input['tipo_documento'] ?? 'DNI')));
        $documentNumber = strtoupper(trim((string) ($input['numero_documento'] ?? '')));
        $specialtyId = (int) ($input['especialidad_id'] ?? 0);
        $printOnly = (string) ($input['modo_emision'] ?? '') === 'solo_impresion';
        $userId = (int) $user['id'];
        $today = date('Y-m-d');
        $time = date('H:i:s');
        $cupoReservationDate = null;

        $this->validateDocument($documentType, $documentNumber);

        $specialty = $this->specialties->findActive($specialtyId);
        if ($specialty === null) {
            throw new RuntimeException('Selecciona una especialidad valida.');
        }

        $this->db->beginTransaction();

        try {
            $range = $this->lockOrCreateRange($today, $type, $userId);

            if ((int) $range['activo'] !== 1) {
                throw new RuntimeException('El rango seleccionado no esta activo.');
            }

            $nextNumber = (int) $range['ultimo'] + 1;
            if ($nextNumber > (int) $range['hasta']) {
                throw new RuntimeException('No quedan tickets disponibles para ' . $type . '.');
            }

            if (!$this->isTestDocument($documentType, $documentNumber)) {
                $duplicate = $this->findDuplicate($today, $type, $documentType, $documentNumber, $specialtyId);
                if ($duplicate !== null) {
                    throw new RuntimeException('Este documento ya tiene un ticket ' . $type . ' para ' . $specialty['nombre'] . ' hoy: ' . $duplicate['codigo']);
                }

                $this->validateDailyDocumentSpecialtyLimit($today, $documentType, $documentNumber, $specialtyId);
            }

            $sighEspecialidadId = (int) ($specialty['sigh_especialidad_id'] ?? 0);
            if ($sighEspecialidadId > 0 && !$printOnly) {
                $this->lockSpecialtyForReservation($specialtyId);
                $cupoOperativo = $this->citasDisponibles()->cupoOperativoTiNeo($sighEspecialidadId, $today);
                $cuposDisponibles = (int) ($cupoOperativo['disponibles'] ?? 0);
                $cupoReservationDate = (string) ($cupoOperativo['fecha'] ?? '');
                if ($cuposDisponibles < 1 || $cupoReservationDate === '') {
                    throw new RuntimeException('No hay cupos disponibles para ' . $specialty['nombre'] . ' en SIGH.');
                }
            }

            $code = self::PREFIXES[$type] . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
            $uuid = $this->uuid();

            $insertParams = [
                'uuid' => $uuid,
                'fecha' => $today,
                'hora' => $time,
                'tipo_ticket' => $type,
                'prioridad_atencion' => $priority,
                'numero' => $nextNumber,
                'codigo' => $code,
                'tipo_documento' => $documentType,
                'numero_documento' => $documentNumber,
                'especialidad_id' => $specialtyId,
                'creado_por' => $userId,
            ];

            if (Sql::isSqlServer($this->db)) {
                $stmt = $this->db->prepare(
                    'INSERT INTO st_tickets (
                        uuid, fecha, hora, tipo_ticket, prioridad_atencion, numero, codigo,
                        tipo_documento, numero_documento, especialidad_id,
                        estado, estado_impresion, cola_estado, reimpresiones, creado_por, creado_en
                     )
                     OUTPUT INSERTED.id AS inserted_id
                     VALUES (
                        :uuid, :fecha, :hora, :tipo_ticket, :prioridad_atencion, :numero, :codigo,
                        :tipo_documento, :numero_documento, :especialidad_id,
                        \'emitido\', \'pendiente\', \'sin_cola\', 0, :creado_por, ' . Sql::now($this->db) . '
                     )'
                );
                $stmt->execute($insertParams);
                $inserted = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $ticketId = (int) ($inserted['inserted_id'] ?? $inserted['INSERTED_ID'] ?? 0);
                if ($ticketId < 1) {
                    $ticketId = Sql::lastInsertId($this->db);
                }
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO st_tickets (
                        uuid, fecha, hora, tipo_ticket, prioridad_atencion, numero, codigo,
                        tipo_documento, numero_documento, especialidad_id,
                        estado, estado_impresion, cola_estado, reimpresiones, creado_por, creado_en
                     ) VALUES (
                        :uuid, :fecha, :hora, :tipo_ticket, :prioridad_atencion, :numero, :codigo,
                        :tipo_documento, :numero_documento, :especialidad_id,
                        \'emitido\', \'pendiente\', \'sin_cola\', 0, :creado_por, ' . Sql::now($this->db) . '
                     )'
                );
                $stmt->execute($insertParams);
                $ticketId = Sql::lastInsertId($this->db);
            }

            if ($ticketId < 1) {
                throw new RuntimeException('No se pudo registrar el ticket emitido.');
            }

            $stmt = $this->db->prepare(
                'UPDATE st_rangos
                 SET ultimo = :ultimo, actualizado_en = ' . Sql::now($this->db) . '
                 WHERE id = :id'
            );
            $stmt->execute(['ultimo' => $nextNumber, 'id' => (int) $range['id']]);

            if ($sighEspecialidadId > 0 && !$printOnly) {
                $this->createCupoReservation($ticketId, $specialtyId, $sighEspecialidadId, $cupoReservationDate ?: $today);
            }

            $this->audit->log($userId, 'EMITIR_TICKET', 'ticket', $ticketId, [
                'codigo' => $code,
                'tipo' => $type,
                'prioridad' => $priority,
                'documento' => $documentType,
                'especialidad' => $specialty['nombre'],
                'modo_emision' => $printOnly ? 'solo_impresion' : 'normal',
                'cupo_fecha' => $cupoReservationDate,
            ]);

            $this->db->commit();

            return [
                'id' => (int) $ticketId,
                'uuid' => $uuid,
                'fecha' => $today,
                'hora' => $time,
                'tipo_ticket' => $type,
                'prioridad_atencion' => $priority,
                'numero' => $nextNumber,
                'codigo' => $code,
                'tipo_documento' => $documentType,
                'numero_documento' => $documentNumber,
                'especialidad' => $specialty['nombre'],
                'cupo_fecha' => $cupoReservationDate,
            ];
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function lockOrCreateRange(string $date, string $type, int $userId): array
    {
        $range = $this->lockRange($date, $type);
        if ($range !== null) {
            return $range;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO st_rangos (fecha, tipo_ticket, desde, hasta, ultimo, activo, creado_por, creado_en)
             VALUES (:fecha, :tipo_ticket, 1, :hasta, 0, 1, :creado_por, ' . Sql::now($this->db) . ')'
        );
        $stmt->execute([
            'fecha' => $date,
            'tipo_ticket' => $type,
            'hasta' => self::DEFAULT_RANGE_END,
            'creado_por' => $userId,
        ]);

        $range = $this->lockRange($date, $type);
        if ($range === null) {
            throw new RuntimeException('No se pudo crear el rango diario.');
        }

        return $range;
    }

    private function lockRange(string $date, string $type): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 'id, fecha, tipo_ticket, desde, hasta, ultimo, activo
             FROM ' . Sql::rangosFrom($this->db) . '
             WHERE fecha = :fecha AND tipo_ticket = :tipo_ticket' . Sql::limit($this->db, 1) . Sql::lockSuffix($this->db)
        );
        $stmt->execute(['fecha' => $date, 'tipo_ticket' => $type]);
        $range = $stmt->fetch();

        return is_array($range) ? $range : null;
    }

    private function citasDisponibles(): CitasDisponiblesService
    {
        if (!$this->citasDisponiblesFactory instanceof \Closure) {
            throw new RuntimeException('La validacion de cupos SIGH no esta configurada.');
        }

        $service = ($this->citasDisponiblesFactory)();
        if (!$service instanceof CitasDisponiblesService) {
            throw new RuntimeException('La validacion de cupos SIGH no esta disponible.');
        }

        return $service;
    }

    private function lockSpecialtyForReservation(int $specialtyId): void
    {
        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                'SELECT id
                 FROM dbo.core_especialidades WITH (UPDLOCK, ROWLOCK)
                 WHERE id = :id'
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT id
                 FROM core_especialidades
                 WHERE id = :id
                 FOR UPDATE'
            );
        }

        $stmt->execute(['id' => $specialtyId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Especialidad no encontrada para reservar cupo.');
        }
    }

    private function createCupoReservation(int $ticketId, int $specialtyId, int $sighEspecialidadId, string $date): void
    {
        $this->ensureCupoReservationTable();
        $stmt = $this->db->prepare(
            'INSERT INTO st_cupo_reservas (
                ticket_id, core_especialidad_id, sigh_especialidad_id, fecha, estado, reservado_en
             ) VALUES (
                :ticket_id, :core_especialidad_id, :sigh_especialidad_id, :fecha, \'reservada\', ' . Sql::now($this->db) . '
             )'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'core_especialidad_id' => $specialtyId,
            'sigh_especialidad_id' => $sighEspecialidadId,
            'fecha' => $date,
        ]);
    }

    private function releaseCupoReservation(int $ticketId, string $reason): void
    {
        $this->ensureCupoReservationTable();
        $stmt = $this->db->prepare(
            'UPDATE st_cupo_reservas
             SET estado = \'liberada\',
                 liberado_en = ' . Sql::now($this->db) . ',
                 liberado_motivo = :motivo
             WHERE ticket_id = :ticket_id AND estado = \'reservada\''
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'motivo' => substr($reason, 0, 40),
        ]);
    }

    private function ensureCupoReservationTable(): void
    {
        if ($this->tableExists('st_cupo_reservas')) {
            return;
        }

        if (Sql::isSqlServer($this->db)) {
            $this->db->exec(
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

        $this->db->exec(
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
        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare("SELECT OBJECT_ID(:name, 'U') AS table_id");
            $stmt->execute(['name' => 'dbo.' . $table]);

            return $stmt->fetchColumn() !== null;
        }

        $stmt = $this->db->prepare("SHOW TABLES LIKE :table");
        $stmt->execute(['table' => $table]);

        return $stmt->fetchColumn() !== false;
    }

    private function findDuplicate(string $date, string $type, string $documentType, string $documentNumber, int $specialtyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 'id, codigo
             FROM ' . Sql::ticketsFrom($this->db) . '
             WHERE fecha = :fecha
               AND tipo_ticket = :tipo_ticket
               AND tipo_documento = :tipo_documento
               AND numero_documento = :numero_documento
               AND especialidad_id = :especialidad_id
               AND estado <> \'anulado\'' . Sql::limit($this->db, 1) . Sql::lockSuffix($this->db)
        );
        $stmt->execute([
            'fecha' => $date,
            'tipo_ticket' => $type,
            'tipo_documento' => $documentType,
            'numero_documento' => $documentNumber,
            'especialidad_id' => $specialtyId,
        ]);
        $duplicate = $stmt->fetch();

        return is_array($duplicate) ? $duplicate : null;
    }

    private function validateDailyDocumentSpecialtyLimit(string $date, string $documentType, string $documentNumber, int $specialtyId): void
    {
        $stmt = $this->db->prepare(
            'SELECT especialidad_id, MIN(codigo) AS codigo
             FROM ' . Sql::ticketsFrom($this->db) . '
             WHERE fecha = :fecha
               AND tipo_documento = :tipo_documento
               AND numero_documento = :numero_documento
               AND estado <> \'anulado\'
             GROUP BY especialidad_id' . Sql::lockSuffix($this->db)
        );
        $stmt->execute([
            'fecha' => $date,
            'tipo_documento' => $documentType,
            'numero_documento' => $documentNumber,
        ]);

        $specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existingSpecialtyIds = array_map(static fn (array $row): int => (int) $row['especialidad_id'], $specialties);
        if (in_array($specialtyId, $existingSpecialtyIds, true)) {
            return;
        }

        if (count($existingSpecialtyIds) < self::MAX_DAILY_DOCUMENT_SPECIALTIES) {
            return;
        }

        $codes = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['codigo'] ?? ''), $specialties)));
        $detail = $codes === [] ? '' : ': ' . implode(', ', $codes);

        throw new RuntimeException(
            'Este documento ya tiene tickets para ' . self::MAX_DAILY_DOCUMENT_SPECIALTIES . ' especialidades hoy' . $detail . '. No se puede emitir otra especialidad.'
        );
    }

    private function formatRange(array $row): array
    {
        $to = (int) $row['hasta'];
        $last = (int) $row['ultimo'];

        return [
            'tipo' => $this->displayTicketType((string) $row['tipo_ticket']),
            'desde' => (int) $row['desde'],
            'hasta' => $to,
            'ultimo' => $last,
            'disponible' => max(0, $to - $last),
            'activo' => (int) $row['activo'] === 1 ? 'SI' : 'NO',
        ];
    }

    private function batchSpecialtyId(): int
    {
        $name = 'LOTE / REIMPRESION';
        $stmt = $this->db->prepare('SELECT id FROM core_especialidades WHERE nombre = :nombre');
        $stmt->execute(['nombre' => $name]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                'INSERT INTO core_especialidades (nombre, estado, orden, creado_en)
                 OUTPUT INSERTED.id AS inserted_id
                 VALUES (:nombre, \'activo\', 999, ' . Sql::now($this->db) . ')'
            );
            $stmt->execute(['nombre' => $name]);
            $inserted = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return (int) ($inserted['inserted_id'] ?? $inserted['INSERTED_ID'] ?? 0);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO core_especialidades (nombre, estado, orden, creado_en)
             VALUES (:nombre, \'activo\', 999, ' . Sql::now($this->db) . ')'
        );
        $stmt->execute(['nombre' => $name]);

        return Sql::lastInsertId($this->db);
    }

    private function lockTicketForQueue(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 'id, fecha, codigo, estado, estado_impresion, cola_estado,
                    tipo_documento, numero_documento, ventanilla_numero, asignado_por
             FROM ' . Sql::ticketsFrom($this->db) . '
             WHERE id = :id' . Sql::limit($this->db, 1) . Sql::lockSuffix($this->db)
        );
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($ticket) ? $ticket : null;
    }

    private function ticketById(int $id): array
    {
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 't.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.id = :id' . Sql::limit($this->db, 1)
        );
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket)) {
            throw new RuntimeException('Ticket no encontrado.');
        }

        return $this->attachRelatedDocumentTickets($this->formatQueueTicket($ticket));
    }

    private function formatQueueTicket(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha' => (string) $row['fecha'],
            'hora' => (string) $row['hora'],
            'tipo_ticket' => $this->displayTicketType((string) $row['tipo_ticket']),
            'prioridad_atencion' => (string) ($row['prioridad_atencion'] ?? $row['tipo_ticket']),
            'numero' => (int) $row['numero'],
            'codigo' => (string) $row['codigo'],
            'tipo_documento' => (string) $row['tipo_documento'],
            'numero_documento' => (string) $row['numero_documento'],
            'especialidad' => (string) $row['especialidad'],
            'estado' => (string) $row['estado'],
            'cola_estado' => (string) $row['cola_estado'],
            'estado_impresion' => (string) $row['estado_impresion'],
            'ventanilla_numero' => isset($row['ventanilla_numero']) ? (int) $row['ventanilla_numero'] : null,
            'asignado_por' => isset($row['asignado_por']) ? (int) $row['asignado_por'] : null,
            'creado_en' => (string) $row['creado_en'],
        ];
    }

    private function attachRelatedDocumentTickets(array $ticket): array
    {
        $documentType = trim((string) ($ticket['tipo_documento'] ?? ''));
        $documentNumber = trim((string) ($ticket['numero_documento'] ?? ''));
        $date = trim((string) ($ticket['fecha'] ?? ''));
        $id = (int) ($ticket['id'] ?? 0);

        if ($id < 1 || $date === '' || $documentType === '' || $documentNumber === '') {
            $ticket['tickets_relacionados'] = [];
            $ticket['tickets_relacionados_total'] = 0;

            return $ticket;
        }

        $stmt = $this->db->prepare(
            'SELECT t.id, t.codigo, t.tipo_ticket, t.tipo_documento, t.numero_documento,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.estado, t.cola_estado, t.ventanilla_numero, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.tipo_documento = :tipo_documento
               AND t.numero_documento = :numero_documento
               AND t.id <> :id
               AND t.estado <> \'anulado\'
               AND t.estado_impresion = \'impreso\'
               AND t.cola_estado IN (\'en_cola\', \'llamando\', \'atendiendo\')
             ORDER BY COALESCE(t.asignado_en, t.creado_en) ASC, t.id ASC'
        );
        $stmt->execute([
            'fecha' => $date,
            'tipo_documento' => $documentType,
            'numero_documento' => $documentNumber,
            'id' => $id,
        ]);

        $related = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'tipo_ticket' => $this->displayTicketType((string) $row['tipo_ticket']),
                'prioridad_atencion' => (string) ($row['prioridad_atencion'] ?? $row['tipo_ticket']),
                'tipo_documento' => (string) $row['tipo_documento'],
                'numero_documento' => (string) $row['numero_documento'],
                'especialidad' => (string) $row['especialidad'],
                'estado' => (string) $row['estado'],
                'cola_estado' => (string) $row['cola_estado'],
                'ventanilla_numero' => isset($row['ventanilla_numero']) ? (int) $row['ventanilla_numero'] : null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $ticket['tickets_relacionados'] = $related;
        $ticket['tickets_relacionados_total'] = count($related);

        return $ticket;
    }

    private function normalizeRelatedTicketIds($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function consumeSelectedRelatedDocumentTickets(array $ticket, string $state, int $userId, array $ids): void
    {
        $documentType = trim((string) ($ticket['tipo_documento'] ?? ''));
        $documentNumber = trim((string) ($ticket['numero_documento'] ?? ''));
        $date = trim((string) ($ticket['fecha'] ?? ''));
        $id = (int) ($ticket['id'] ?? 0);

        if ($id < 1 || $date === '' || $documentType === '' || $documentNumber === '' || $ids === []) {
            return;
        }

        $idPlaceholders = [];
        $selectParams = [
            'fecha' => $date,
            'tipo_documento' => $documentType,
            'numero_documento' => $documentNumber,
            'id' => $id,
        ];
        foreach ($ids as $index => $relatedId) {
            $key = 'selected_id' . $index;
            $idPlaceholders[] = ':' . $key;
            $selectParams[$key] = $relatedId;
        }

        $stmt = $this->db->prepare(
            'SELECT id, codigo
             FROM ' . Sql::ticketsFrom($this->db) . '
             WHERE fecha = :fecha
               AND tipo_documento = :tipo_documento
               AND numero_documento = :numero_documento
               AND id <> :id
               AND id IN (' . implode(', ', $idPlaceholders) . ')
               AND estado <> \'anulado\'
               AND estado_impresion = \'impreso\'
               AND cola_estado IN (\'en_cola\', \'llamando\', \'atendiendo\')'
               . Sql::lockSuffix($this->db)
        );
        $stmt->execute($selectParams);

        $related = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$related) {
            return;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $related);
        $placeholders = [];
        $params = [
            'estado' => $state,
            'cola_estado' => $state,
            'ventanilla' => isset($ticket['ventanilla_numero']) ? (int) $ticket['ventanilla_numero'] : null,
            'asignado_por' => $userId,
        ];

        foreach ($ids as $index => $relatedId) {
            $key = 'id' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $relatedId;
        }

        $update = $this->db->prepare(
            'UPDATE st_tickets
             SET estado = :estado,
                 cola_estado = :cola_estado,
                 ventanilla_numero = COALESCE(ventanilla_numero, :ventanilla),
                 asignado_por = COALESCE(asignado_por, :asignado_por),
                 asignado_en = COALESCE(asignado_en, ' . $this->turneroTimestampSql() . ')
             WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $update->execute($params);

        foreach ($ids as $relatedId) {
            $this->releaseCupoReservation($relatedId, $state);
        }

        $this->audit->log($userId, 'TURNERO_CONSUMIR_DOCUMENTO', 'ticket', $id, [
            'codigo' => (string) ($ticket['codigo'] ?? ''),
            'estado' => $state,
            'relacionados' => array_map(static fn (array $row): string => (string) ($row['codigo'] ?? $row['id']), $related),
        ]);
    }

    private function formatScreenTicket(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha' => (string) $row['fecha'],
            'hora' => (string) $row['hora'],
            'tipo_ticket' => $this->displayTicketType((string) $row['tipo_ticket']),
            'prioridad_atencion' => (string) ($row['prioridad_atencion'] ?? $row['tipo_ticket']),
            'numero' => (int) $row['numero'],
            'codigo' => (string) $row['codigo'],
            'especialidad' => (string) $row['especialidad'],
            'estado' => (string) $row['estado'],
            'cola_estado' => (string) $row['cola_estado'],
            'ventanilla_numero' => isset($row['ventanilla_numero']) ? (int) $row['ventanilla_numero'] : null,
            'asignado_en' => (string) ($row['asignado_en'] ?? ''),
        ];
    }

    private function sanitizeScreenPayload(array $payload): array
    {
        $payload['llamados'] = array_map(function (array $ticket): array {
            return [
                'id' => (int) ($ticket['id'] ?? 0),
                'fecha' => (string) ($ticket['fecha'] ?? ''),
                'hora' => (string) ($ticket['hora'] ?? ''),
                'tipo_ticket' => (string) ($ticket['tipo_ticket'] ?? ''),
                'prioridad_atencion' => (string) ($ticket['prioridad_atencion'] ?? $ticket['tipo_ticket'] ?? ''),
                'numero' => (int) ($ticket['numero'] ?? 0),
                'codigo' => (string) ($ticket['codigo'] ?? ''),
                'especialidad' => (string) ($ticket['especialidad'] ?? ''),
                'estado' => (string) ($ticket['estado'] ?? ''),
                'cola_estado' => (string) ($ticket['cola_estado'] ?? ''),
                'ventanilla_numero' => isset($ticket['ventanilla_numero']) ? (int) $ticket['ventanilla_numero'] : null,
                'asignado_en' => (string) ($ticket['asignado_en'] ?? ''),
            ];
        }, array_filter($payload['llamados'] ?? [], 'is_array'));

        return $payload;
    }

    private function nextTicketIdForWindow(string $date, int $window, string $specialtyWhere, array $params): int
    {
        return $this->firstQueuedTicketId($date, $this->prioritiesForWindow($date, $window), $specialtyWhere, $params);
    }

    private function claimNextTicketIdForWindow(string $date, int $window, string $specialtyWhere, array $params, int $userId): int
    {
        if (!Sql::isSqlServer($this->db)) {
            $id = $this->nextTicketIdForWindow($date, $window, $specialtyWhere, $params);
            if ($id < 1) {
                return 0;
            }

            $stmt = $this->db->prepare(
                'UPDATE st_tickets
                 SET estado = \'llamando\',
                     cola_estado = \'llamando\',
                     ventanilla_numero = :ventanilla,
                     asignado_por = :asignado_por,
                     asignado_en = ' . $this->turneroTimestampSql() . '
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'ventanilla' => $window,
                'asignado_por' => $userId,
            ]);

            return $id;
        }

        foreach ($this->prioritiesForWindow($date, $window) as $priority) {
            $claimParams = $params;
            $claimParams['prioridad_atencion'] = $priority;
            $claimParams['ventanilla'] = $window;
            $claimParams['asignado_por'] = $userId;

            $stmt = $this->db->prepare(
                ';WITH siguiente AS (
                    SELECT TOP (1) id
                    FROM dbo.st_tickets WITH (UPDLOCK, READPAST, ROWLOCK)
                    WHERE fecha = :fecha
                      AND estado = \'en_cola\'
                      AND cola_estado = \'en_cola\'
                      AND prioridad_atencion = :prioridad_atencion'
                      . $specialtyWhere . '
                    ORDER BY COALESCE(asignado_en, creado_en) ASC, id ASC
                 )
                 UPDATE t
                 SET estado = \'llamando\',
                     cola_estado = \'llamando\',
                     ventanilla_numero = :ventanilla,
                     asignado_por = :asignado_por,
                     asignado_en = ' . $this->turneroTimestampSql() . '
                 OUTPUT inserted.id
                 FROM dbo.st_tickets t
                 INNER JOIN siguiente s ON s.id = t.id'
            );
            $stmt->execute($claimParams);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function runQueueTransactionWithRetry(callable $callback): array
    {
        $attempts = 0;

        while (true) {
            $this->db->beginTransaction();

            try {
                $result = $callback();
                $this->db->commit();

                return $result;
            } catch (\Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                if ($attempts < 2 && $this->isQueueConcurrencyError($exception)) {
                    $attempts++;
                    usleep(120000 * $attempts);
                    continue;
                }

                if ($this->isQueueConcurrencyError($exception)) {
                    throw new RuntimeException('La cola fue actualizada por otro usuario. Actualiza e intenta nuevamente.');
                }

                throw $exception;
            }
        }
    }

    private function isQueueConcurrencyError(\Throwable $exception): bool
    {
        if ($this->isDatabaseDeadlock($exception)) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        $code = strtoupper((string) $exception->getCode());

        return in_array($code, ['HYT00', 'HYT01'], true)
            || str_contains($message, 'lock request time out')
            || str_contains($message, 'lock timeout')
            || str_contains($message, 'timeout expired')
            || str_contains($message, 'tiempo de espera')
            || str_contains($message, 'could not serialize')
            || str_contains($message, 'serialization failure')
            || str_contains($message, 'transaccion')
            || (str_contains($message, 'transaction') && str_contains($message, 'lock'));
    }

    private function isDatabaseDeadlock(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $code = (string) $exception->getCode();

        if (str_contains($message, 'deadlock') || str_contains($message, 'interbloqueo')) {
            return true;
        }

        return in_array($code, ['40001', '1205'], true);
    }

    private function turneroTimestampSql(): string
    {
        return Sql::isSqlServer($this->db) ? 'SYSDATETIME()' : 'NOW(3)';
    }

    private function screenPayloadTimestamp(): string
    {
        $now = microtime(true);
        $seconds = (int) floor($now);
        $milliseconds = (int) floor(($now - $seconds) * 1000);

        return date('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $milliseconds, 3, '0', STR_PAD_LEFT);
    }

    private function prioritiesForWindow(string $date, int $window): array
    {
        $mode = $this->windowQueueMode($window);
        if ($mode === 'preferencial') {
            return ['Preferencial'];
        }

        if ($mode === 'normal') {
            return ['Normal'];
        }

        if (in_array($window, [1, 2], true)) {
            return ['Preferencial', 'Normal'];
        }

        if (in_array($window, [3, 4, 5, 6], true)) {
            $preferred = $this->normalWindowShouldSupportPreferential($date) ? 'Preferencial' : 'Normal';
            $fallback = $preferred === 'Normal' ? 'Preferencial' : 'Normal';

            return [$preferred, $fallback];
        }

        return ['Normal', 'Preferencial'];
    }

    private function firstQueuedTicketId(string $date, array $priorities, string $specialtyWhere, array $baseParams): int
    {
        foreach ($priorities as $priority) {
            $params = $baseParams;
            $params['prioridad_atencion'] = $priority;
            $stmt = $this->db->prepare(
                'SELECT ' . Sql::top($this->db, 1) . 'id
                 FROM ' . Sql::ticketsFrom($this->db) . '
                 WHERE fecha = :fecha
                   AND estado = \'en_cola\'
                   AND cola_estado = \'en_cola\'
                   AND prioridad_atencion = :prioridad_atencion'
                   . $specialtyWhere . '
                 ORDER BY COALESCE(asignado_en, creado_en) ASC, id ASC'
                   . Sql::limit($this->db, 1) . Sql::lockSuffix($this->db)
            );
            $stmt->execute($params);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function normalWindowShouldSupportPreferential(string $date): bool
    {
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 30) . 'prioridad_atencion
             FROM st_tickets
             WHERE fecha = :fecha
               AND ventanilla_numero IN (3, 4, 5, 6)
               AND cola_estado IN (\'atendido\', \'ausente\')
               AND prioridad_atencion IN (\'Preferencial\', \'Normal\')
             ORDER BY asignado_en DESC, id DESC' . Sql::limit($this->db, 30)
        );
        $stmt->execute(['fecha' => $date]);
        $rows = $stmt->fetchAll();
        if (count($rows) < 6) {
            return $this->queuedCount($date, 'Preferencial') > 0 && $this->queuedCount($date, 'Normal') > 1;
        }

        $preferential = 0;
        foreach ($rows as $row) {
            if ((string) $row['prioridad_atencion'] === 'Preferencial') {
                $preferential++;
            }
        }

        return ($preferential / max(1, count($rows))) < 0.30 && $this->queuedCount($date, 'Preferencial') > 0;
    }

    private function queuedCount(string $date, string $priority): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM st_tickets
             WHERE fecha = :fecha
               AND estado = \'en_cola\'
               AND cola_estado = \'en_cola\'
               AND prioridad_atencion = :prioridad_atencion'
        );
        $stmt->execute(['fecha' => $date, 'prioridad_atencion' => $priority]);

        return (int) $stmt->fetchColumn();
    }

    private function currentTicketForWindowUser(int $window, int $userId): ?array
    {
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 't.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.ventanilla_numero = :ventanilla
               AND t.cola_estado IN (\'llamando\', \'atendiendo\')
             ORDER BY t.asignado_en DESC, t.id DESC' . Sql::limit($this->db, 1)
        );
        $stmt->execute([
            'fecha' => date('Y-m-d'),
            'ventanilla' => $window,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->attachRelatedDocumentTickets($this->formatQueueTicket($row)) : null;
    }

    private function currentTicketForWindow(int $window): ?array
    {
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();
        $stmt = $this->db->prepare(
            'SELECT ' . Sql::top($this->db, 1) . 't.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.ventanilla_numero = :ventanilla
               AND t.cola_estado IN (\'llamando\', \'atendiendo\')
             ORDER BY t.asignado_en DESC, t.id DESC' . Sql::limit($this->db, 1)
        );
        $stmt->execute([
            'fecha' => date('Y-m-d'),
            'ventanilla' => $window,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->attachRelatedDocumentTickets($this->formatQueueTicket($row)) : null;
    }

    private function activeTicketsByWindow(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.fecha, t.hora, t.tipo_ticket,
                    COALESCE(t.prioridad_atencion, t.tipo_ticket) AS prioridad_atencion,
                    t.numero, t.codigo, t.ventanilla_numero, t.asignado_por,
                    t.tipo_documento, t.numero_documento, t.estado, t.cola_estado,
                    t.estado_impresion, t.creado_en, e.nombre AS especialidad
             FROM st_tickets t
             INNER JOIN core_especialidades e ON e.id = t.especialidad_id
             WHERE t.fecha = :fecha
               AND t.ventanilla_numero BETWEEN 1 AND 9
               AND t.cola_estado IN (\'llamando\', \'atendiendo\')
             ORDER BY t.asignado_en DESC, t.id DESC'
        );
        $stmt->execute(['fecha' => $date]);

        $tickets = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $window = (int) ($row['ventanilla_numero'] ?? 0);
            if ($window >= 1 && $window <= 9 && !isset($tickets[$window])) {
                $tickets[$window] = $this->formatQueueTicket($row);
            }
        }

        return $tickets;
    }

    private function windowMode(int $window): string
    {
        if (in_array($window, [1, 2], true)) {
            return 'Preferencial';
        }

        if (in_array($window, [3, 4, 5, 6], true)) {
            return 'Normal proporcional';
        }

        return 'Manual';
    }

    private function windowQueueMode(int $window): string
    {
        if ($window < 1 || $window > 9) {
            return 'automatico';
        }

        try {
            $stmt = $this->db->prepare('SELECT valor FROM st_configuracion WHERE clave = :clave');
            $stmt->execute(['clave' => $this->windowQueueModeKey($window)]);
            $value = $stmt->fetchColumn();
        } catch (\Throwable) {
            $value = null;
        }

        return $this->normalizeWindowQueueMode(is_string($value) ? $value : 'automatico');
    }

    private function windowAutoCalls(int $window): bool
    {
        if ($window < 1 || $window > 6) {
            return false;
        }

        return $this->windowQueueMode($window) !== 'manual';
    }

    private function normalizeWindowQueueMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return in_array($normalized, self::WINDOW_QUEUE_MODES, true) ? $normalized : 'automatico';
    }

    private function windowQueueModeKey(int $window): string
    {
        return 'turnero_ventanilla_' . $window . '_modo';
    }

    private function windowQueueModeLabel(string $mode, int $window): string
    {
        return match ($this->normalizeWindowQueueMode($mode)) {
            'preferencial' => 'Solo preferencial',
            'normal' => 'Solo normal',
            'manual' => 'Manual',
            default => 'Automatico (' . $this->windowMode($window) . ')',
        };
    }

    private function activeUsersForWindowAssignment(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT u.id, u.dni, u.nombres, u.apellidos, u.email
             FROM core_usuarios u
             WHERE u.estado = 'activo'
               AND (
                    EXISTS (
                        SELECT 1
                        FROM core_usuario_politicas up
                        INNER JOIN core_politicas p ON p.id = up.politica_id
                        WHERE up.usuario_id = u.id
                          AND up.estado = 'activo'
                          AND up.permitido = 1
                          AND p.estado = 'activo'
                          AND p.efecto = 'Allow'
                          AND p.accion IN ('tickets.turnero.atender', 'sistema.admin', '*')
                          AND p.recurso IN ('sistema:tickets', 'sistema:*', '*')
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM core_grupo_usuarios gu
                        INNER JOIN core_grupos g ON g.id = gu.grupo_id
                        INNER JOIN core_grupo_politicas gp ON gp.grupo_id = g.id
                        INNER JOIN core_politicas p ON p.id = gp.politica_id
                        WHERE gu.usuario_id = u.id
                          AND gu.estado = 'activo'
                          AND g.estado = 'activo'
                          AND gp.permitido = 1
                          AND p.estado = 'activo'
                          AND p.efecto = 'Allow'
                          AND p.accion IN ('tickets.turnero.atender', 'sistema.admin', '*')
                          AND p.recurso IN ('sistema:tickets', 'sistema:*', '*')
                    )
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM core_usuario_politicas up
                    INNER JOIN core_politicas p ON p.id = up.politica_id
                    WHERE up.usuario_id = u.id
                      AND up.estado = 'activo'
                      AND up.permitido = 1
                      AND p.estado = 'activo'
                      AND p.efecto = 'Deny'
                      AND p.accion IN ('tickets.turnero.atender', 'sistema.admin', '*')
                      AND p.recurso IN ('sistema:tickets', 'sistema:*', '*')
               )
             ORDER BY u.nombres ASC, u.apellidos ASC"
        );

        return array_map(function (array $row): array {
            $email = trim((string) ($row['email'] ?? ''));
            $dni = trim((string) ($row['dni'] ?? ''));
            $name = trim((string) (($row['nombres'] ?? '') . ' ' . ($row['apellidos'] ?? ''))) ?: $email;
            $syntheticEmail = $this->isSyntheticDniEmail($email);

            return [
                'id' => (int) $row['id'],
                'nombre' => $name,
                'email' => $syntheticEmail ? '' : $email,
                'dni' => $dni,
                'login_label' => $syntheticEmail ? ($dni !== '' ? 'DNI ' . $dni : '') : $email,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function isSyntheticDniEmail(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@dni.local');
    }

    private function deactivateWindowAssignmentByUser(int $userId, ?int $adminId = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE st_usuario_ventanillas
             SET estado = \'liberado\',
                 liberado_por = :liberado_por,
                 liberado_en = ' . Sql::now($this->db) . '
             WHERE usuario_id = :usuario_id AND estado = \'activo\''
        );
        $stmt->execute([
            'usuario_id' => $userId,
            'liberado_por' => $adminId,
        ]);
    }

    private function deactivateWindowAssignmentByWindow(int $window, ?int $adminId = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE st_usuario_ventanillas
             SET estado = \'liberado\',
                 liberado_por = :liberado_por,
                 liberado_en = ' . Sql::now($this->db) . '
             WHERE ventanilla_numero = :ventanilla AND estado = \'activo\''
        );
        $stmt->execute([
            'ventanilla' => $window,
            'liberado_por' => $adminId,
        ]);
    }

    private function queueSummary(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT cola_estado, COUNT(*) AS total
             FROM st_tickets
             WHERE fecha = :fecha
               AND estado <> \'anulado\'
               AND estado_impresion = \'impreso\'
             GROUP BY cola_estado'
        );
        $stmt->execute(['fecha' => $date]);

        $summary = [
            'sin_cola' => 0,
            'en_cola' => 0,
            'llamando' => 0,
            'atendiendo' => 0,
            'atendido' => 0,
            'ausente' => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $state = (string) $row['cola_estado'];
            if (array_key_exists($state, $summary)) {
                $summary[$state] = (int) $row['total'];
            }
        }

        return $summary;
    }

    private function incrementReprints(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if (!$ids) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $stmt = $this->db->prepare(
            'UPDATE st_tickets
             SET reimpresiones = reimpresiones + 1
             WHERE id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
    }

    private function normalizeType(string $type): string
    {
        $type = trim($type);
        if (strcasecmp($type, 'Emergencia') === 0) {
            $type = 'Procedimiento';
        }

        foreach (self::TYPES as $validType) {
            if (strcasecmp($type, $validType) === 0) {
                return $validType;
            }
        }

        throw new RuntimeException('Tipo de ticket no valido.');
    }

    private function ensureTicketPriorityColumn(): void
    {
        try {
            if (Sql::isSqlServer($this->db)) {
                $this->db->exec(
                    "IF COL_LENGTH('dbo.st_tickets', 'prioridad_atencion') IS NULL
                     BEGIN
                        ALTER TABLE dbo.st_tickets ADD prioridad_atencion VARCHAR(20) NULL;
                     END"
                );
                $this->db->exec(
                    "UPDATE dbo.st_tickets
                     SET prioridad_atencion = CASE WHEN tipo_ticket IN ('Preferencial', 'Normal') THEN tipo_ticket ELSE NULL END
                     WHERE prioridad_atencion IS NULL"
                );

                return;
            }

            $exists = $this->db->query("SHOW COLUMNS FROM st_tickets LIKE 'prioridad_atencion'")->fetch();
            if (!$exists) {
                $this->db->exec('ALTER TABLE st_tickets ADD prioridad_atencion VARCHAR(20) NULL AFTER tipo_ticket');
                $this->db->exec(
                    "UPDATE st_tickets
                     SET prioridad_atencion = CASE WHEN tipo_ticket IN ('Preferencial', 'Normal') THEN tipo_ticket ELSE NULL END
                     WHERE prioridad_atencion IS NULL"
                );
            }
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo preparar la prioridad de atencion. Ejecuta la migracion de prioridad.');
        }
    }

    private function ensureTurneroColumns(): void
    {
        try {
            if (Sql::isSqlServer($this->db)) {
                $this->db->exec(
                    "IF COL_LENGTH('dbo.st_tickets', 'ventanilla_numero') IS NULL
                     BEGIN
                        ALTER TABLE dbo.st_tickets ADD ventanilla_numero INT NULL;
                     END"
                );
                $this->db->exec(
                    "IF COL_LENGTH('dbo.st_tickets', 'asignado_por') IS NULL
                     BEGIN
                        ALTER TABLE dbo.st_tickets ADD asignado_por BIGINT NULL;
                     END"
                );
                $this->db->exec(
                    "IF COL_LENGTH('dbo.st_tickets', 'asignado_en') IS NULL
                     BEGIN
                        ALTER TABLE dbo.st_tickets ADD asignado_en DATETIME2(3) NULL;
                     END"
                );
                $this->db->exec(
                    "IF EXISTS (
                        SELECT 1
                        FROM sys.columns
                        WHERE object_id = OBJECT_ID('dbo.st_tickets')
                          AND name = 'asignado_en'
                          AND scale <> 3
                     )
                     BEGIN
                        IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_st_tickets_turnero_screen' AND object_id = OBJECT_ID('dbo.st_tickets'))
                        BEGIN
                            DROP INDEX IX_st_tickets_turnero_screen ON dbo.st_tickets;
                        END

                        ALTER TABLE dbo.st_tickets ALTER COLUMN asignado_en DATETIME2(3) NULL;
                     END"
                );
                $this->ensureTurneroIndexes();

                return;
            }

            $exists = $this->db->query("SHOW COLUMNS FROM st_tickets LIKE 'ventanilla_numero'")->fetch();
            if (!$exists) {
                $this->db->exec('ALTER TABLE st_tickets ADD ventanilla_numero INT NULL AFTER cola_estado');
            }

            $exists = $this->db->query("SHOW COLUMNS FROM st_tickets LIKE 'asignado_por'")->fetch();
            if (!$exists) {
                $this->db->exec('ALTER TABLE st_tickets ADD asignado_por BIGINT UNSIGNED NULL AFTER ventanilla_numero');
            }

            $exists = $this->db->query("SHOW COLUMNS FROM st_tickets LIKE 'asignado_en'")->fetch();
            if (!$exists) {
                $this->db->exec('ALTER TABLE st_tickets ADD asignado_en DATETIME(3) NULL AFTER asignado_por');
            }
            $this->ensureTurneroIndexes();
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo preparar las columnas del turnero.');
        }
    }

    private function ensureTurneroIndexes(): void
    {
        if (Sql::isSqlServer($this->db)) {
            $this->db->exec(
                "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_st_tickets_turnero_next' AND object_id = OBJECT_ID('dbo.st_tickets'))
                 BEGIN
                    CREATE INDEX IX_st_tickets_turnero_next
                    ON dbo.st_tickets (fecha, cola_estado, estado, prioridad_atencion, creado_en, id)
                    INCLUDE (especialidad_id);
                 END"
            );
            $this->db->exec(
                "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_st_tickets_turnero_screen' AND object_id = OBJECT_ID('dbo.st_tickets'))
                 BEGIN
                    CREATE INDEX IX_st_tickets_turnero_screen
                    ON dbo.st_tickets (fecha, cola_estado, asignado_en, id)
                    INCLUDE (ventanilla_numero, prioridad_atencion);
                 END"
            );

            return;
        }

        $nextIndex = $this->db->query("SHOW INDEX FROM st_tickets WHERE Key_name = 'idx_st_tickets_turnero_next'")->fetch();
        if (!$nextIndex) {
            $this->db->exec(
                'CREATE INDEX idx_st_tickets_turnero_next
                 ON st_tickets (fecha, cola_estado, estado, prioridad_atencion, creado_en, id, especialidad_id)'
            );
        }

        $screenIndex = $this->db->query("SHOW INDEX FROM st_tickets WHERE Key_name = 'idx_st_tickets_turnero_screen'")->fetch();
        if (!$screenIndex) {
            $this->db->exec(
                'CREATE INDEX idx_st_tickets_turnero_screen
                 ON st_tickets (fecha, cola_estado, asignado_en, id, ventanilla_numero, prioridad_atencion)'
            );
        }
    }

    private function ensureScreenStateTable(): void
    {
        try {
            if (Sql::isSqlServer($this->db)) {
                $this->db->exec(
                    "IF OBJECT_ID('dbo.st_turnero_pantalla_estado', 'U') IS NULL
                     BEGIN
                        CREATE TABLE dbo.st_turnero_pantalla_estado (
                            fecha DATE NOT NULL CONSTRAINT PK_st_turnero_pantalla_estado PRIMARY KEY,
                            estado_json NVARCHAR(MAX) NOT NULL,
                            etag VARCHAR(80) NOT NULL,
                            version BIGINT NOT NULL,
                            actualizado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_turnero_pantalla_estado_actualizado DEFAULT (SYSDATETIME())
                        );
                     END"
                );

                return;
            }

            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS st_turnero_pantalla_estado (
                    fecha DATE NOT NULL PRIMARY KEY,
                    estado_json LONGTEXT NOT NULL,
                    etag VARCHAR(80) NOT NULL,
                    version BIGINT NOT NULL,
                    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )'
            );
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo preparar el estado precalculado de pantalla.');
        }
    }

    private function screenState(string $date): array
    {
        $row = $this->screenStateRow($date);
        if ($row === null) {
            $this->refreshScreenState($date);
            $row = $this->screenStateRow($date);
        }

        if ($row === null) {
            $payload = $this->screenSnapshotFromTickets($date);

            return [
                'payload' => $payload,
                'etag' => $this->screenEtag($payload),
            ];
        }

        $payload = json_decode((string) $row['estado_json'], true);
        if (!is_array($payload)) {
            $payload = $this->screenSnapshotFromTickets($date);
        }
        $payload = $this->sanitizeScreenPayload($payload);

        return [
            'payload' => $payload,
            'etag' => (string) $row['etag'],
        ];
    }

    private function screenStateRow(string $date): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT estado_json, etag, version, actualizado_en
             FROM st_turnero_pantalla_estado
             WHERE fecha = :fecha'
        );
        $stmt->execute(['fecha' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function refreshScreenState(string $date): void
    {
        $this->ensureTicketPriorityColumn();
        $this->ensureTurneroColumns();
        $this->ensureScreenStateTable();

        $payload = $this->screenSnapshotFromTickets($date);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo preparar el estado de pantalla.');
        }

        $etag = $this->screenEtag($payload);
        $version = (int) floor(microtime(true) * 1000);

        if (Sql::isSqlServer($this->db)) {
            $stmt = $this->db->prepare(
                'DECLARE @fecha DATE = :fecha;
                 DECLARE @estado_json NVARCHAR(MAX) = :estado_json;
                 DECLARE @etag VARCHAR(80) = :etag;
                 DECLARE @version BIGINT = :version;

                 UPDATE dbo.st_turnero_pantalla_estado WITH (UPDLOCK, HOLDLOCK)
                 SET estado_json = @estado_json,
                     etag = @etag,
                     version = @version,
                     actualizado_en = SYSDATETIME()
                 WHERE fecha = @fecha;

                 IF @@ROWCOUNT = 0
                 BEGIN
                    INSERT INTO dbo.st_turnero_pantalla_estado (fecha, estado_json, etag, version, actualizado_en)
                    VALUES (@fecha, @estado_json, @etag, @version, SYSDATETIME());
                 END'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO st_turnero_pantalla_estado (fecha, estado_json, etag, version, actualizado_en)
                 VALUES (:fecha, :estado_json, :etag, :version, NOW())
                 ON DUPLICATE KEY UPDATE
                    estado_json = VALUES(estado_json),
                    etag = VALUES(etag),
                    version = VALUES(version),
                    actualizado_en = NOW()'
            );
        }

        $stmt->execute([
            'fecha' => $date,
            'estado_json' => $json,
            'etag' => $etag,
            'version' => $version,
        ]);
    }

    private function screenEtag(array $payload): string
    {
        return '"' . sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') . '"';
    }

    private function ensureWindowAssignmentsTable(): void
    {
        try {
            if (Sql::isSqlServer($this->db)) {
                $this->db->exec(
                    "IF OBJECT_ID('dbo.st_usuario_ventanillas', 'U') IS NULL
                     BEGIN
                        CREATE TABLE dbo.st_usuario_ventanillas (
                            usuario_id BIGINT NOT NULL CONSTRAINT PK_st_usuario_ventanillas PRIMARY KEY,
                            ventanilla_numero INT NOT NULL,
                            estado VARCHAR(20) NOT NULL CONSTRAINT DF_st_usuario_ventanillas_estado DEFAULT ('activo'),
                            asignado_por BIGINT NULL,
                            asignado_en DATETIME2(0) NOT NULL CONSTRAINT DF_st_usuario_ventanillas_asignado DEFAULT (SYSDATETIME()),
                            liberado_por BIGINT NULL,
                            liberado_en DATETIME2(0) NULL,
                            CONSTRAINT CK_st_usuario_ventanillas_numero CHECK (ventanilla_numero BETWEEN 1 AND 9),
                            CONSTRAINT CK_st_usuario_ventanillas_estado CHECK (estado IN ('activo', 'liberado', 'inactivo'))
                        );
                        CREATE INDEX IX_st_usuario_ventanillas_numero ON dbo.st_usuario_ventanillas(ventanilla_numero, estado);
                     END"
                );

                return;
            }

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS st_usuario_ventanillas (
                    usuario_id BIGINT UNSIGNED NOT NULL,
                    ventanilla_numero INT NOT NULL,
                    estado VARCHAR(20) NOT NULL DEFAULT 'activo',
                    asignado_por BIGINT UNSIGNED NULL,
                    asignado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    liberado_por BIGINT UNSIGNED NULL,
                    liberado_en DATETIME NULL,
                    PRIMARY KEY (usuario_id),
                    KEY idx_st_usuario_ventanillas_numero (ventanilla_numero, estado),
                    CONSTRAINT fk_st_usuario_ventanillas_usuario
                        FOREIGN KEY (usuario_id) REFERENCES core_usuarios(id)
                        ON UPDATE CASCADE
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable) {
            throw new RuntimeException('No se pudo preparar la asignacion de ventanillas.');
        }
    }

    private function displayTicketType(string $type): string
    {
        return strcasecmp($type, 'Emergencia') === 0 ? 'Procedimiento' : $type;
    }

    private function validateDocument(string $type, string $number): void
    {
        if (!in_array($type, ['DNI', 'CE', 'PASAPORTE'], true)) {
            throw new RuntimeException('Tipo de documento no valido.');
        }

        if ($this->isTestDocument($type, $number)) {
            return;
        }

        if ($type === 'DNI' && preg_match('/^\d{8}$/', $number) !== 1) {
            throw new RuntimeException('El DNI debe tener 8 digitos.');
        }

        if ($type === 'CE' && preg_match('/^[A-Z0-9]{6,12}$/', $number) !== 1) {
            throw new RuntimeException('El carne de extranjeria debe tener entre 6 y 12 caracteres.');
        }

        if ($type === 'PASAPORTE' && preg_match('/^[A-Z0-9]{6,15}$/', $number) !== 1) {
            throw new RuntimeException('El pasaporte debe tener entre 6 y 15 caracteres.');
        }
    }

    private function isTestDocument(string $type, string $number): bool
    {
        return $type === 'DNI' && in_array($number, ['72402757'], true);
    }

    private function defaultPrintLayout(): array
    {
        return [
            'papel_mm' => 80,
            'contenido_mm' => 80,
            'padding_mm' => 5,
            'logo_mm' => 16,
            'header_px' => 15,
            'codigo_px' => 54,
            'documento_px' => 19,
            'especialidad_px' => 19,
            'fecha_px' => 31,
            'codigo_margen_mm' => 0,
            'linea_margen_mm' => 0,
            'fecha_margen_mm' => 0,
            'offset_x_mm' => 0,
            'offset_y_mm' => 0,
            'hospital_x_mm' => 0,
            'hospital_y_mm' => 0,
            'logo_left_x_mm' => 0,
            'logo_left_y_mm' => 0,
            'logo_right_x_mm' => 0,
            'logo_right_y_mm' => 0,
            'code_x_mm' => 0,
            'code_y_mm' => 0,
            'doc_x_mm' => 0,
            'doc_y_mm' => 0,
            'specialty_x_mm' => 0,
            'specialty_y_mm' => 0,
            'date_x_mm' => 0,
            'date_y_mm' => 0,
        ];
    }

    private function sanitizePrintLayout(array $input): array
    {
        $defaults = $this->defaultPrintLayout();
        $limits = [
            'papel_mm' => [50, 90],
            'contenido_mm' => [40, 86],
            'padding_mm' => [0, 12],
            'logo_mm' => [8, 22],
            'header_px' => [8, 22],
            'codigo_px' => [28, 72],
            'documento_px' => [10, 30],
            'especialidad_px' => [10, 32],
            'fecha_px' => [16, 42],
            'codigo_margen_mm' => [0, 18],
            'linea_margen_mm' => [0, 14],
            'fecha_margen_mm' => [0, 18],
            'offset_x_mm' => [-10, 10],
            'offset_y_mm' => [-15, 8],
            'hospital_x_mm' => [-20, 20],
            'hospital_y_mm' => [-20, 20],
            'logo_left_x_mm' => [-20, 20],
            'logo_left_y_mm' => [-20, 20],
            'logo_right_x_mm' => [-20, 20],
            'logo_right_y_mm' => [-20, 20],
            'code_x_mm' => [-20, 20],
            'code_y_mm' => [-20, 20],
            'doc_x_mm' => [-20, 20],
            'doc_y_mm' => [-20, 20],
            'specialty_x_mm' => [-20, 20],
            'specialty_y_mm' => [-20, 20],
            'date_x_mm' => [-20, 20],
            'date_y_mm' => [-20, 20],
        ];

        $layout = [];
        foreach ($defaults as $key => $default) {
            $value = isset($input[$key]) ? (float) $input[$key] : (float) $default;
            [$min, $max] = $limits[$key];
            $layout[$key] = max($min, min($max, $value));
        }

        $layout['contenido_mm'] = min($layout['contenido_mm'], $layout['papel_mm']);

        return $layout;
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException('Fecha no valida.');
        }

        return $date;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

