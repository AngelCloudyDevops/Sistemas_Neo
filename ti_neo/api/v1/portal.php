<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use TiNeo\Controllers\PortalController;
use TiNeo\Core\Auth;
use TiNeo\Core\Database;
use TiNeo\Core\Response;
use TiNeo\Models\Portal;
use TiNeo\Services\JwtService;

$config = require BASE_PATH . '/config/config.php';
$db = Database::connection($config);
$auth = new Auth(new JwtService($config));
$user = $auth->user();
$controller = new PortalController(new Portal($db), $user);
$action = $_GET['action'] ?? 'systems';

match ($action) {
    'systems' => $controller->systems(),
    default => Response::error('Accion no encontrada.', 404),
};
