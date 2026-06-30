<?php

declare(strict_types=1);

use InventarioNeo\Controllers\InventoryController;
use InventarioNeo\Core\Auth;
use InventarioNeo\Core\Database;
use InventarioNeo\Core\Response;
use InventarioNeo\Models\Inventory;
use InventarioNeo\Services\AuditService;
use InventarioNeo\Services\JwtService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $jwt = new JwtService($config);
    $auth = new Auth($jwt, $db);
    $inventory = new Inventory($db);
    $audit = new AuditService($db);

    (new InventoryController($inventory, $auth, $audit))->handle();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
