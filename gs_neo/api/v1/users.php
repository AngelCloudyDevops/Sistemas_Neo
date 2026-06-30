<?php

declare(strict_types=1);

use GsNeo\Controllers\UserController;
use GsNeo\Core\Auth;
use GsNeo\Core\Database;
use GsNeo\Core\Response;
use GsNeo\Models\UserAccount;
use GsNeo\Services\AuditService;
use GsNeo\Services\JwtService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $jwt = new JwtService($config);
    $users = new UserAccount($db);
    $audit = new AuditService($db);
    $auth = new Auth($jwt, $db);

    (new UserController($users, $auth, $audit))->handle();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
