<?php

declare(strict_types=1);

/**
 * Application bootstrap: PSR-4 style autoload (no Composer required until you run composer dump-autoload).
 */
$basePath = __DIR__;

spl_autoload_register(static function (string $class) use ($basePath): void {
    $prefix = 'Locally\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $basePath . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once $basePath . '/src/Config/Env.php';

Locally\Config\Env::load($basePath . '/.env');
