<?php

declare(strict_types=1);

namespace Locally\Http;

use Locally\Config\AppConfig;
use Locally\Config\DatabaseConfig;
use Locally\Config\SessionConfig;
use Locally\Database\PdoFactory;
use Locally\Http\Controllers\AdminCatalogController;
use Locally\Http\Controllers\AdminController;
use Locally\Http\Controllers\AdminUsersController;
use Locally\Http\Controllers\AnalyticsController;
use Locally\Http\Controllers\AuthController;
use Locally\Http\Controllers\CartController;
use Locally\Http\Controllers\CatalogController;
use Locally\Http\Controllers\ConfirmerController;
use Locally\Http\Controllers\FavoriteController;
use Locally\Http\Controllers\OrderController;
use Locally\Http\Controllers\ReviewController;
use Locally\Repository\AnalyticsRepository;
use Locally\Repository\CartRepository;
use Locally\Repository\CategoryRepository;
use Locally\Repository\FavoriteRepository;
use Locally\Repository\HomepageSectionRepository;
use Locally\Repository\OrderRepository;
use Locally\Repository\ProductRepository;
use Locally\Repository\ReviewRepository;
use Locally\Repository\UserRepository;
use Locally\Security\CsrfGuard;
use Locally\Security\RateLimiter;
use PDO;

final class Kernel
{
    private AppConfig $config;

    private ?PDO $pdoCache = null;

    private ?string $pdoConnectError = null;

    public function __construct()
    {
        $this->config = AppConfig::fromEnv();
    }

    public function handle(): Response
    {
        $request = Request::fromGlobals();

        if ($request->method === 'OPTIONS') {
            return $this->withSecurityHeaders($this->withCors(new Response(204, '', [])));
        }

        SessionBootstrap::start(SessionConfig::fromEnv());

        $rateBlock = $this->enforceRateLimit($request);
        if ($rateBlock !== null) {
            return $this->withSecurityHeaders($this->withCors($rateBlock));
        }

        $csrfBlock = $this->enforceCsrf($request);
        if ($csrfBlock !== null) {
            return $this->withSecurityHeaders($this->withCors($csrfBlock));
        }

        $router = new Router();
        $this->registerRoutes($router);

        $response = $router->dispatch($request);
        if ($response === null) {
            $response = Response::jsonError(
                ['code' => 'NOT_FOUND', 'message' => 'Route not found.'],
                404
            );
        }

        return $this->withSecurityHeaders($this->withCors($response));
    }

