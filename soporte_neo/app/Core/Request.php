<?php

declare(strict_types=1);

namespace SoporteNeo\Core;

final class Request
{
    public static function json(): array
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $data = json_decode($rawBody, true);
        return is_array($data) ? $data : [];
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['HTTP_X_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if ($header === '' && isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return trim((string) $_SERVER['HTTP_X_AUTH_TOKEN']);
        }

        if (preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    public static function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }
}

