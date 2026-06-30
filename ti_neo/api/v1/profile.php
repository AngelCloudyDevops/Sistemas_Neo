<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use TiNeo\Controllers\ProfileController;
use TiNeo\Core\Auth;
use TiNeo\Core\Database;
use TiNeo\Core\Response;
use TiNeo\Services\JwtService;
use TiNeo\Services\ProfileService;

$config = require BASE_PATH . '/config/config.php';
$db = Database::connection($config);
$auth = new Auth(new JwtService($config), $db);
$controller = new ProfileController(new ProfileService($db));
$action = (string) ($_GET['action'] ?? 'ver');

match ($action) {
    'ver' => (function () use ($auth, $controller): void {
        $controller->show($auth->requirePolicy('sistema.entrar', 'sistema:tickets'));
    })(),
    'guardar' => (function () use ($auth, $controller): void {
        $controller->save($auth->requirePolicy('sistema.entrar', 'sistema:tickets'));
    })(),
    'solicitar_reinicio_password' => (function () use ($auth, $controller): void {
        $controller->requestPasswordReset($auth->requirePolicy('sistema.entrar', 'sistema:tickets'));
    })(),
    'cambiar_password' => (function () use ($auth, $controller): void {
        $controller->changePassword($auth->requirePolicy('sistema.entrar', 'sistema:tickets'));
    })(),
    default => Response::error('Accion no encontrada.', 404),
};