    private function registerRoutes(Router $router): void
    {
        // Helps when opening http://127.0.0.1:8080/ in the browser (no /api prefix).
        $router->get('/', static fn (): Response => Response::jsonOk([
            'message' => 'Locally API — use paths under /api',
            'try' => '/api/health',
            'docs' => 'See README.md in the repo root.',
        ]));

        $router->get('/api/health', fn (): Response => $this->health());

        $router->get('/api/csrf', static fn (): Response => Response::jsonOk([
            'csrf_token' => CsrfGuard::token(),
        ]));

        $pdo = $this->getPdo();
        if ($pdo === null) {
            $unavailable = fn (): Response => Response::jsonError(
                [
                    'code' => 'SERVICE_UNAVAILABLE',
                    'message' => 'Database is not configured or unreachable.',
                ],
                503
            );

            $router->post('/api/auth/register', static fn (Request $r): Response => $unavailable());
            $router->post('/api/auth/login', static fn (Request $r): Response => $unavailable());
            $router->get('/api/auth/me', static fn (Request $r): Response => $unavailable());
            $router->get('/api/admin/ping', static fn (Request $r): Response => $unavailable());
        } else {
            $users = new UserRepository($pdo);
            $carts = new CartRepository($pdo);
            $categories = new CategoryRepository($pdo);
            $products = new ProductRepository($pdo);
            $favorites = new FavoriteRepository($pdo);

            $auth = new AuthController($users, $carts);
            $orders = new OrderRepository($pdo, $carts);
            $analytics = new AnalyticsRepository($pdo);
            $analyticsCtl = new AnalyticsController($analytics);
            $admin = new AdminController($users, $orders, $analytics);
            $adminUsers = new AdminUsersController($users);
            $homepageRepo = new HomepageSectionRepository($pdo);
            $adminCatalog = new AdminCatalogController($users, $categories, $products, $homepageRepo, $this->config);
            $catalog = new CatalogController($categories, $products, $favorites);
            $favCtl = new FavoriteController($favorites);
            $cart = new CartController($carts);
            $orderCtl = new OrderController($orders);
            $confCtl = new ConfirmerController($orders, $users);
            $reviews = new ReviewRepository($pdo);
            $reviewCtl = new ReviewController($reviews, $users);

            $router->post('/api/auth/register', fn (Request $r): Response => $auth->register($r));
            $router->post('/api/auth/login', fn (Request $r): Response => $auth->login($r));
            $router->post('/api/auth/logout', fn (Request $r): Response => $auth->logout());
            $router->get('/api/auth/me', fn (Request $r): Response => $auth->me());
            $router->get('/api/admin/ping', fn (Request $r): Response => $admin->ping());
            $router->get('/api/admin/summary', fn (Request $r): Response => $admin->summary());
            $router->get('/api/admin/orders', fn (Request $r): Response => $admin->orders($r));
            $router->get('/api/admin/analytics/summary', fn (Request $r): Response => $admin->analyticsSummary());
            $router->get('/api/admin/users', fn (Request $r): Response => $adminUsers->list($r));
            $router->patchSlug('/api/admin/users', fn (Request $r, string $seg): Response => $adminUsers->patch($r, $seg));

            $router->get('/api/admin/categories', fn (Request $r): Response => $adminCatalog->categoriesList());
            $router->post('/api/admin/categories', fn (Request $r): Response => $adminCatalog->categoryCreate($r));
            $router->patchSlug('/api/admin/categories', fn (Request $r, string $seg): Response => $adminCatalog->categoryPatch($r, $seg));

            $router->get('/api/admin/products', fn (Request $r): Response => $adminCatalog->productsList($r));
            $router->post('/api/admin/products', fn (Request $r): Response => $adminCatalog->productCreate($r));
            $router->patchSlug('/api/admin/products', fn (Request $r, string $seg): Response => $adminCatalog->productPatch($r, $seg));
            $router->post('/api/admin/product-images', fn (Request $r): Response => $adminCatalog->productImageUpload($r));

            $router->get('/api/admin/homepage/sections', fn (Request $r): Response => $adminCatalog->homepageSectionsList());
            $router->put('/api/admin/homepage/sections/reorder', fn (Request $r): Response => $adminCatalog->homepageReorder($r));

            $router->get('/api/catalog/categories', fn (Request $r): Response => $catalog->categories());
            $router->get('/api/catalog/products', fn (Request $r): Response => $catalog->products($r));
            $router->getSlug('/api/catalog/products', fn (Request $r, string $slug): Response => $catalog->product($r, $slug));
            $router->get('/api/catalog/homepage', fn (Request $r): Response => $catalog->homepage($r));

            $router->post('/api/reviews', fn (Request $r): Response => $reviewCtl->create($r));
            $router->post('/api/analytics/track', fn (Request $r): Response => $analyticsCtl->track($r));

            $router->get('/api/cart', fn (Request $r): Response => $cart->get());
            $router->post('/api/cart/items', fn (Request $r): Response => $cart->upsertItem($r));
            $router->delete('/api/cart/items', fn (Request $r): Response => $cart->deleteItem($r));

            $router->get('/api/favorites', fn (Request $r): Response => $favCtl->list($r));
            $router->post('/api/favorites', fn (Request $r): Response => $favCtl->add($r));
            $router->delete('/api/favorites', fn (Request $r): Response => $favCtl->remove($r));

            $router->get('/api/orders', fn (Request $r): Response => $orderCtl->list($r));
            $router->post('/api/orders', fn (Request $r): Response => $orderCtl->create($r));
            $router->getSlug('/api/orders', fn (Request $r, string $seg): Response => $orderCtl->show($r, $seg));

            $router->get('/api/confirmer/orders', fn (Request $r): Response => $confCtl->list($r));
            $router->getSlug('/api/confirmer/orders', fn (Request $r, string $seg): Response => $confCtl->show($r, $seg));
            $router->post('/api/confirmer/orders/approve', fn (Request $r): Response => $confCtl->approve($r));
            $router->post('/api/confirmer/orders/reject', fn (Request $r): Response => $confCtl->reject($r));
        }
    }

    private function enforceCsrf(Request $request): ?Response
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        if (!CsrfGuard::validate($request)) {
            return Response::jsonError(
                [
                    'code' => 'CSRF_TOKEN_MISMATCH',
                    'message' => 'Send a valid X-CSRF-Token header (fetch GET /api/csrf first).',
                ],
                403
            );
        }

