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

spl_autoload_register(static function (string $class): void {
    $prefix = 'InventarioNeo\\';
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
