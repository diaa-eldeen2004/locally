<?php

declare(strict_types=1);

namespace Locally\Config;

final class SessionConfig
{
    public function __construct(
        public readonly string $sessionName,
        public readonly bool $cookieSecure,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            sessionName: Env::string('SESSION_NAME', 'LOCALLY_SESSION'),
            cookieSecure: Env::bool('SESSION_SECURE', false),
        );
    }
}
