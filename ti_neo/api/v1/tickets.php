<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use TiNeo\Controllers\TicketController;
use TiNeo\Core\Auth;
use TiNeo\Core\Database;
use TiNeo\Core\Response;
use TiNeo\Models\Specialty;
use TiNeo\Services\AuditService;
use TiNeo\Services\CitasDisponiblesService;
use TiNeo\Services\JwtService;
use TiNeo\Services\ScreenDeviceService;
use TiNeo\Services\TicketService;

$config = require BASE_PATH . '/config/config.php';
$db = Database::connection($config);
$auth = new Auth(new JwtService($config), $db);
$specialties = new Specialty($db);
$controller = new TicketController(
    new TicketService(
        $db,
        $specialties,
        new AuditService($db),
        static function () use ($config, $db): CitasDisponiblesService {
            return new CitasDisponiblesService($db, Database::namedConnection($config, 'sigh'));
        }
    ),
    $specialties
);
$screenDevices = new ScreenDeviceService($db);

$action = (string) ($_GET['action'] ?? 'summary');

match ($action) {
    'permisos' => (function () use ($auth): void {
        if ($auth->isTicketsScreenOnlyUser()) {
            Response::success([
                'views' => [
                    'emision' => false,
                    'turnero' => false,
                    'pantalla' => true,
                    'rangos' => false,
                    'historial' => false,
                    'reportes' => false,
                    'perfil' => false,
                    'configuracion' => false,
                    'ventanillas' => false,
                    'pantallas' => false,
                    'impresion' => false,
                ],
                'actions' => [
                    'emitir' => false,
                    'emitir_normal' => false,
                    'emitir_solo_impresion' => false,
                    'reimprimir' => false,
                    'reimprimir_dia' => false,
                    'reimprimir_historial' => false,
                    'anular' => false,
                    'turnero' => false,
                    'rangos_admin' => false,
                    'lotes_admin' => false,
                    'configurar' => false,
                    'ventanillas_admin' => false,
                    'pantallas_admin' => false,
                    'impresion_admin' => false,
                ],
                'mode' => 'pantalla',
            ]);
        }

        $canConfigure = $auth->canPolicy('tickets.configurar', 'sistema:tickets');
        $canManageWindows = $canConfigure || $auth->canPolicy('tickets.ventanillas.admin', 'sistema:tickets');
        $canManageScreens = $canConfigure || $auth->canPolicy('tickets.pantalla.admin', 'sistema:tickets');
        $canManagePrint = $canConfigure || $auth->canPolicy('tickets.impresion.admin', 'sistema:tickets');
        $canEmitNormal = $auth->canPolicy('tickets.emitir', 'sistema:tickets');
        $canEmitPrintOnly = $auth->canPolicy('tickets.emitir.solo_impresion', 'sistema:tickets');

        Response::success([
            'views' => [
                'emision' => $auth->canPolicy('tickets.emision.ver', 'sistema:tickets')
                    || $canEmitNormal
                    || $canEmitPrintOnly,
                'turnero' => $auth->canPolicy('tickets.turnero.ver', 'sistema:tickets')
                    || $auth->canPolicy('tickets.turnero.atender', 'sistema:tickets'),
                'pantalla' => $auth->canPolicy('tickets.pantalla.ver', 'sistema:tickets'),
                'rangos' => $auth->canPolicy('tickets.rangos.ver', 'sistema:tickets')
                    || $auth->canPolicy('tickets.rangos.admin', 'sistema:tickets')
                    || $auth->canPolicy('tickets.lotes.ver', 'sistema:tickets')
                    || $auth->canPolicy('tickets.lotes.admin', 'sistema:tickets'),
                'historial' => $auth->canPolicy('tickets.historial.ver', 'sistema:tickets'),
                'reportes' => $auth->canPolicy('tickets.reportes.ver', 'sistema:tickets'),
                'perfil' => $auth->canPolicy('tickets.perfil.ver', 'sistema:tickets')
                    || $auth->canPolicy('sistema.entrar', 'sistema:tickets'),
                'configuracion' => $canConfigure || $canManageWindows || $canManageScreens || $canManagePrint,
                'ventanillas' => $auth->canPolicy('tickets.ventanillas.ver', 'sistema:tickets') || $canManageWindows,
                'pantallas' => $auth->canPolicy('tickets.pantallas.ver', 'sistema:tickets') || $canManageScreens,
                'impresion' => $auth->canPolicy('tickets.impresion.ver', 'sistema:tickets') || $canManagePrint,
            ],
            'actions' => [
                'emitir' => $canEmitNormal,
                'emitir_normal' => $canEmitNormal,
                'emitir_solo_impresion' => $canEmitPrintOnly,
                'reimprimir' => $auth->canPolicy('tickets.reimprimir', 'sistema:tickets')
                    || $auth->canPolicy('tickets.reimprimir.dia', 'sistema:tickets')
                    || $auth->canPolicy('tickets.reimprimir.historial', 'sistema:tickets'),
                'reimprimir_dia' => $auth->canPolicy('tickets.reimprimir.dia', 'sistema:tickets')
                    || $auth->canPolicy('tickets.reimprimir', 'sistema:tickets'),
                'reimprimir_historial' => $auth->canPolicy('tickets.reimprimir.historial', 'sistema:tickets')
                    || $auth->canPolicy('tickets.reimprimir', 'sistema:tickets'),
                'anular' => $auth->canPolicy('tickets.anular', 'sistema:tickets'),
                'turnero' => $auth->canPolicy('tickets.turnero.atender', 'sistema:tickets'),
                'rangos_admin' => $auth->canPolicy('tickets.rangos.admin', 'sistema:tickets'),
                'lotes_admin' => $auth->canPolicy('tickets.lotes.admin', 'sistema:tickets'),
                'configurar' => $canConfigure || $canManageWindows || $canManageScreens || $canManagePrint,
                'ventanillas_admin' => $canManageWindows,
                'pantallas_admin' => $canManageScreens,
                'impresion_admin' => $canManagePrint,
            ],
        ]);
    })(),
    'summary' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('sistema.entrar', 'sistema:tickets');
        $controller->summary();
    })(),
    'especialidades' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('sistema.entrar', 'sistema:tickets');
        $controller->specialties();
    })(),
    'citas-sigh' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('sistema.entrar', 'sistema:tickets');
        try {
            $config = require BASE_PATH . '/config/config.php';
            $service = new CitasDisponiblesService(
                Database::connection($config),
                Database::namedConnection($config, 'sigh')
            );

            Response::success($service->disponiblesHoy());
        } catch (\RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    })(),
    'emitir' => (function () use ($auth, $controller): void {
        $input = \TiNeo\Core\Request::json();
        $isPrintOnly = (string) ($input['modo_emision'] ?? '') === 'solo_impresion';
        $user = $auth->requirePolicy(
            $isPrintOnly ? 'tickets.emitir.solo_impresion' : 'tickets.emitir',
            'sistema:tickets'
        );
        $controller->emit($user, $input);
    })(),
    'guardar-rango' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.rangos.admin', 'sistema:tickets');
        $controller->saveRange($user);
    })(),
    'calendario-rangos' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.rangos.ver', 'sistema:tickets');
        $controller->rangeCalendar();
    })(),
    'generar-lote' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.lotes.admin', 'sistema:tickets');
        $controller->generateBatch($user);
    })(),
    'lotes-pendientes' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.lotes.admin', 'sistema:tickets');
        $controller->pendingBatches();
    })(),
    'lote-reimpresion' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.lotes.admin', 'sistema:tickets');
        $controller->batchForReprint($user);
    })(),
    'actualizar-avance-lote' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.lotes.admin', 'sistema:tickets');
        $controller->updateBatchProgress($user);
    })(),
    'marcar-lote' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.lotes.admin', 'sistema:tickets');
        $controller->markBatchPrinted($user);
    })(),
    'historial' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.historial.ver', 'sistema:tickets');
        $controller->history();
    })(),
    'reimpresion-dia-buscar' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.reimprimir.dia', 'sistema:tickets');
        $controller->dailyReprintSearch();
    })(),
    'reportes' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.reportes.ver', 'sistema:tickets');
        $controller->report();
    })(),
    'cola' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.turnero.atender', 'sistema:tickets');
        $controller->queue();
    })(),
    'encolar' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.turnero.atender', 'sistema:tickets');
        $controller->enqueue($user);
    })(),
    'llamar-siguiente' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.turnero.atender', 'sistema:tickets');
        $controller->callNext($user);
    })(),
    'actualizar-cola' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.turnero.atender', 'sistema:tickets');
        $controller->updateQueueState($user);
    })(),
    'rellamar' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.turnero.atender', 'sistema:tickets');
        $controller->recall($user);
    })(),
    'pantalla' => (function () use ($auth, $controller): void {
        $auth->requirePolicy('tickets.pantalla.ver', 'sistema:tickets');
        $controller->screen();
    })(),
    'mi-ventanilla' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.turnero.atender', 'sistema:tickets');
        $controller->myWindow($user);
    })(),
    'ventanillas' => (function () use ($auth, $controller): void {
        $auth->requireAnyPolicy(['tickets.ventanillas.ver', 'tickets.ventanillas.admin', 'tickets.configurar'], 'sistema:tickets');
        $controller->windowsAdmin();
    })(),
    'asignar-ventanilla' => (function () use ($auth, $controller): void {
        $user = $auth->requireAnyPolicy(['tickets.ventanillas.admin', 'tickets.configurar'], 'sistema:tickets');
        $controller->assignWindow($user);
    })(),
    'liberar-ventanilla' => (function () use ($auth, $controller): void {
        $user = $auth->requireAnyPolicy(['tickets.ventanillas.admin', 'tickets.configurar'], 'sistema:tickets');
        $controller->releaseWindow($user);
    })(),
    'configurar-modo-ventanilla' => (function () use ($auth, $controller): void {
        $user = $auth->requireAnyPolicy(['tickets.ventanillas.admin', 'tickets.configurar'], 'sistema:tickets');
        $controller->updateWindowQueueMode($user);
    })(),
    'pantallas-dispositivos' => (function () use ($auth, $screenDevices): void {
        $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        Response::success([
            'dispositivos' => $screenDevices->listDevices(),
            'ticker' => $screenDevices->globalTicker(),
        ]);
    })(),
    'guardar-ticker-pantallas' => (function () use ($auth, $screenDevices): void {
        $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        $input = \TiNeo\Core\Request::json();
        Response::success($screenDevices->saveGlobalTicker((string) ($input['ticker'] ?? '')));
    })(),
    'crear-pantalla-dispositivo' => (function () use ($auth, $screenDevices): void {
        $user = $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        Response::success($screenDevices->create(\TiNeo\Core\Request::json(), (int) $user['id']));
    })(),
    'regenerar-clave-pantalla' => (function () use ($auth, $screenDevices): void {
        $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        $input = \TiNeo\Core\Request::json();
        Response::success($screenDevices->regenerateKey((int) ($input['id'] ?? 0)));
    })(),
    'actualizar-formato-pantalla' => (function () use ($auth, $screenDevices): void {
        $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        $input = \TiNeo\Core\Request::json();
        Response::success($screenDevices->updateFormat(
            (int) ($input['id'] ?? 0),
            (string) ($input['formato'] ?? ''),
            (string) ($input['media_url'] ?? '')
        ));
    })(),
    'actualizar-pantalla-dispositivo' => (function () use ($auth, $screenDevices): void {
        $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        $input = \TiNeo\Core\Request::json();
        Response::success($screenDevices->updateDetails(
            (int) ($input['id'] ?? 0),
            (string) ($input['nombre'] ?? ''),
            (string) ($input['ubicacion'] ?? '')
        ));
    })(),
    'eliminar-pantalla-dispositivo' => (function () use ($auth, $screenDevices): void {
        $auth->requireAnyPolicy(['tickets.pantalla.admin', 'tickets.configurar'], 'sistema:tickets');
        $input = \TiNeo\Core\Request::json();
        $screenDevices->delete((int) ($input['id'] ?? 0));
        Response::success(['message' => 'Pantalla eliminada.']);
    })(),
    'reimprimir' => (function () use ($auth, $controller): void {
        if ($auth->canPolicy('tickets.reimprimir', 'sistema:tickets')) {
            $user = $auth->requirePolicy('tickets.reimprimir', 'sistema:tickets');
            $controller->reprint($user, 'historial');
            return;
        }

        if ($auth->canPolicy('tickets.reimprimir.historial', 'sistema:tickets')) {
            $user = $auth->requirePolicy('tickets.reimprimir.historial', 'sistema:tickets');
            $controller->reprint($user, 'historial');
            return;
        }

        $user = $auth->requirePolicy('tickets.reimprimir.dia', 'sistema:tickets');
        $controller->reprint($user, 'dia');
    })(),
    'anular' => (function () use ($auth, $controller): void {
        $user = $auth->requirePolicy('tickets.anular', 'sistema:tickets');
        $controller->cancel($user);
    })(),
    'confirmar-impresion' => (function () use ($auth, $controller): void {
        $input = \TiNeo\Core\Request::json();
        $isPrintOnly = (bool) ($input['solo_impresion'] ?? false);
        $user = $auth->requirePolicy(
            $isPrintOnly ? 'tickets.emitir.solo_impresion' : 'tickets.emitir',
            'sistema:tickets'
        );
        $controller->markPrinted($user, $input);
    })(),
    'cancelar-impresion' => (function () use ($auth, $controller): void {
        $input = \TiNeo\Core\Request::json();
        $isPrintOnly = (bool) ($input['solo_impresion'] ?? false);
        $user = $auth->requirePolicy(
            $isPrintOnly ? 'tickets.emitir.solo_impresion' : 'tickets.emitir',
            'sistema:tickets'
        );
        $controller->markPrintCanceled($user, $input);
    })(),
    'guardar-diseno-impresion' => (function () use ($auth, $controller): void {
        $user = $auth->requireAnyPolicy(['tickets.impresion.admin', 'tickets.configurar'], 'sistema:tickets');
        $controller->savePrintLayout($user);
    })(),
    default => Response::error('Accion no encontrada.', 404),
};
