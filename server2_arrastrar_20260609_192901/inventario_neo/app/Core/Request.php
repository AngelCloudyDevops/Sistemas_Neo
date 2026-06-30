<?php

declare(strict_types=1);

namespace InventarioNeo\Core;

final class Request
{
    public static function json(): array
    {
        $payload = file_get_contents('php://input');
        if ($payload === false || trim($payload) === '') {
            return [];
        }

        $data = json_decode($payload, true);
        return is_array($data) ? $data : [];
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/Bearer\s+(.+)/i', (string) $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
