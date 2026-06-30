<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$config = require BASE_PATH . '/config/config.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'SoporteNeo\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (empty($config['app']['base_url'])) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $config['app']['base_url'] = $scheme . '://' . $host . '/soporte_neo/public';
}
date_default_timezone_set((string) $config['app']['timezone']);
header_remove('X-Powered-By');

if (($config['app']['debug'] ?? false) === true) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

return $config;


