<?php

declare(strict_types=1);

/**
 * Router script for PHP built-in server so all non-file requests hit index.php.
 * Usage: php -S 127.0.0.1:8080 -t public public/router.php
 */
if (PHP_SAPI_NAME() === 'cli-server') {
    $uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $file = __DIR__ . $uri;
    if ($uri !== '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/index.php';
