<?php

declare(strict_types=1);

namespace Locally\Config;

/**
 * Single-database connection settings (matches database/schema.sql target: MySQL 8+).
 */
final class DatabaseConfig
{
    public function __construct(
        public readonly string $driver,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {
    }

    public static function tryFromEnv(): ?self
    {
        $database = Env::string('DB_NAME', '');
        if ($database === '') {
            return null;
        }

        return new self(
            driver: strtolower(Env::string('DB_DRIVER', 'mysql')),
            host: Env::string('DB_HOST', '127.0.0.1'),
            port: max(1, (int) Env::string('DB_PORT', '3306')),
            database: $database,
            username: Env::string('DB_USER', 'root'),
            password: Env::string('DB_PASSWORD', ''),
        );
    }

    public function dsn(): string
    {
        if ($this->driver !== 'mysql') {
            throw new \InvalidArgumentException('Unsupported DB_DRIVER; Phase 1 supports mysql only.');
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->database
        );
    }
}
