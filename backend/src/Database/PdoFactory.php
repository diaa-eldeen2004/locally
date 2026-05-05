<?php

declare(strict_types=1);

namespace Locally\Database;

use Locally\Config\DatabaseConfig;
use PDO;
use PDOException;

final class PdoFactory
{
    /**
     * @return array{0: PDO|null, 1: string|null} [pdo, errorMessage]
     */
    public static function connect(DatabaseConfig $config): array
    {
        try {
            $pdo = new PDO(
                $config->dsn(),
                $config->username,
                $config->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return [$pdo, null];
        } catch (PDOException $e) {
            return [null, $e->getMessage()];
        }
    }
}
