<?php

declare(strict_types=1);

namespace Locally\Http;

use JsonException;

final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/') ?: '/';
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            server: $_SERVER,
        );
    }

    public function header(string $name): ?string
    {
        if (strcasecmp($name, 'Content-Type') === 0) {
            $ct = $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? null;
            if (is_string($ct) && $ct !== '') {
                return $ct;
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp((string) $key, $name) === 0 && is_string($value)) {
                        return $value;
                    }
                }
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $fallback = $this->server[$serverKey] ?? null;

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * Parsed JSON object from the request body (empty object when body is empty).
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function jsonBody(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            $this->jsonCache = [];

            return $this->jsonCache;
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            $this->jsonCache = [];

            return $this->jsonCache;
        }

        $this->jsonCache = $decoded;

        return $this->jsonCache;
    }
}
