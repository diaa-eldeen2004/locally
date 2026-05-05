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
        $paymentType = isset($body['payment_type']) && is_string($body['payment_type'])
            ? strtolower(trim($body['payment_type']))
            : 'cash';
        $visa = isset($body['visa']) && is_array($body['visa']) ? $body['visa'] : null;
        $note = is_string($body['customer_note'] ?? null) ? $body['customer_note'] : null;

        if ($shipping === null) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'shipping_address is required.'], 422);
        }
        $validationError = $this->validateCheckoutInput($shipping, $paymentType, $visa);
        if ($validationError !== null) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => $validationError], 422);
        }

        // Persist only checkout-safe data (never store full card number/cvv).
        $shipping['payment_type'] = $paymentType;
        if ($paymentType === 'visa' && $visa !== null) {
            $digits = preg_replace('/\D+/', '', (string) ($visa['card_number'] ?? ''));
            $shipping['payment'] = [
                'brand' => 'visa',
                'card_last4' => substr($digits, -4),
                'cardholder_name' => (string) ($visa['cardholder_name'] ?? ''),
                'exp_month' => (int) ($visa['exp_month'] ?? 0),
                'exp_year' => (int) ($visa['exp_year'] ?? 0),
            ];
        }

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

    /**
     * @param array<string, mixed> $shipping
     * @param array<string, mixed>|null $visa
     */
    private function validateCheckoutInput(array $shipping, string $paymentType, ?array $visa): ?string
    {
        $requiredText = [
            'phone_number' => 24,
            'recovery_number' => 24,
            'address_line_1' => 160,
            'city' => 80,
            'state' => 80,
            'postal_code' => 24,
            'country' => 80,
        ];
        foreach ($requiredText as $key => $maxLen) {
            $v = isset($shipping[$key]) && is_string($shipping[$key]) ? trim($shipping[$key]) : '';
            if ($v === '') {
                return $key . ' is required.';
            }
            if (strlen($v) > $maxLen) {
                return $key . ' is too long.';
            }
        }

        if (isset($shipping['address_line_2']) && is_string($shipping['address_line_2']) && strlen(trim($shipping['address_line_2'])) > 160) {
            return 'address_line_2 is too long.';
        }

        if (!in_array($paymentType, ['cash', 'visa'], true)) {
            return 'payment_type must be cash or visa.';
        }
        if ($paymentType === 'cash') {
            return null;
        }

        if ($visa === null) {
            return 'visa details are required when payment_type is visa.';
        }
        $cardholder = isset($visa['cardholder_name']) && is_string($visa['cardholder_name']) ? trim($visa['cardholder_name']) : '';
        $cardNumberRaw = isset($visa['card_number']) && is_string($visa['card_number']) ? $visa['card_number'] : '';
        $cardDigits = preg_replace('/\D+/', '', $cardNumberRaw);
        $expMonth = isset($visa['exp_month']) ? (int) $visa['exp_month'] : 0;
        $expYear = isset($visa['exp_year']) ? (int) $visa['exp_year'] : 0;
        $cvv = isset($visa['cvv']) && is_string($visa['cvv']) ? trim($visa['cvv']) : '';

        if ($cardholder === '' || strlen($cardholder) > 120) {
            return 'cardholder_name is required.';
        }
        if ($cardDigits === null || strlen($cardDigits) < 13 || strlen($cardDigits) > 19 || !$this->passesLuhn($cardDigits)) {
            return 'card_number is invalid.';
        }
        if ($expMonth < 1 || $expMonth > 12) {
            return 'exp_month is invalid.';
        }
        if ($expYear < 2024 || $expYear > 2100) {
            return 'exp_year is invalid.';
        }
        $nowY = (int) gmdate('Y');
        $nowM = (int) gmdate('n');
        if ($expYear < $nowY || ($expYear === $nowY && $expMonth < $nowM)) {
            return 'Card is expired.';
        }
        if (!preg_match('/^\d{3,4}$/', $cvv)) {
            return 'cvv is invalid.';
        }

        return null;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }

        return ($sum % 10) === 0;
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
