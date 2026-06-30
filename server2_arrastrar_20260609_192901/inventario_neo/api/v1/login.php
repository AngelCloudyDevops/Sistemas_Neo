<?php

declare(strict_types=1);

use InventarioNeo\Controllers\AuthController;
use InventarioNeo\Core\Database;
use InventarioNeo\Core\Response;
use InventarioNeo\Models\User;
use InventarioNeo\Services\AuditService;
use InventarioNeo\Services\JwtService;
use InventarioNeo\Services\SessionService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $users = new User($db);
    $audit = new AuditService($db);
    $jwt = new JwtService($config);

    (new AuthController($users, $jwt, $audit, new SessionService($db, $config)))->login();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
