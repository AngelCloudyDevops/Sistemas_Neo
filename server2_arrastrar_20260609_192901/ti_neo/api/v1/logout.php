<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

use TiNeo\Controllers\AuthController;
use TiNeo\Core\Database;
use TiNeo\Models\User;
use TiNeo\Services\AuditService;
use TiNeo\Services\JwtService;
use TiNeo\Services\SessionService;

$config = require BASE_PATH . '/config/config.php';
$db = Database::connection($config);

(new AuthController(
    new User($db),
    new JwtService($config),
    new AuditService($db),
    new SessionService($db, $config)
))->logout();
