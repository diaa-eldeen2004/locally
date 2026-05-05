<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Domain\CheckoutException;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\OrderRepository;
use JsonException;
use PDOException;

final class OrderController
{
    public function __construct(
        private readonly OrderRepository $orders,
    ) {
    }

    public function create(Request $request): Response
    {
        $block = Access::ensureAuth();
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

        $ship = $body['shipping_address'] ?? null;
        $shipping = is_array($ship) ? $ship : null;

        $note = is_string($body['customer_note'] ?? null) ? $body['customer_note'] : null;

        try {
            $order = $this->orders->createFromCart(Access::userId() ?? 0, $shipping, $note);

            return Response::jsonOk(['order' => $this->shapeOrderDetail($order)], 201);
        } catch (CheckoutException $e) {
            return Response::jsonError(
                ['code' => $e->errorCode, 'message' => $e->getMessage()],
                $e->httpStatus
            );
        } catch (PDOException) {
            return Response::jsonError(
                ['code' => 'ORDER_FAILED', 'message' => 'Could not place the order. Please try again.'],
                500
            );
        }
    }

    public function list(Request $request): Response
    {
        $block = Access::ensureAuth();
        if ($block !== null) {
            return $block;
        }

        $page = max(1, (int) ($request->query['page'] ?? 1));
        $per = min(50, max(1, (int) ($request->query['per_page'] ?? 20)));

        $res = $this->orders->listForUser(Access::userId() ?? 0, $page, $per);
        $items = array_map(fn (array $o): array => $this->shapeOrderSummary($o), $res['items']);

        return Response::jsonOk([
            'items' => $items,
            'page' => $page,
            'per_page' => $per,
            'total' => $res['total'],
        ]);
    }

    public function show(Request $request, string $segment): Response
    {
        $block = Access::ensureAuth();
        if ($block !== null) {
            return $block;
        }

        if (!ctype_digit($segment)) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Order not found.'], 404);
        }

        $id = (int) $segment;
        $order = $this->orders->getOrderForUser($id, Access::userId() ?? 0);
        if ($order === null) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Order not found.'], 404);
        }

        return Response::jsonOk(['order' => $this->shapeOrderDetail($order)]);
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function shapeOrderSummary(array $o): array
    {
        return [
            'id' => (int) $o['id'],
            'order_number' => (string) $o['order_number'],
            'status' => (string) $o['status'],
            'grand_total' => (float) $o['grand_total'],
            'currency' => (string) $o['currency'],
            'created_at' => (string) $o['created_at'],
            'line_count' => (int) ($o['line_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function shapeOrderDetail(array $o): array
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

        $ship = $o['shipping_address'] ?? null;
        $decoded = null;
        if (is_string($ship) && $ship !== '') {
            try {
                $decoded = json_decode($ship, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
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
            'shipping_address' => $decoded,
            'customer_note' => $o['customer_note'] !== null ? (string) $o['customer_note'] : null,
            'created_at' => (string) $o['created_at'],
            'items' => $items,
        ];
    }
}
