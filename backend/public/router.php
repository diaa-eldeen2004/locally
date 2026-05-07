<?php

declare(strict_types=1);

/**
 * Entry script for PHP's built-in server:
 *   php -S 127.0.0.1:8080 -t public router.php
 *
 * Serves real files under public/ (e.g. uploads); everything else goes through index.php (Kernel).
 */
if (PHP_SAPI !== 'cli-server') {
    require __DIR__ . '/index.php';

    return;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($uri) || $uri === '') {
    $uri = '/';
}

$file = __DIR__ . $uri;
$publicRoot = realpath(__DIR__);
if ($publicRoot !== false && $uri !== '/') {
    $resolved = realpath($file);
    if (
        $resolved !== false
        && str_starts_with($resolved, $publicRoot . DIRECTORY_SEPARATOR)
        && is_file($resolved)
    ) {
        return false;
    }
}

require __DIR__ . '/index.php';
