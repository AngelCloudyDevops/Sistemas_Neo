<?php

declare(strict_types=1);

namespace InventarioNeo\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(array $data = [], string $message = 'OK'): void
    {
        self::json(['ok' => true, 'message' => $message, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['ok' => false, 'message' => $message], $status);
    }
}
