<?php

declare(strict_types=1);

namespace Locally\Config;

/**
 * Typed read-only config built from environment (single source of truth for runtime settings).
 */
final class AppConfig
{
    public function __construct(
        public readonly string $appEnv,
        public readonly bool $appDebug,
        public readonly string $appUrl,
        public readonly string $corsOrigin,
        public readonly int $rateLimitWindowSeconds,
        public readonly int $rateLimitAuthLoginPerWindow,
        public readonly int $rateLimitAuthRegisterPerWindow,
        public readonly int $rateLimitAnalyticsPerWindow,
        public readonly int $rateLimitOrdersCreatePerWindow,
        public readonly int $rateLimitConfirmerDecisionPerWindow,
        public readonly int $rateLimitUploadsPerWindow,
        public readonly string $contentSecurityPolicy,
        public readonly string $uploadScanCommand,
        public readonly bool $uploadScanRequired,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            appEnv: Env::string('APP_ENV', 'production'),
            appDebug: Env::bool('APP_DEBUG', false),
            appUrl: rtrim(Env::string('APP_URL', 'http://127.0.0.1:8080'), '/'),
            corsOrigin: rtrim(Env::string('CORS_ORIGIN', 'http://127.0.0.1:5173'), '/'),
            rateLimitWindowSeconds: max(10, (int) Env::string('RATE_LIMIT_WINDOW_SECONDS', '60')),
            rateLimitAuthLoginPerWindow: max(3, (int) Env::string('RATE_LIMIT_AUTH_LOGIN_PER_WINDOW', '12')),
            rateLimitAuthRegisterPerWindow: max(2, (int) Env::string('RATE_LIMIT_AUTH_REGISTER_PER_WINDOW', '8')),
            rateLimitAnalyticsPerWindow: max(20, (int) Env::string('RATE_LIMIT_ANALYTICS_PER_WINDOW', '180')),
            rateLimitOrdersCreatePerWindow: max(2, (int) Env::string('RATE_LIMIT_ORDERS_CREATE_PER_WINDOW', '20')),
            rateLimitConfirmerDecisionPerWindow: max(5, (int) Env::string('RATE_LIMIT_CONFIRMER_DECISION_PER_WINDOW', '60')),
            rateLimitUploadsPerWindow: max(2, (int) Env::string('RATE_LIMIT_UPLOADS_PER_WINDOW', '20')),
            contentSecurityPolicy: trim(Env::string('CONTENT_SECURITY_POLICY', "default-src 'none'; frame-ancestors 'none'")),
            uploadScanCommand: trim(Env::string('UPLOAD_SCAN_COMMAND', '')),
            uploadScanRequired: Env::bool('UPLOAD_SCAN_REQUIRED', false),
        );
    }
}
