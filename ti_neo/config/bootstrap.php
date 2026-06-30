<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$config = require BASE_PATH . '/config/config.php';

date_default_timezone_set($config['app']['timezone']);
header_remove('X-Powered-By');

if (($config['app']['debug'] ?? false) === true) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/logs/php_errors.log');
}

$logUnhandled = static function (Throwable $exception): void {
    $uri = (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . (string) ($_SERVER['REQUEST_URI'] ?? '');
    error_log(sprintf(
        "[ti_neo] Unhandled %s at %s: %s in %s:%d\n%s",
        $exception::class,
        $uri,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));
};

set_exception_handler(static function (Throwable $exception) use ($logUnhandled): void {
    $logUnhandled($exception);
    if (!headers_sent()) {
        http_response_code(500);
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $uri = (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . (string) ($_SERVER['REQUEST_URI'] ?? '');
    error_log(sprintf(
        '[ti_neo] Fatal at %s: %s in %s:%d',
        $uri,
        (string) ($error['message'] ?? 'fatal error'),
        (string) ($error['file'] ?? ''),
        (int) ($error['line'] ?? 0)
    ));
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'TiNeo\\';
    $baseDir = BASE_PATH . '/app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

return $config;
