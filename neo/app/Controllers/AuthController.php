<?php

declare(strict_types=1);

namespace Neo\Controllers;

use Neo\Core\Request;
use Neo\Core\Response;
use Neo\Models\User;
use Neo\Services\AuditService;
use Neo\Services\JwtService;
use Neo\Services\SessionService;

final class AuthController
{
    public function __construct(
        private readonly User $users,
        private readonly JwtService $jwt,
        private readonly AuditService $audit,
        private readonly SessionService $sessions
    ) {
    }

    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $login = strtolower(trim((string) ($input['login'] ?? $input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($login === '' || $password === '') {
            Response::error('Ingrese usuario y contrasena validos.', 422);
        }

        $user = $this->users->findActiveByLogin($login);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            $this->audit->log(null, 'LOGIN_FALLIDO', 'Intento fallido: ' . $login);
            Response::error('Credenciales incorrectas.', 401);
        }

        $this->users->updateLastLogin((int) $user['id']);
        $session = $this->sessions->create((int) $user['id'], 'neo');
        $token = $this->jwt->generate($user, (int) $session['session_id']);
        $this->audit->log((int) $user['id'], 'LOGIN_EXITOSO', 'Ingreso a portal NEO.');

        unset($user['password_hash']);

        Response::success([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'refresh_token' => $session['refresh_token'],
            'expires_in' => $this->jwt->ttl(),
            'refresh_expires_in' => $session['refresh_expires_in'],
            'user' => $user,
        ], 'Sesion iniciada.');
    }

    public function refresh(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $refreshToken = trim((string) ($input['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            Response::error('Refresh token requerido.', 422);
        }

        try {
            $session = $this->sessions->renew($refreshToken);
            $user = $this->users->findActiveById((int) $session['user_id']);
            if ($user === null) {
                $this->sessions->revoke($refreshToken);
                Response::error('Usuario no encontrado o inactivo.', 401);
            }
            $token = $this->jwt->generate($user, (int) $session['session_id']);
            unset($user['password_hash']);

            Response::success([
                'token_type' => 'Bearer',
                'access_token' => $token,
                'expires_in' => $this->jwt->ttl(),
                'user' => $user,
            ], 'Sesion renovada.');
        } catch (\Throwable $exception) {
            Response::error($exception->getMessage(), 401);
        }
    }

    public function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::error('Metodo no permitido.', 405);
        }

        $input = Request::json();
        $refreshToken = trim((string) ($input['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            $this->sessions->revoke($refreshToken);
        }

        Response::success([], 'Sesion cerrada.');
    }
}
