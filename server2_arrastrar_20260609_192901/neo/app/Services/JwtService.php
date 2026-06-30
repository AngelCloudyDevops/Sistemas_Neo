<?php

declare(strict_types=1);

namespace Neo\Services;

use RuntimeException;

final class JwtService
{
    private string $secret;
    private string $issuer;
    private string $audience;
    private int $ttl;

    public function __construct(array $config)
    {
        $jwt = $config['jwt'];
        $this->secret = (string) $jwt['secret'];
        $this->issuer = (string) $jwt['issuer'];
        $this->audience = (string) $jwt['audience'];
        $this->ttl = (int) $jwt['ttl_seconds'];
    }

    public function generate(array $user, ?int $sessionId = null): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'sid' => $sessionId,
            'exp' => $now + $this->ttl,
            'user' => [
                'id' => (int) $user['id'],
                'rol_id' => (int) $user['rol_id'],
                'dni' => $user['dni'] ?? null,
                'nombres' => $user['nombres'] ?? '',
                'apellidos' => $user['apellidos'] ?? '',
                'email' => $user['email'] ?? '',
                'rol' => $user['rol_nombre'] ?? '',
            ],
        ];

        return $this->encode(['alg' => 'HS256', 'typ' => 'JWT']) . '.'
            . $this->encode($payload) . '.'
            . $this->signature($this->encode(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $this->encode($payload));
    }

    public function validate(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Token invalido.');
        }

        [$header, $payload, $signature] = $parts;
        if (!hash_equals($this->signature($header . '.' . $payload), $signature)) {
            throw new RuntimeException('Firma invalida.');
        }

        $data = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token vencido.');
        }

        return $data;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    private function encode(array $data): string
    {
        return $this->base64UrlEncode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function signature(string $value): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $value, $this->secret, true));
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