        return null;
    }

    private function enforceRateLimit(Request $request): ?Response
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $bucket = null;
        $limit = 0;
        $path = $request->path;
        if ($path === '/api/auth/login') {
            $bucket = 'auth-login';
            $limit = $this->config->rateLimitAuthLoginPerWindow;
        } elseif ($path === '/api/auth/register') {
            $bucket = 'auth-register';
            $limit = $this->config->rateLimitAuthRegisterPerWindow;
        } elseif ($path === '/api/analytics/track') {
            $bucket = 'analytics';
            $limit = $this->config->rateLimitAnalyticsPerWindow;
        } elseif ($path === '/api/orders') {
            $bucket = 'orders-create';
            $limit = $this->config->rateLimitOrdersCreatePerWindow;
        } elseif ($path === '/api/confirmer/orders/approve' || $path === '/api/confirmer/orders/reject') {
            $bucket = 'confirmer-decision';
            $limit = $this->config->rateLimitConfirmerDecisionPerWindow;
        } elseif ($path === '/api/admin/product-images') {
            $bucket = 'uploads';
            $limit = $this->config->rateLimitUploadsPerWindow;
        }

        if ($bucket === null) {
            return null;
        }

        $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $identity = $uid > 0 ? 'u:' . $uid : 'ip:' . $this->clientIp($request);
        $limiter = new RateLimiter(dirname(__DIR__, 2) . '/storage/rate-limits');
        $res = $limiter->allow($bucket, $identity, $limit, $this->config->rateLimitWindowSeconds);
        if ($res['allowed']) {
            return null;
        }

        return Response::jsonError(
            [
                'code' => 'RATE_LIMITED',
                'message' => 'Too many requests. Please retry in about ' . $res['retry_after_seconds'] . ' seconds.',
            ],
            429
        )->withHeaders([
            'Retry-After' => (string) $res['retry_after_seconds'],
        ]);
    }

    private function clientIp(Request $request): string
    {
        $xff = $request->server['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($xff) && $xff !== '') {
            $first = trim(explode(',', $xff)[0] ?? '');
            if ($first !== '') {
                return substr($first, 0, 64);
            }
        }

        $ip = $request->server['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 64) : '0.0.0.0';
    }

    private function health(): Response
    {
        $payload = [
            'service' => 'locally-api',
            'time' => gmdate('c'),
            'database' => $this->databaseStatus(),
        ];

        return Response::jsonOk($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseStatus(): array
    {
        $dbConfig = DatabaseConfig::tryFromEnv();
        if ($dbConfig === null) {
            return [
                'configured' => false,
                'message' => 'Set DB_NAME (and related DB_* vars) in backend/.env to enable MySQL.',
            ];
        }

        $pdo = $this->getPdo();
        if ($pdo === null) {
            return [
                'configured' => true,
                'connected' => false,
                'driver' => $dbConfig->driver,
                'error' => $this->config->appDebug ? ($this->pdoConnectError ?? 'Connection failed.') : 'Database connection failed.',
            ];
        }

        $started = microtime(true);
        try {
            $version = $this->fetchMysqlVersion($pdo);
        } catch (\Throwable) {
            $version = null;
        }
        $latencyMs = (microtime(true) - $started) * 1000.0;

        return [
            'configured' => true,
            'connected' => true,
            'driver' => $dbConfig->driver,
            'latency_ms' => round($latencyMs, 2),
            'server_version' => $version,
        ];
    }

    private function fetchMysqlVersion(PDO $pdo): ?string
    {
        $stmt = $pdo->query('SELECT VERSION() AS v');
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch();
        if (!is_array($row) || !isset($row['v'])) {
            return null;
        }

        return (string) $row['v'];
    }

    private function getPdo(): ?PDO
    {
        if ($this->pdoCache !== null) {
            return $this->pdoCache;
        }

        $dbConfig = DatabaseConfig::tryFromEnv();
        if ($dbConfig === null) {
            return null;
        }

        [$pdo, $error] = PdoFactory::connect($dbConfig);
        if ($pdo === null) {
            $this->pdoConnectError = $error;

            return null;
        }

        $this->pdoConnectError = null;
        $this->pdoCache = $pdo;

        return $this->pdoCache;
    }

    private function withCors(Response $response): Response
    {
        $origin = $this->config->corsOrigin;

        return $response->withHeaders([
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        ]);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => $this->config->contentSecurityPolicy,
        ]);
    }
}
