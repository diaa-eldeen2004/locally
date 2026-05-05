<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\AnalyticsRepository;
use Locally\Repository\OrderRepository;
use Locally\Repository\UserRepository;
use DateTimeImmutable;

/** RBAC admin endpoints; expand with CRUD in Phase 7 UI. */
final class AdminController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OrderRepository $orders,
        private readonly AnalyticsRepository $analytics,
    ) {
    }

    public function ping(): Response
    {
        return $this->requireAdminResponse(fn (): Response => Response::jsonOk(['message' => 'Administrator access confirmed.']));
    }

    public function summary(): Response
    {
        return $this->requireAdminResponse(fn (): Response => Response::jsonOk($this->orders->adminDashboardMetrics()));
    }

    public function analyticsSummary(): Response
    {
        return $this->requireAdminResponse(function (): Response {
            $since = new DateTimeImmutable('-7 days');

            return Response::jsonOk([
                'since' => $since->format('c'),
                'events_by_name' => $this->analytics->countsByEventSince($since),
                'recent_events' => $this->analytics->recent(25),
            ]);
        });
    }

    public function orders(Request $request): Response
    {
        return $this->requireAdminResponse(function () use ($request): Response {
            $rawStatus = isset($request->query['status']) && is_string($request->query['status'])
                ? trim($request->query['status'])
                : 'all';
            $allowed = [
                'all',
                'pending_approval',
                'approved',
                'rejected',
                'cancelled',
                'processing',
                'shipped',
                'delivered',
            ];
            $status = in_array(strtolower($rawStatus), $allowed, true) ? strtolower($rawStatus) : 'all';
            $statusFilter = $status === 'all' ? null : $status;

            $q = isset($request->query['q']) && is_string($request->query['q']) ? trim($request->query['q']) : '';
            $page = max(1, (int) ($request->query['page'] ?? 1));
            $per = min(50, max(1, (int) ($request->query['per_page'] ?? 20)));

            $res = $this->orders->listForAdmin($statusFilter, $q, $page, $per);
            $items = array_map(fn (array $o): array => $this->shapeAdminOrderRow($o), $res['items']);

            return Response::jsonOk([
                'items' => $items,
                'page' => $page,
                'per_page' => $per,
                'total' => $res['total'],
            ]);
        });
    }

    /**
     * @param callable(): Response $fn
     */
    private function requireAdminResponse(callable $fn): Response
    {
        $block = Access::ensureRoles($this->users, ['admin']);
        if ($block !== null) {
            return $block;
        }

        return $fn();
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function shapeAdminOrderRow(array $o): array
    {
        return [
            'id' => (int) $o['id'],
            'order_number' => (string) $o['order_number'],
            'status' => (string) $o['status'],
            'grand_total' => (float) $o['grand_total'],
            'currency' => (string) $o['currency'],
            'created_at' => (string) $o['created_at'],
            'line_count' => (int) ($o['line_count'] ?? 0),
            'customer' => [
                'email' => (string) ($o['customer_email'] ?? ''),
                'first_name' => (string) ($o['customer_first_name'] ?? ''),
                'last_name' => (string) ($o['customer_last_name'] ?? ''),
            ],
        ];
    }
}
