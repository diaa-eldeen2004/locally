<?php

declare(strict_types=1);

namespace Locally\Http;

use Locally\Config\SessionConfig;

final class SessionBootstrap
{
    public static function start(SessionConfig $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $config->cookieSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
