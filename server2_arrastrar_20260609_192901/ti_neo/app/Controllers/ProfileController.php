<?php

declare(strict_types=1);

namespace TiNeo\Controllers;

use RuntimeException;
use TiNeo\Core\Request;
use TiNeo\Core\Response;
use TiNeo\Services\ProfileService;

final class ProfileController
{
    public function __construct(private readonly ProfileService $profiles)
    {
    }

    public function show(array $user): void
    {
        try {
            Response::success($this->profiles->get($user));
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function save(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->profiles->save(Request::json(), $user), 'Perfil guardado.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function requestPasswordReset(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->profiles->requestPasswordReset($user), 'Solicitud enviada.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }

    public function changePassword(array $user): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        try {
            Response::success($this->profiles->changePassword(Request::json(), $user), 'Contrasena actualizada.');
        } catch (RuntimeException $exception) {
            Response::error($exception->getMessage(), 422);
        }
    }
}
