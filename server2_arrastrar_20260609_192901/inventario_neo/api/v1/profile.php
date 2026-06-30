<?php

declare(strict_types=1);

use InventarioNeo\Core\Auth;
use InventarioNeo\Core\Database;
use InventarioNeo\Core\Request;
use InventarioNeo\Core\Response;
use InventarioNeo\Services\JwtService;
use InventarioNeo\Services\ProfileService;

$config = require __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::connection($config);
    $user = (new Auth(new JwtService($config), $db))->requirePolicy('sistema.entrar');
    $profiles = new ProfileService($db);

    match ($_GET['action'] ?? 'ver') {
        'ver' => Response::success($profiles->get($user)),
        'guardar' => Response::success($profiles->save(Request::json(), $user), 'Perfil guardado.'),
        'solicitar_reinicio_password' => Response::success($profiles->requestPasswordReset($user), 'Solicitud enviada.'),
        'cambiar_password' => Response::success($profiles->changePassword(Request::json(), $user), 'Contrasena actualizada.'),
        default => Response::error('Accion no encontrada.', 404),
    };
} catch (InvalidArgumentException $exception) {
    Response::error($exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    Response::error('No se pudo completar la operacion.', 500);
}
