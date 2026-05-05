<?php

declare(strict_types=1);

namespace Locally\Security;

use Locally\Http\Request;

/**
 * Synchronizer token stored in the PHP session; client sends X-CSRF-Token on mutating requests.
 */
final class CsrfGuard
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function regenerate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    public static function validate(Request $request): bool
    {
        $sent = $request->header('X-CSRF-Token');
        if ($sent === null || $sent === '') {
            return false;
        }

        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $sent);
    }
}
