<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Domain\CheckoutException;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\OrderRepository;
use Locally\Repository\UserRepository;
use JsonException;
use PDOException;

final class ConfirmerController
{
    private const ALLOWED_STATUS = [
        'all',
        'pending_approval',
        'approved',
        'rejected',
        'cancelled',
        'processing',
        'shipped',
        'delivered',
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly UserRepository $users,
    ) {
    }

    public function list(Request $request): Response
    {
        $block = Access::ensureRoles($this->users, ['admin', 'confirmer']);
        if ($block !== null) {
            return $block;
        }

        $rawStatus = isset($request->query['status']) && is_string($request->query['status'])
            ? trim($request->query['status'])
            : 'pending_approval';
        $status = in_array(strtolower($rawStatus), self::ALLOWED_STATUS, true) ? strtolower($rawStatus) : 'pending_approval';
        $statusFilter = $status === 'all' ? null : $status;

        $q = isset($request->query['q']) && is_string($request->query['q']) ? trim($request->query['q']) : '';
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $per = min(50, max(1, (int) ($request->query['per_page'] ?? 20)));

        $res = $this->orders->listForConfirmer($statusFilter, $q, $page, $per);
        $items = array_map(fn (array $o): array => $this->shapeConfirmerSummary($o), $res['items']);

        return Response::jsonOk([
            'items' => $items,
            'page' => $page,
            'per_page' => $per,
            'total' => $res['total'],
        ]);
    }

    public function show(Request $request, string $segment): Response
    {
        $block = Access::ensureRoles($this->users, ['admin', 'confirmer']);
        if ($block !== null) {
            return $block;
        }

        if (!ctype_digit($segment)) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Order not found.'], 404);
        }

        $id = (int) $segment;
        $order = $this->orders->getOrderForStaff($id);
        if ($order === null) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Order not found.'], 404);
        }

        return Response::jsonOk(['order' => $this->shapeStaffOrder($order)]);
    }

    public function approve(Request $request): Response
    {
        $block = Access::ensureRoles($this->users, ['admin', 'confirmer']);
        if ($block !== null) {
            return $block;
        }

        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $orderId = isset($body['order_id']) ? (int) $body['order_id'] : 0;
        if ($orderId <= 0) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'order_id is required.'], 422);
        }

        $note = is_string($body['note'] ?? null) ? $body['note'] : null;

        try {
            $this->orders->approve($orderId, Access::userId() ?? 0, $note);

            return Response::jsonOk(['order_id' => $orderId, 'status' => 'approved']);
        } catch (CheckoutException $e) {
            return Response::jsonError(
                ['code' => $e->errorCode, 'message' => $e->getMessage()],
                $e->httpStatus
            );
        } catch (PDOException) {
            return Response::jsonError(['code' => 'UPDATE_FAILED', 'message' => 'Could not approve the order.'], 500);
        }
    }

    public function reject(Request $request): Response
    {
        $block = Access::ensureRoles($this->users, ['admin', 'confirmer']);
        if ($block !== null) {
            return $block;
        }

        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $orderId = isset($body['order_id']) ? (int) $body['order_id'] : 0;
        if ($orderId <= 0) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'order_id is required.'], 422);
        }

        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;

        try {
            $this->orders->reject($orderId, Access::userId() ?? 0, $reason);

            return Response::jsonOk(['order_id' => $orderId, 'status' => 'rejected']);
        } catch (CheckoutException $e) {
            return Response::jsonError(
                ['code' => $e->errorCode, 'message' => $e->getMessage()],
                $e->httpStatus
            );
        } catch (PDOException) {
            return Response::jsonError(['code' => 'UPDATE_FAILED', 'message' => 'Could not reject the order.'], 500);
        }
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function shapeConfirmerSummary(array $o): array
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

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function shapeStaffOrder(array $o): array
    {
        $items = [];
        foreach ($o['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $items[] = [
                'id' => (int) $it['id'],
                'product_id' => (int) $it['product_id'],
                'variant_id' => $it['variant_id'] !== null ? (int) $it['variant_id'] : null,
                'product_name' => (string) $it['product_name'],
                'variant_label' => (string) $it['variant_label'],
                'unit_price' => (float) $it['unit_price'],
                'quantity' => (int) $it['quantity'],
                'line_total' => (float) $it['line_total'],
            ];
        }

        return [
            'id' => (int) $o['id'],
            'order_number' => (string) $o['order_number'],
            'status' => (string) $o['status'],
            'currency' => (string) $o['currency'],
            'subtotal' => (float) $o['subtotal'],
            'tax_total' => (float) $o['tax_total'],
            'shipping_total' => (float) $o['shipping_total'],
            'grand_total' => (float) $o['grand_total'],
            'customer_note' => $o['customer_note'] !== null ? (string) $o['customer_note'] : null,
            'internal_note' => $o['internal_note'] !== null ? (string) $o['internal_note'] : null,
            'created_at' => (string) $o['created_at'],
            'customer' => [
                'email' => (string) ($o['customer_email'] ?? ''),
                'first_name' => (string) ($o['customer_first_name'] ?? ''),
                'last_name' => (string) ($o['customer_last_name'] ?? ''),
            ],
            'items' => $items,
        ];
    }
}
