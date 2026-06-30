<?php

declare(strict_types=1);

namespace GsNeo\Services;

use InvalidArgumentException;

final class JwtService
{
    public function __construct(private readonly array $config)
    {
    }

    public function generate(array $user, ?int $sessionId = null): string
    {
        $now = time();
        $ttl = $this->ttl();
        $payload = [
            'iss' => $this->config['jwt']['issuer'],
            'aud' => $this->config['jwt']['audience'],
            'iat' => $now,
            'sid' => $sessionId,
            'exp' => $now + $ttl,
            'user' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'nombre' => trim(($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? '')),
                'rol_id' => (int) $user['rol_id'],
                'rol' => $user['rol_nombre'],
            ],
        ];

        return $this->encode($payload);
    }

    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Token invalido.');
        }

        [$header64, $payload64, $signature64] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header64 . '.' . $payload64, $this->secret(), true));

        if (!hash_equals($expected, $signature64)) {
            throw new InvalidArgumentException('Firma de token invalida.');
        }

        $payload = json_decode($this->base64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Contenido de token invalido.');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new InvalidArgumentException('Token expirado.');
        }

        return $payload;
    }

    public function ttl(): int
    {
        return (int) ($this->config['jwt']['ttl_seconds'] ?? 3600);
    }

    private function encode(array $payload): string
    {
        $header64 = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload64 = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature64 = $this->base64UrlEncode(hash_hmac('sha256', $header64 . '.' . $payload64, $this->secret(), true));

        return $header64 . '.' . $payload64 . '.' . $signature64;
    }

    private function secret(): string
    {
        return (string) $this->config['jwt']['secret'];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
