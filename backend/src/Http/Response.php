<?php

declare(strict_types=1);

namespace Locally\Http;

/**
 * JSON responses follow the centralized API envelope (Phase 0 contract).
 */
final class Response
{
    /** @param array<string, mixed> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed>|null $data */
    public static function jsonEnvelope(bool $ok, mixed $data, ?array $error, int $status = 200): self
    {
        $payload = [
            'ok' => $ok,
            'data' => $data,
            'error' => $error,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return new self(
            status: $status,
            body: $body,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
        );
    }

    public static function jsonOk(mixed $data, int $status = 200): self
    {
        return self::jsonEnvelope(true, $data, null, $status);
    }

    /** @param array{code:string,message:string} $error */
    public static function jsonError(array $error, int $status = 400): self
    {
        return self::jsonEnvelope(false, null, $error, $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $this->body;
    }

    /** @param array<string, string> $extra */
    public function withHeaders(array $extra): self
    {
        return new self(
            status: $this->status,
            body: $this->body,
            headers: array_merge($this->headers, $extra),
        );
    }
}
