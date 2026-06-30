<?php

declare(strict_types=1);

use Neo\Controllers\AuthController;
use Neo\Core\Database;
use Neo\Core\Response;
use Neo\Models\User;
use Neo\Services\AuditService;
use Neo\Services\JwtService;
use Neo\Services\SessionService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    (new AuthController(
        new User($db),
        new JwtService($config),
        new AuditService($db),
        new SessionService($db, $config)
    ))->login();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
