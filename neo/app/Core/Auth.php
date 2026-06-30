<?php

declare(strict_types=1);

namespace Neo\Core;

use Neo\Services\JwtService;

final class Auth
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    public function user(): array
    {
        $token = Request::bearerToken();

        if ($token === null) {
            Response::error('Sesion no iniciada.', 401);
        }

        try {
            $payload = $this->jwt->validate($token);
            $user = $payload['user'] ?? null;
            if (!is_array($user)) {
                Response::error('Sesion invalida.', 401);
            }

            return $user;
        } catch (\Throwable) {
            Response::error('Sesion invalida o vencida.', 401);
        }
    }
}
