<?php

declare(strict_types=1);

namespace Locally\Config;

/**
 * Minimal .env loader (KEY=value per line). Does not override existing getenv/$_ENV.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '') {
                continue;
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if (getenv($name) === false && !array_key_exists($name, $_ENV)) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public static function string(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return $default;
        }
        return (string) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = self::string($key, $default ? '1' : '0');
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}
