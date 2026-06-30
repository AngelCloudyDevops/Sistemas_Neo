<?php

declare(strict_types=1);

use GsNeo\Controllers\AuthController;
use GsNeo\Core\Database;
use GsNeo\Core\Response;
use GsNeo\Models\UserAccount;
use GsNeo\Services\AuditService;
use GsNeo\Services\JwtService;
use GsNeo\Services\SessionService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $users = new UserAccount($db);
    $audit = new AuditService($db);
    $jwt = new JwtService($config);

    (new AuthController($users, $jwt, $audit, new SessionService($db, $config)))->login();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
