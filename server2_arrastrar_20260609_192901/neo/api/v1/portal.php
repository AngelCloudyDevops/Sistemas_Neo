<?php

declare(strict_types=1);

use Neo\Controllers\PortalController;
use Neo\Core\Auth;
use Neo\Core\Database;
use Neo\Core\Response;
use Neo\Models\Portal;
use Neo\Services\AuditService;
use Neo\Services\JwtService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $user = (new Auth(new JwtService($config)))->user();
    $controller = new PortalController(new Portal($db), new AuditService($db), $user);

    match ($_GET['action'] ?? 'dashboard') {
        'dashboard' => $controller->dashboard(),
        default => Response::error('Accion no encontrada.', 404),
    };
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
