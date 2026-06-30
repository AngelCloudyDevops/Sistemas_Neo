<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use TiNeo\Core\Database;
use TiNeo\Core\Response;
use TiNeo\Models\Specialty;
use TiNeo\Services\AuditService;
use TiNeo\Services\CitasDisponiblesService;
use TiNeo\Services\MonthlyAppointmentsService;
use TiNeo\Services\ScreenDeviceService;
use TiNeo\Services\TicketService;

$config = require BASE_PATH . '/config/config.php';
$db = Database::connection($config);
$devices = new ScreenDeviceService($db);
$tickets = new TicketService(
    $db,
    new Specialty($db),
    new AuditService($db),
    static function () use ($config, $db): CitasDisponiblesService {
        return new CitasDisponiblesService($db, Database::namedConnection($config, 'sigh'));
    }
);

$deviceCode = trim((string) ($_GET['device'] ?? ''));
$key = trim((string) ($_GET['key'] ?? ''));

if ($deviceCode === '' || $key === '') {
    Response::error('Pantalla no configurada.', 401);
}

try {
    $device = $devices->validatePublicDevice($deviceCode, $key);
    $device['mensaje'] = $devices->globalTicker();
    $format = (string) ($device['formato'] ?? 'turnos_clasico');
    if (in_array($format, ['citas_mes_doctor', 'citas_mes_doctor_limpio', 'citas_mes_doctor_aviso'], true)) {
        $screen = [
            'payload' => [
                'citas' => (new MonthlyAppointmentsService(Database::namedConnection($config, 'sigh')))->summary('mes'),
            ],
            'etag' => sha1($format . date('Y-m-d-H-i')),
        ];
    } else {
        $screen = $tickets->screen([
            'fecha' => (string) ($_GET['fecha'] ?? date('Y-m-d')),
        ]);
    }

    $isTurneroFormat = in_array($format, ['turnos_clasico', 'turnero', ''], true);

    Response::success([
        'device' => $device,
        'screen' => $screen['payload'] ?? [],
        'etag' => (string) ($screen['etag'] ?? ''),
        'polling' => [
            'normal_ms' => $isTurneroFormat ? 1500 : 5000,
            'idle_ms' => 12000,
            'error_ms' => 30000,
        ],
    ]);
} catch (\Throwable $exception) {
    Response::error($exception->getMessage(), 401);
}
