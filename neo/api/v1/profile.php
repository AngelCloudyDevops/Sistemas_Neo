<?php

declare(strict_types=1);

use Neo\Core\Auth;
use Neo\Core\Database;
use Neo\Core\Request;
use Neo\Core\Response;
use Neo\Services\JwtService;
use Neo\Services\ProfileService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $user = (new Auth(new JwtService($config)))->user();
    $profiles = new ProfileService($db);

    match ($_GET['action'] ?? 'ver') {
        'ver' => Response::success($profiles->get($user)),
        'guardar' => Response::success($profiles->save(Request::json(), $user), 'Perfil guardado.'),
        'solicitar_reinicio_password' => Response::success($profiles->requestPasswordReset($user), 'Solicitud enviada.'),
        'cambiar_password' => Response::success($profiles->changePassword(Request::json(), $user), 'Contrasena actualizada.'),
        default => Response::error('Accion no encontrada.', 404),
    };
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
