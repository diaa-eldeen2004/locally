<?php

declare(strict_types=1);

namespace Locally\Security;

/**
 * Lightweight file-based fixed-window rate limiter for small deployments.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $storageDir,
    ) {
    }

    /**
     * @return array{allowed: bool, retry_after_seconds: int}
     */
    public function allow(string $bucket, string $identity, int $limit, int $windowSeconds): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $windowStart = (int) (floor($now / $windowSeconds) * $windowSeconds);
        $safeBucket = preg_replace('/[^a-zA-Z0-9._-]/', '_', $bucket) ?? 'bucket';
        $keyRaw = $safeBucket . '|' . $identity . '|' . $windowStart;
        $key = sha1($keyRaw);
        $file = rtrim($this->storageDir, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';

        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0755, true) && !is_dir($this->storageDir)) {
            // Fail-open: avoid blocking traffic if storage cannot be created.
            return ['allowed' => true, 'retry_after_seconds' => 0];
        }

        $count = 0;
        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['count']) && is_numeric($decoded['count'])) {
                    $count = (int) $decoded['count'];
                }
            }
        }

        $count += 1;
        $payload = json_encode(['count' => $count], JSON_THROW_ON_ERROR);
        @file_put_contents($file, $payload, LOCK_EX);

        $retry = max(1, $windowStart + $windowSeconds - $now);
        if ($count > $limit) {
            return ['allowed' => false, 'retry_after_seconds' => $retry];
        }

        return ['allowed' => true, 'retry_after_seconds' => $retry];
    }
}
