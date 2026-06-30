<?php

declare(strict_types=1);

namespace InventarioNeo\Models;

use PDO;

final class Inventory
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function summary(): array
    {
        $personalWhere = $this->columnExists('dbo.core_personal', 'estado') ? "estado = 'activo'" : '';

        return [
            'personal' => $this->countRows('dbo.core_personal', $personalWhere),
            'equipos' => $this->countRows('dbo.core_equipos'),
            'equipos_asignados' => $this->countRows('dbo.core_equipos', 'usuario_actual_id IS NOT NULL'),
            'impresoras' => $this->countRows('dbo.core_impresoras'),
        ];
    }

    public function listPersonal(string $search = '', int $page = 1, int $perPage = 20): array
    {
        if (!$this->tableExists('dbo.core_personal')) {
            return $this->paginated([], $page, $perPage, 0);
        }

        $page = max(1, $page);
        $perPage = max(10, min(80, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [];
        $searchColumns = [];

        $columnExpr = function (string $column, string $fallback = 'NULL'): string {
            return $this->columnExists('dbo.core_personal', $column)
                ? "CONVERT(NVARCHAR(400), [{$column}])"
                : $fallback;
        };

        $nameParts = [];
        foreach (['nombre_completo', 'apellido_paterno', 'apellido_materno', 'nombres', 'nombre'] as $column) {
            if ($this->columnExists('dbo.core_personal', $column)) {
                $nameParts[] = "NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(240), [{$column}]))), '')";
            }
        }

        $nombreCompleto = $nameParts !== []
            ? 'COALESCE(' . implode(', ', $nameParts) . ', CAST(id AS NVARCHAR(20)))'
            : 'CAST(id AS NVARCHAR(20))';

        if ($search !== '') {
            foreach (['dni', 'nombre_completo', 'apellido_paterno', 'apellido_materno', 'nombres', 'nombre', 'correo_institucional', 'correo_firma'] as $column) {
                if ($this->columnExists('dbo.core_personal', $column)) {
                    $searchColumns[] = "CONVERT(NVARCHAR(400), [{$column}]) LIKE ?";
                }
            }

            if ($searchColumns === []) {
                $searchColumns[] = 'CAST(id AS NVARCHAR(20)) LIKE ?';
            }

            $params = array_fill(0, count($searchColumns), '%' . $search . '%');
        }

        $where = $searchColumns !== [] ? 'WHERE ' . implode(' OR ', $searchColumns) : '';
        $total = $this->countQuery("SELECT COUNT(*) FROM dbo.core_personal {$where}", $params);
        $stmt = $this->db->prepare(
            "SELECT id,
                    {$columnExpr('dni', 'CAST(id AS NVARCHAR(20))')} AS dni,
                    {$columnExpr('nombres')} AS nombres,
                    {$columnExpr('apellido_paterno')} AS apellido_paterno,
                    {$columnExpr('apellido_materno')} AS apellido_materno,
                    {$nombreCompleto} AS nombre_completo,
                    {$columnExpr('correo_institucional')} AS correo_institucional,
                    {$columnExpr('correo_firma')} AS correo_firma,
                    {$columnExpr('estado', "'activo'")} AS estado,
                    {$columnExpr('creado_en')} AS creado_en,
                    {$columnExpr('actualizado_en')} AS actualizado_en
             FROM dbo.core_personal
             {$where}
             ORDER BY nombre_completo
             OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY"
        );
        $stmt->execute($params);

        return $this->paginated($stmt->fetchAll(), $page, $perPage, $total);
    }

    public function savePersonal(array $data): int
    {
        $this->requireTable('dbo.core_personal');

        $id = (int) ($data['id'] ?? 0);
        $dni = trim((string) ($data['dni'] ?? ''));
        $nombres = trim((string) ($data['nombres'] ?? ''));
        $apellidoPaterno = trim((string) ($data['apellido_paterno'] ?? ''));
        $apellidoMaterno = trim((string) ($data['apellido_materno'] ?? ''));
        $correoInstitucional = trim((string) ($data['correo_institucional'] ?? ''));
        $correoFirma = trim((string) ($data['correo_firma'] ?? ''));
        $estado = strtolower(trim((string) ($data['estado'] ?? 'activo'))) === 'inactivo' ? 'inactivo' : 'activo';
        $nombreCompleto = trim((string) ($data['nombre_completo'] ?? ''));

        if ($dni === '' || $nombres === '') {
            throw new \InvalidArgumentException('DNI y nombres son obligatorios.');
        }

        if ($nombreCompleto === '') {
            $nombreCompleto = trim($apellidoPaterno . ' ' . $apellidoMaterno . ' ' . $nombres);
        }

        $params = [
            'dni' => $dni,
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno !== '' ? $apellidoPaterno : null,
            'apellido_materno' => $apellidoMaterno !== '' ? $apellidoMaterno : null,
            'nombre_completo' => $nombreCompleto,
            'correo_institucional' => $correoInstitucional !== '' ? $correoInstitucional : null,
            'correo_firma' => $correoFirma !== '' ? $correoFirma : null,
            'estado' => $estado,
        ];

        if ($id > 0) {
            $params['id'] = $id;
            $stmt = $this->db->prepare(
                'UPDATE dbo.core_personal
                 SET dni = :dni,
                     nombres = :nombres,
                     apellido_paterno = :apellido_paterno,
                     apellido_materno = :apellido_materno,
                     nombre_completo = :nombre_completo,
                     correo_institucional = :correo_institucional,
                     correo_firma = :correo_firma,
                     estado = :estado,
                     actualizado_en = SYSDATETIME()
                 WHERE id = :id'
            );
            $stmt->execute($params);
            return $id;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO dbo.core_personal
                (dni, nombres, apellido_paterno, apellido_materno, nombre_completo,
                 correo_institucional, correo_firma, estado, creado_en)
             OUTPUT INSERTED.id
             VALUES
                (:dni, :nombres, :apellido_paterno, :apellido_materno, :nombre_completo,
                 :correo_institucional, :correo_firma, :estado, SYSDATETIME())'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function listEquipos(string $search = '', int $page = 1, int $perPage = 20): array
    {
        if (!$this->tableExists('dbo.core_equipos')) {
            return $this->paginated([], $page, $perPage, 0);
        }

        $page = max(1, $page);
        $perPage = max(10, min(80, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [];
        $hasHardware = $this->tableExists('dbo.core_equipo_hardware');
        $hasSoftware = $this->tableExists('dbo.core_equipo_software');
        $hasPeripherals = $this->tableExists('dbo.core_equipo_perifericos');
        $hasSystems = $this->tableExists('dbo.core_sistemas_operativos');
        $hasAntivirus = $this->tableExists('dbo.core_antivirus');
        $showHardware = $hasHardware && $this->countRows('dbo.core_equipo_hardware') > 0;
        $showSoftware = $hasSoftware && $this->countRows('dbo.core_equipo_software') > 0;
        $showPeripherals = $hasPeripherals && $this->countRows('dbo.core_equipo_perifericos') > 0;
        $where = '';
        $searchFields = [
            'e.nombre_pc',
            'e.numero_ip',
            'e.cod_patrimonio',
            'p.nombre_completo',
            'u.nombre',
            'a.nombre',
            'o.nombre',
        ];

        if ($hasHardware) {
            array_push(
                $searchFields,
                'mcpu.nombre',
                'mocpu.nombre',
                'h.serie_cpu',
                'mmain.nombre',
                'momain.nombre',
                'h.procesador',
                'h.capacidad_disco',
                'h.tipo_disco',
                'mvideo.nombre',
                'movideo.nombre'
            );
        }

        if ($hasSoftware) {
            array_push($searchFields, 'sw.licencia_so', 'sw.software_oficina', 'sw.internet');
        }

        if ($hasSoftware && $hasSystems) {
            $searchFields[] = 'so.nombre';
        }

        if ($hasSoftware && $hasAntivirus) {
            $searchFields[] = 'av.nombre';
        }

        if ($hasPeripherals) {
            array_push(
                $searchFields,
                'mmonitor.nombre',
                'momonitor.nombre',
                'pe.serie_monitor',
                'pe.cod_monitor',
                'mteclado.nombre',
                'moteclado.nombre',
                'pe.cod_teclado',
                'mmouse.nombre',
                'momouse.nombre',
                'mestabilizador.nombre',
                'moestabilizador.nombre',
                'pe.serie_estabilizador',
                'pe.cod_estabilizador',
                'mgrabadora.nombre',
                'pe.tipo_grabadora_dvd',
                'pe.velocidad_grabadora'
            );
        }

        $joins = [
            'LEFT JOIN dbo.core_personal p ON p.id = e.usuario_actual_id',
            'LEFT JOIN dbo.core_unidades u ON u.id = e.unidad_id',
            'LEFT JOIN dbo.core_areas a ON a.id = e.area_id',
            'LEFT JOIN dbo.core_oficinas o ON o.id = e.oficina_id',
            'LEFT JOIN dbo.core_estados_activo ea ON ea.id = e.estado_id',
        ];

        if ($hasHardware) {
            array_push(
                $joins,
                'LEFT JOIN dbo.core_equipo_hardware h ON h.equipo_id = e.id',
                'LEFT JOIN dbo.core_marcas mcpu ON mcpu.id = h.marca_cpu_id',
                'LEFT JOIN dbo.core_modelos mocpu ON mocpu.id = h.modelo_cpu_id',
                'LEFT JOIN dbo.core_estados_activo ecpu ON ecpu.id = h.estado_cpu_id',
                'LEFT JOIN dbo.core_marcas mmain ON mmain.id = h.marca_mainboard_id',
                'LEFT JOIN dbo.core_modelos momain ON momain.id = h.modelo_mainboard_id',
                'LEFT JOIN dbo.core_marcas mproc ON mproc.id = h.marca_procesador_id',
                'LEFT JOIN dbo.core_marcas mdisco ON mdisco.id = h.marca_disco_id',
                'LEFT JOIN dbo.core_modelos modisco ON modisco.id = h.modelo_disco_id',
                'LEFT JOIN dbo.core_marcas mvideo ON mvideo.id = h.marca_video_id',
                'LEFT JOIN dbo.core_modelos movideo ON movideo.id = h.modelo_video_id'
            );
        }

        if ($hasSoftware) {
            $joins[] = 'LEFT JOIN dbo.core_equipo_software sw ON sw.equipo_id = e.id';
            if ($hasSystems) {
                $joins[] = 'LEFT JOIN dbo.core_sistemas_operativos so ON so.id = sw.sistema_operativo_id';
            }
            if ($hasAntivirus) {
                $joins[] = 'LEFT JOIN dbo.core_antivirus av ON av.id = sw.antivirus_id';
            }
        }

        if ($hasPeripherals) {
            array_push(
                $joins,
                'LEFT JOIN dbo.core_equipo_perifericos pe ON pe.equipo_id = e.id',
                'LEFT JOIN dbo.core_marcas mmonitor ON mmonitor.id = pe.marca_monitor_id',
                'LEFT JOIN dbo.core_modelos momonitor ON momonitor.id = pe.modelo_monitor_id',
                'LEFT JOIN dbo.core_marcas mteclado ON mteclado.id = pe.marca_teclado_id',
                'LEFT JOIN dbo.core_modelos moteclado ON moteclado.id = pe.modelo_teclado_id',
                'LEFT JOIN dbo.core_marcas mmouse ON mmouse.id = pe.marca_mouse_id',
                'LEFT JOIN dbo.core_modelos momouse ON momouse.id = pe.modelo_mouse_id',
                'LEFT JOIN dbo.core_estados_activo eteclado ON eteclado.id = pe.estado_teclado_id',
                'LEFT JOIN dbo.core_estados_activo emouse ON emouse.id = pe.estado_mouse_id',
                'LEFT JOIN dbo.core_estados_activo emonitor ON emonitor.id = pe.estado_monitor_id',
                'LEFT JOIN dbo.core_marcas mestabilizador ON mestabilizador.id = pe.marca_estabilizador_id',
                'LEFT JOIN dbo.core_modelos moestabilizador ON moestabilizador.id = pe.modelo_estabilizador_id',
                'LEFT JOIN dbo.core_estados_activo eestabilizador ON eestabilizador.id = pe.estado_estabilizador_id',
                'LEFT JOIN dbo.core_marcas mgrabadora ON mgrabadora.id = pe.marca_grabadora_dvd_id'
            );
        }

        $joinSql = implode("\n             ", $joins);

        if ($search !== '') {
            $where = 'WHERE ' . implode(' OR ', array_map(static fn (string $field): string => "{$field} LIKE :search", $searchFields));
            $params['search'] = '%' . $search . '%';
        }

        $total = $this->countQuery(
            "SELECT COUNT(*)
             FROM dbo.core_equipos e
             {$joinSql}
             {$where}",
            $params
        );

        $hardwareSelect = $hasHardware
            ? "h.marca_cpu_id, mcpu.nombre AS marca_cpu, h.modelo_cpu_id, mocpu.nombre AS modelo_cpu, h.serie_cpu,
                    h.estado_cpu_id, ecpu.nombre AS estado_cpu,
                    h.marca_mainboard_id, mmain.nombre AS marca_mainboard,
                    h.modelo_mainboard_id, momain.nombre AS modelo_mainboard,
                    h.marca_procesador_id, mproc.nombre AS marca_procesador, h.procesador, h.velocidad_procesador,
                    h.generacion_procesador, h.tipo_memoria, h.total_memoria_gb,
                    h.marca_disco_id, mdisco.nombre AS marca_disco, h.capacidad_disco,
                    h.modelo_disco_id, modisco.nombre AS modelo_disco, h.tipo_disco,
                    h.marca_video_id, mvideo.nombre AS marca_video,
                    h.modelo_video_id, movideo.nombre AS modelo_video"
            : "NULL AS marca_cpu_id, NULL AS marca_cpu, NULL AS modelo_cpu_id, NULL AS modelo_cpu, NULL AS serie_cpu,
                    NULL AS estado_cpu_id, NULL AS estado_cpu,
                    NULL AS marca_mainboard_id, NULL AS marca_mainboard,
                    NULL AS modelo_mainboard_id, NULL AS modelo_mainboard,
                    NULL AS marca_procesador_id, NULL AS marca_procesador, NULL AS procesador, NULL AS velocidad_procesador,
                    NULL AS generacion_procesador, NULL AS tipo_memoria, NULL AS total_memoria_gb,
                    NULL AS marca_disco_id, NULL AS marca_disco, NULL AS capacidad_disco,
                    NULL AS modelo_disco_id, NULL AS modelo_disco, NULL AS tipo_disco,
                    NULL AS marca_video_id, NULL AS marca_video,
                    NULL AS modelo_video_id, NULL AS modelo_video";
        $softwareSelect = $hasSoftware
            ? "sw.sistema_operativo_id, " . ($hasSystems ? "so.nombre" : "NULL") . " AS sistema_operativo,
                    sw.licencia_so, sw.key_so, sw.software_oficina, sw.key_software_oficina,
                    sw.internet, sw.antivirus_id, " . ($hasAntivirus ? "av.nombre" : "NULL") . " AS antivirus"
            : "NULL AS sistema_operativo_id, NULL AS sistema_operativo,
                    NULL AS licencia_so, NULL AS key_so, NULL AS software_oficina, NULL AS key_software_oficina,
                    NULL AS internet, NULL AS antivirus_id, NULL AS antivirus";
        $peripheralSelect = $hasPeripherals
            ? "pe.marca_monitor_id, mmonitor.nombre AS marca_monitor, pe.tipo_monitor,
                    pe.modelo_monitor_id, momonitor.nombre AS modelo_monitor,
                    pe.monitor_pulgadas, pe.serie_monitor, pe.cod_monitor,
                    pe.estado_monitor_id, emonitor.nombre AS estado_monitor,
                    pe.marca_teclado_id, mteclado.nombre AS marca_teclado,
                    pe.modelo_teclado_id, moteclado.nombre AS modelo_teclado, pe.serie_teclado, pe.cod_teclado,
                    pe.estado_teclado_id, eteclado.nombre AS estado_teclado,
                    pe.marca_mouse_id, mmouse.nombre AS marca_mouse,
                    pe.modelo_mouse_id, momouse.nombre AS modelo_mouse, pe.serie_mouse,
                    pe.estado_mouse_id, emouse.nombre AS estado_mouse,
                    pe.marca_estabilizador_id, mestabilizador.nombre AS marca_estabilizador,
                    pe.modelo_estabilizador_id, moestabilizador.nombre AS modelo_estabilizador,
                    pe.serie_estabilizador, pe.cod_estabilizador,
                    pe.estado_estabilizador_id, eestabilizador.nombre AS estado_estabilizador,
                    pe.marca_grabadora_dvd_id, mgrabadora.nombre AS marca_grabadora_dvd,
                    pe.tipo_grabadora_dvd, pe.velocidad_grabadora"
            : "NULL AS marca_monitor_id, NULL AS marca_monitor, NULL AS tipo_monitor,
                    NULL AS modelo_monitor_id, NULL AS modelo_monitor,
                    NULL AS monitor_pulgadas, NULL AS serie_monitor, NULL AS cod_monitor,
                    NULL AS estado_monitor_id, NULL AS estado_monitor,
                    NULL AS marca_teclado_id, NULL AS marca_teclado,
                    NULL AS modelo_teclado_id, NULL AS modelo_teclado, NULL AS serie_teclado, NULL AS cod_teclado,
                    NULL AS estado_teclado_id, NULL AS estado_teclado,
                    NULL AS marca_mouse_id, NULL AS marca_mouse,
                    NULL AS modelo_mouse_id, NULL AS modelo_mouse, NULL AS serie_mouse,
                    NULL AS estado_mouse_id, NULL AS estado_mouse,
                    NULL AS marca_estabilizador_id, NULL AS marca_estabilizador,
                    NULL AS modelo_estabilizador_id, NULL AS modelo_estabilizador,
                    NULL AS serie_estabilizador, NULL AS cod_estabilizador,
                    NULL AS estado_estabilizador_id, NULL AS estado_estabilizador,
                    NULL AS marca_grabadora_dvd_id, NULL AS marca_grabadora_dvd,
                    NULL AS tipo_grabadora_dvd, NULL AS velocidad_grabadora";

        $stmt = $this->db->prepare(
            "SELECT e.id, e.nombre_pc, e.numero_ip, e.mac_address, e.cod_patrimonio,
                    e.unidad_id, u.nombre AS unidad,
                    e.area_id, a.nombre AS area,
                    e.oficina_id, o.nombre AS oficina,
                    e.usuario_actual_id, p.nombre_completo AS usuario_actual,
                    e.fecha_adquisicion, e.fecha_registro, e.estado_id, ea.nombre AS estado,
                    e.observacion,
                    {$hardwareSelect},
                    {$softwareSelect},
                    {$peripheralSelect},
                    e.creado_en, e.actualizado_en
             FROM dbo.core_equipos e
             {$joinSql}
             {$where}
             ORDER BY e.nombre_pc
             OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY"
        );
        $stmt->execute($params);

        return $this->paginated($stmt->fetchAll(), $page, $perPage, $total, [
            'detail' => [
                'hardware' => $showHardware,
                'software' => $showSoftware,
                'peripherals' => $showPeripherals,
                'missing_tables' => array_values(array_filter([
                    !$hasHardware ? 'core_equipo_hardware' : null,
                    !$hasSoftware ? 'core_equipo_software' : null,
                    !$hasPeripherals ? 'core_equipo_perifericos' : null,
                    !$hasSystems ? 'core_sistemas_operativos' : null,
                    !$hasAntivirus ? 'core_antivirus' : null,
                ])),
            ],
        ]);
    }

    public function saveEquipo(array $data): int
    {
        $this->requireTable('dbo.core_equipos');

        $id = (int) ($data['id'] ?? 0);
        $nombrePc = trim((string) ($data['nombre_pc'] ?? ''));
        if ($nombrePc === '') {
            throw new \InvalidArgumentException('Nombre de equipo es obligatorio.');
        }

        $params = [
            'nombre_pc' => $nombrePc,
            'numero_ip' => $this->nullable($data['numero_ip'] ?? null),
            'mac_address' => $this->nullable($data['mac_address'] ?? null),
            'usuario_actual_id' => $this->nullableInt($data['usuario_actual_id'] ?? null),
            'cod_patrimonio' => $this->nullable($data['cod_patrimonio'] ?? null),
            'fecha_adquisicion' => $this->nullableDate($data['fecha_adquisicion'] ?? null),
            'fecha_registro' => $this->nullableDate($data['fecha_registro'] ?? null),
            'estado_id' => $this->nullableInt($data['estado_id'] ?? null),
            'unidad_id' => $this->nullableInt($data['unidad_id'] ?? null),
            'area_id' => $this->nullableInt($data['area_id'] ?? null),
            'oficina_id' => $this->nullableInt($data['oficina_id'] ?? null),
            'observacion' => $this->nullable($data['observacion'] ?? null),
        ];

        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $params['id'] = $id;
                $stmt = $this->db->prepare(
                    'UPDATE dbo.core_equipos
                     SET nombre_pc = :nombre_pc,
                         numero_ip = :numero_ip,
                         mac_address = :mac_address,
                         usuario_actual_id = :usuario_actual_id,
                         cod_patrimonio = :cod_patrimonio,
                         fecha_adquisicion = :fecha_adquisicion,
                         fecha_registro = :fecha_registro,
                         estado_id = :estado_id,
                         unidad_id = :unidad_id,
                         area_id = :area_id,
                         oficina_id = :oficina_id,
                         observacion = :observacion,
                         actualizado_en = SYSDATETIME()
                     WHERE id = :id'
                );
                $stmt->execute($params);
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO dbo.core_equipos
                        (nombre_pc, numero_ip, mac_address, unidad_id, area_id, oficina_id,
                         usuario_actual_id, cod_patrimonio, fecha_adquisicion, fecha_registro,
                         estado_id, observacion, creado_en)
                     OUTPUT INSERTED.id
                     VALUES
                        (:nombre_pc, :numero_ip, :mac_address, :unidad_id, :area_id, :oficina_id,
                         :usuario_actual_id, :cod_patrimonio, :fecha_adquisicion, :fecha_registro,
                         :estado_id, :observacion, SYSDATETIME())'
                );
                $stmt->execute($params);
                $id = (int) $stmt->fetchColumn();
            }

            $this->saveEquipoHardware($id, $data);
            $this->saveEquipoSoftware($id, $data);
            $this->saveEquipoPerifericos($id, $data);

            $this->db->commit();
            return $id;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function catalogs(): array
    {
        return [
            'estados' => $this->catalogRows('dbo.core_estados_activo'),
            'unidades' => $this->catalogRows('dbo.core_unidades'),
            'areas' => $this->catalogRows('dbo.core_areas'),
            'oficinas' => $this->catalogRows('dbo.core_oficinas'),
            'marcas' => $this->catalogRows('dbo.core_marcas'),
            'modelos' => $this->catalogRows('dbo.core_modelos', 'id, marca_id, nombre, tipo'),
            'tipos_equipo' => $this->catalogRows('dbo.core_tipos_equipo'),
            'tipos_impresora' => $this->catalogRows('dbo.core_tipos_impresora'),
            'sistemas_operativos' => $this->catalogRows('dbo.core_sistemas_operativos'),
            'antivirus' => $this->catalogRows('dbo.core_antivirus'),
        ];
    }

    public function listImpresoras(string $search = '', int $page = 1, int $perPage = 20): array
    {
        if (!$this->tableExists('dbo.core_impresoras')) {
            return $this->paginated([], $page, $perPage, 0);
        }

        $page = max(1, $page);
        $perPage = max(10, min(80, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [];
        $where = '';

        if ($search !== '') {
            $where = 'WHERE i.ubicacion LIKE :search OR i.codpat LIKE :search OR i.cod_inv LIKE :search
                      OR i.serie LIKE :search OR i.observacion LIKE :search OR i.nota LIKE :search
                      OR u.nombre LIKE :search OR a.nombre LIKE :search OR m.nombre LIKE :search
                      OR mo.nombre LIKE :search OR te.nombre LIKE :search OR ti.nombre LIKE :search
                      OR ea.nombre LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $total = $this->countQuery(
            "SELECT COUNT(*)
             FROM dbo.core_impresoras i
             LEFT JOIN dbo.core_unidades u ON u.id = i.unidad_id
             LEFT JOIN dbo.core_areas a ON a.id = i.area_id
             LEFT JOIN dbo.core_tipos_equipo te ON te.id = i.tipo_equipo_id
             LEFT JOIN dbo.core_marcas m ON m.id = i.marca_id
             LEFT JOIN dbo.core_modelos mo ON mo.id = i.modelo_id
             LEFT JOIN dbo.core_tipos_impresora ti ON ti.id = i.tipo_impresora_id
             LEFT JOIN dbo.core_estados_activo ea ON ea.id = i.estado_id
             {$where}",
            $params
        );
        $stmt = $this->db->prepare(
            "SELECT i.id, i.ubicacion, i.codpat, i.cod_inv, i.serie,
                    i.unidad_id, u.nombre AS unidad,
                    i.area_id, a.nombre AS area,
                    i.tipo_equipo_id, te.nombre AS tipo_equipo,
                    i.marca_id, m.nombre AS marca,
                    i.modelo_id, mo.nombre AS modelo,
                    i.tipo_impresora_id, ti.nombre AS tipo_impresora,
                    i.estado_id, ea.nombre AS estado,
                    i.observacion, i.nota, i.creado_en, i.actualizado_en
             FROM dbo.core_impresoras i
             LEFT JOIN dbo.core_unidades u ON u.id = i.unidad_id
             LEFT JOIN dbo.core_areas a ON a.id = i.area_id
             LEFT JOIN dbo.core_tipos_equipo te ON te.id = i.tipo_equipo_id
             LEFT JOIN dbo.core_marcas m ON m.id = i.marca_id
             LEFT JOIN dbo.core_modelos mo ON mo.id = i.modelo_id
             LEFT JOIN dbo.core_tipos_impresora ti ON ti.id = i.tipo_impresora_id
             LEFT JOIN dbo.core_estados_activo ea ON ea.id = i.estado_id
             {$where}
             ORDER BY i.id DESC
             OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY"
        );
        $stmt->execute($params);

        return $this->paginated($stmt->fetchAll(), $page, $perPage, $total);
    }

    public function saveImpresora(array $data): int
    {
        $this->requireTable('dbo.core_impresoras');

        $id = (int) ($data['id'] ?? 0);
        $params = [
            'ubicacion' => $this->nullable($data['ubicacion'] ?? null),
            'unidad_id' => $this->nullableInt($data['unidad_id'] ?? null),
            'area_id' => $this->nullableInt($data['area_id'] ?? null),
            'codpat' => $this->nullable($data['codpat'] ?? null),
            'cod_inv' => $this->nullable($data['cod_inv'] ?? null),
            'tipo_equipo_id' => $this->nullableInt($data['tipo_equipo_id'] ?? null),
            'marca_id' => $this->nullableInt($data['marca_id'] ?? null),
            'modelo_id' => $this->nullableInt($data['modelo_id'] ?? null),
            'tipo_impresora_id' => $this->nullableInt($data['tipo_impresora_id'] ?? null),
            'serie' => $this->nullable($data['serie'] ?? null),
            'estado_id' => $this->nullableInt($data['estado_id'] ?? null),
            'observacion' => $this->nullable($data['observacion'] ?? null),
            'nota' => $this->nullable($data['nota'] ?? null),
        ];

        if (($params['codpat'] ?? '') === '' && ($params['serie'] ?? '') === '') {
            throw new \InvalidArgumentException('Codigo patrimonial o serie es obligatorio.');
        }

        if ($id > 0) {
            $params['id'] = $id;
            $stmt = $this->db->prepare(
                'UPDATE dbo.core_impresoras
                 SET ubicacion = :ubicacion,
                     unidad_id = :unidad_id,
                     area_id = :area_id,
                     codpat = :codpat,
                     cod_inv = :cod_inv,
                     tipo_equipo_id = :tipo_equipo_id,
                     marca_id = :marca_id,
                     modelo_id = :modelo_id,
                     tipo_impresora_id = :tipo_impresora_id,
                     serie = :serie,
                     estado_id = :estado_id,
                     observacion = :observacion,
                     nota = :nota,
                     actualizado_en = SYSDATETIME()
                 WHERE id = :id'
            );
            $stmt->execute($params);
            return $id;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO dbo.core_impresoras
                (ubicacion, unidad_id, area_id, codpat, cod_inv, tipo_equipo_id, marca_id,
                 modelo_id, tipo_impresora_id, serie, estado_id, observacion, nota, creado_en)
             OUTPUT INSERTED.id
             VALUES
                (:ubicacion, :unidad_id, :area_id, :codpat, :cod_inv, :tipo_equipo_id, :marca_id,
                 :modelo_id, :tipo_impresora_id, :serie, :estado_id, :observacion, :nota, SYSDATETIME())'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function searchPersonal(string $search, int $limit = 10): array
    {
        if (!$this->tableExists('dbo.core_personal')) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $nameParts = [];
        foreach (['nombre_completo', 'apellido_paterno', 'apellido_materno', 'nombres', 'nombre'] as $column) {
            if ($this->columnExists('dbo.core_personal', $column)) {
                $nameParts[] = "NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(240), [{$column}]))), '')";
            }
        }

        $nameSelect = $nameParts !== []
            ? 'COALESCE(' . implode(', ', $nameParts) . ', CAST(id AS NVARCHAR(20)))'
            : 'CAST(id AS NVARCHAR(20))';
        $dniSelect = $this->columnExists('dbo.core_personal', 'dni')
            ? "CONVERT(NVARCHAR(20), [dni])"
            : "CAST(id AS NVARCHAR(20))";
        $searchColumns = [];
        foreach (['dni', 'nombre_completo', 'apellido_paterno', 'apellido_materno', 'nombres', 'nombre', 'correo_institucional', 'correo_firma'] as $column) {
            if ($this->columnExists('dbo.core_personal', $column)) {
                $searchColumns[] = "CONVERT(NVARCHAR(400), [{$column}]) LIKE ?";
            }
        }

        if ($searchColumns === []) {
            $searchColumns[] = 'CAST(id AS NVARCHAR(20)) LIKE ?';
        }

        $where = implode(' OR ', $searchColumns);
        $estadoFilter = $this->columnExists('dbo.core_personal', 'estado')
            ? "AND (LOWER(CONVERT(NVARCHAR(20), [estado])) = 'activo' OR [estado] IS NULL)"
            : '';
        $stmt = $this->db->prepare(
            "SELECT TOP {$limit} id,
                    {$dniSelect} AS dni,
                    {$nameSelect} AS nombre_completo
             FROM dbo.core_personal
             WHERE ({$where})
               {$estadoFilter}
             ORDER BY nombre_completo"
        );
        $stmt->execute(array_fill(0, count($searchColumns), '%' . $search . '%'));

        return $stmt->fetchAll();
    }

    private function saveEquipoHardware(int $equipoId, array $data): void
    {
        if (!$this->tableExists('dbo.core_equipo_hardware')) {
            return;
        }

        $params = [
            'equipo_id' => $equipoId,
            'marca_cpu_id' => $this->nullableInt($data['marca_cpu_id'] ?? null),
            'modelo_cpu_id' => $this->nullableInt($data['modelo_cpu_id'] ?? null),
            'serie_cpu' => $this->nullable($data['serie_cpu'] ?? null),
            'estado_cpu_id' => $this->nullableInt($data['estado_cpu_id'] ?? null),
            'marca_mainboard_id' => $this->nullableInt($data['marca_mainboard_id'] ?? null),
            'modelo_mainboard_id' => $this->nullableInt($data['modelo_mainboard_id'] ?? null),
            'marca_procesador_id' => $this->nullableInt($data['marca_procesador_id'] ?? null),
            'procesador' => $this->nullable($data['procesador'] ?? null),
            'velocidad_procesador' => $this->nullable($data['velocidad_procesador'] ?? null),
            'generacion_procesador' => $this->nullable($data['generacion_procesador'] ?? null),
            'tipo_memoria' => $this->nullable($data['tipo_memoria'] ?? null),
            'total_memoria_gb' => $this->nullableDecimal($data['total_memoria_gb'] ?? null),
            'marca_disco_id' => $this->nullableInt($data['marca_disco_id'] ?? null),
            'capacidad_disco' => $this->nullable($data['capacidad_disco'] ?? null),
            'modelo_disco_id' => $this->nullableInt($data['modelo_disco_id'] ?? null),
            'tipo_disco' => $this->nullable($data['tipo_disco'] ?? null),
            'marca_video_id' => $this->nullableInt($data['marca_video_id'] ?? null),
            'modelo_video_id' => $this->nullableInt($data['modelo_video_id'] ?? null),
        ];

        $exists = $this->countQuery('SELECT COUNT(*) FROM dbo.core_equipo_hardware WHERE equipo_id = :equipo_id', ['equipo_id' => $equipoId]) > 0;
        $sql = $exists
            ? 'UPDATE dbo.core_equipo_hardware
               SET marca_cpu_id = :marca_cpu_id, modelo_cpu_id = :modelo_cpu_id, serie_cpu = :serie_cpu,
                   estado_cpu_id = :estado_cpu_id, marca_mainboard_id = :marca_mainboard_id,
                   modelo_mainboard_id = :modelo_mainboard_id, marca_procesador_id = :marca_procesador_id,
                   procesador = :procesador, velocidad_procesador = :velocidad_procesador,
                   generacion_procesador = :generacion_procesador, tipo_memoria = :tipo_memoria,
                   total_memoria_gb = :total_memoria_gb, marca_disco_id = :marca_disco_id,
                   capacidad_disco = :capacidad_disco, modelo_disco_id = :modelo_disco_id,
                   tipo_disco = :tipo_disco, marca_video_id = :marca_video_id,
                   modelo_video_id = :modelo_video_id, actualizado_en = SYSDATETIME()
               WHERE equipo_id = :equipo_id'
            : 'INSERT INTO dbo.core_equipo_hardware
                  (equipo_id, marca_cpu_id, modelo_cpu_id, serie_cpu, estado_cpu_id,
                   marca_mainboard_id, modelo_mainboard_id, marca_procesador_id, procesador,
                   velocidad_procesador, generacion_procesador, tipo_memoria, total_memoria_gb,
                   marca_disco_id, capacidad_disco, modelo_disco_id, tipo_disco,
                   marca_video_id, modelo_video_id, actualizado_en)
               VALUES
                  (:equipo_id, :marca_cpu_id, :modelo_cpu_id, :serie_cpu, :estado_cpu_id,
                   :marca_mainboard_id, :modelo_mainboard_id, :marca_procesador_id, :procesador,
                   :velocidad_procesador, :generacion_procesador, :tipo_memoria, :total_memoria_gb,
                   :marca_disco_id, :capacidad_disco, :modelo_disco_id, :tipo_disco,
                   :marca_video_id, :modelo_video_id, SYSDATETIME())';
        $this->db->prepare($sql)->execute($params);
    }

    private function saveEquipoSoftware(int $equipoId, array $data): void
    {
        if (!$this->tableExists('dbo.core_equipo_software')) {
            return;
        }

        $params = [
            'equipo_id' => $equipoId,
            'sistema_operativo_id' => $this->nullableInt($data['sistema_operativo_id'] ?? null),
            'licencia_so' => $this->nullable($data['licencia_so'] ?? null),
            'key_so' => $this->nullable($data['key_so'] ?? null),
            'software_oficina' => $this->nullable($data['software_oficina'] ?? null),
            'key_software_oficina' => $this->nullable($data['key_software_oficina'] ?? null),
            'internet' => $this->nullable($data['internet'] ?? null),
            'antivirus_id' => $this->nullableInt($data['antivirus_id'] ?? null),
        ];

        $exists = $this->countQuery('SELECT COUNT(*) FROM dbo.core_equipo_software WHERE equipo_id = :equipo_id', ['equipo_id' => $equipoId]) > 0;
        $sql = $exists
            ? 'UPDATE dbo.core_equipo_software
               SET sistema_operativo_id = :sistema_operativo_id, licencia_so = :licencia_so,
                   key_so = :key_so, software_oficina = :software_oficina,
                   key_software_oficina = :key_software_oficina, internet = :internet,
                   antivirus_id = :antivirus_id, actualizado_en = SYSDATETIME()
               WHERE equipo_id = :equipo_id'
            : 'INSERT INTO dbo.core_equipo_software
                  (equipo_id, sistema_operativo_id, licencia_so, key_so, software_oficina,
                   key_software_oficina, internet, antivirus_id, actualizado_en)
               VALUES
                  (:equipo_id, :sistema_operativo_id, :licencia_so, :key_so, :software_oficina,
                   :key_software_oficina, :internet, :antivirus_id, SYSDATETIME())';
        $this->db->prepare($sql)->execute($params);
    }

    private function saveEquipoPerifericos(int $equipoId, array $data): void
    {
        if (!$this->tableExists('dbo.core_equipo_perifericos')) {
            return;
        }

        $params = [
            'equipo_id' => $equipoId,
            'marca_teclado_id' => $this->nullableInt($data['marca_teclado_id'] ?? null),
            'modelo_teclado_id' => $this->nullableInt($data['modelo_teclado_id'] ?? null),
            'serie_teclado' => $this->nullable($data['serie_teclado'] ?? null),
            'cod_teclado' => $this->nullable($data['cod_teclado'] ?? null),
            'estado_teclado_id' => $this->nullableInt($data['estado_teclado_id'] ?? null),
            'marca_mouse_id' => $this->nullableInt($data['marca_mouse_id'] ?? null),
            'modelo_mouse_id' => $this->nullableInt($data['modelo_mouse_id'] ?? null),
            'serie_mouse' => $this->nullable($data['serie_mouse'] ?? null),
            'estado_mouse_id' => $this->nullableInt($data['estado_mouse_id'] ?? null),
            'marca_monitor_id' => $this->nullableInt($data['marca_monitor_id'] ?? null),
            'tipo_monitor' => $this->nullable($data['tipo_monitor'] ?? null),
            'modelo_monitor_id' => $this->nullableInt($data['modelo_monitor_id'] ?? null),
            'monitor_pulgadas' => $this->nullable($data['monitor_pulgadas'] ?? null),
            'serie_monitor' => $this->nullable($data['serie_monitor'] ?? null),
            'cod_monitor' => $this->nullable($data['cod_monitor'] ?? null),
            'estado_monitor_id' => $this->nullableInt($data['estado_monitor_id'] ?? null),
            'marca_estabilizador_id' => $this->nullableInt($data['marca_estabilizador_id'] ?? null),
            'modelo_estabilizador_id' => $this->nullableInt($data['modelo_estabilizador_id'] ?? null),
            'serie_estabilizador' => $this->nullable($data['serie_estabilizador'] ?? null),
            'cod_estabilizador' => $this->nullable($data['cod_estabilizador'] ?? null),
            'estado_estabilizador_id' => $this->nullableInt($data['estado_estabilizador_id'] ?? null),
            'marca_grabadora_dvd_id' => $this->nullableInt($data['marca_grabadora_dvd_id'] ?? null),
            'tipo_grabadora_dvd' => $this->nullable($data['tipo_grabadora_dvd'] ?? null),
            'velocidad_grabadora' => $this->nullable($data['velocidad_grabadora'] ?? null),
        ];

        $exists = $this->countQuery('SELECT COUNT(*) FROM dbo.core_equipo_perifericos WHERE equipo_id = :equipo_id', ['equipo_id' => $equipoId]) > 0;
        $sql = $exists
            ? 'UPDATE dbo.core_equipo_perifericos
               SET marca_teclado_id = :marca_teclado_id, modelo_teclado_id = :modelo_teclado_id,
                   serie_teclado = :serie_teclado, cod_teclado = :cod_teclado,
                   estado_teclado_id = :estado_teclado_id,
                   marca_mouse_id = :marca_mouse_id, modelo_mouse_id = :modelo_mouse_id,
                   serie_mouse = :serie_mouse, estado_mouse_id = :estado_mouse_id,
                   marca_monitor_id = :marca_monitor_id,
                   tipo_monitor = :tipo_monitor, modelo_monitor_id = :modelo_monitor_id,
                   monitor_pulgadas = :monitor_pulgadas, serie_monitor = :serie_monitor,
                   cod_monitor = :cod_monitor, estado_monitor_id = :estado_monitor_id,
                   marca_estabilizador_id = :marca_estabilizador_id,
                   modelo_estabilizador_id = :modelo_estabilizador_id,
                   serie_estabilizador = :serie_estabilizador,
                   cod_estabilizador = :cod_estabilizador,
                   estado_estabilizador_id = :estado_estabilizador_id,
                   marca_grabadora_dvd_id = :marca_grabadora_dvd_id,
                   tipo_grabadora_dvd = :tipo_grabadora_dvd,
                   velocidad_grabadora = :velocidad_grabadora,
                   actualizado_en = SYSDATETIME()
               WHERE equipo_id = :equipo_id'
            : 'INSERT INTO dbo.core_equipo_perifericos
                  (equipo_id, marca_teclado_id, modelo_teclado_id, serie_teclado, cod_teclado,
                   estado_teclado_id, marca_mouse_id, modelo_mouse_id, serie_mouse, estado_mouse_id,
                   marca_monitor_id, tipo_monitor, modelo_monitor_id, monitor_pulgadas,
                   serie_monitor, cod_monitor, estado_monitor_id, marca_estabilizador_id,
                   modelo_estabilizador_id, serie_estabilizador, cod_estabilizador,
                   estado_estabilizador_id, marca_grabadora_dvd_id, tipo_grabadora_dvd,
                   velocidad_grabadora, actualizado_en)
               VALUES
                  (:equipo_id, :marca_teclado_id, :modelo_teclado_id, :serie_teclado, :cod_teclado,
                   :estado_teclado_id, :marca_mouse_id, :modelo_mouse_id, :serie_mouse, :estado_mouse_id,
                   :marca_monitor_id, :tipo_monitor, :modelo_monitor_id, :monitor_pulgadas,
                   :serie_monitor, :cod_monitor, :estado_monitor_id, :marca_estabilizador_id,
                   :modelo_estabilizador_id, :serie_estabilizador, :cod_estabilizador,
                   :estado_estabilizador_id, :marca_grabadora_dvd_id, :tipo_grabadora_dvd,
                   :velocidad_grabadora, SYSDATETIME())';
        $this->db->prepare($sql)->execute($params);
    }

    private function catalogRows(string $table, string $columns = 'id, nombre'): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        return $this->db->query("SELECT {$columns} FROM {$table} ORDER BY nombre")->fetchAll();
    }

    private function countRows(string $table, string $where = ''): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM {$table}" . ($where !== '' ? " WHERE {$where}" : '');
        return (int) $this->db->query($sql)->fetchColumn();
    }

    private function requireTable(string $qualifiedTable): void
    {
        if (!$this->tableExists($qualifiedTable)) {
            throw new \InvalidArgumentException("Tabla {$qualifiedTable} no existe en esta base de datos.");
        }
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = $this->splitTableName($qualifiedTable);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $qualifiedTable, string $column): bool
    {
        [$schema, $table] = $this->splitTableName($qualifiedTable);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Accepts dbo.table names used by the SQL Server queries.
     *
     * @return array{0:string,1:string}
     */
    private function splitTableName(string $qualifiedTable): array
    {
        $parts = explode('.', $qualifiedTable, 2);
        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['dbo', $qualifiedTable];
    }

    private function countQuery(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function paginated(array $rows, int $page, int $perPage, int $total, array $meta = []): array
    {
        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
            'meta' => $meta,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        $value = str_replace(',', '.', trim((string) ($value ?? '')));
        return $value !== '' && is_numeric($value) ? $value : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
