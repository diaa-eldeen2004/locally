<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\CartRepository;
use JsonException;

final class CartController
{
    public function __construct(
        private readonly CartRepository $carts,
    ) {
    }

    public function get(Request $request): Response
    {
        $cartId = $this->carts->resolveCartId();

        return Response::jsonOk($this->carts->getCartPayload($cartId));
    }

    public function upsertItem(Request $request): Response
    {
        try {
            $body = $request->jsonBody();
        } catch (JsonException) {
            return Response::jsonError(
                ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.'],
                400
            );
        }

        $variantId = isset($body['variant_id']) ? (int) $body['variant_id'] : 0;
        $quantity = isset($body['quantity']) ? (int) $body['quantity'] : 0;
        if ($variantId <= 0) {
            return Response::jsonError(
                ['code' => 'VALIDATION_ERROR', 'message' => 'variant_id is required.'],
                422
            );
        }

        try {
            $cartId = $this->carts->resolveCartId();
            $this->carts->upsertLine($cartId, $variantId, $quantity);
        } catch (\InvalidArgumentException) {
            return Response::jsonError(
                ['code' => 'INVALID_VARIANT', 'message' => 'Unknown product variant.'],
                422
            );
        }

        return Response::jsonOk($this->carts->getCartPayload($cartId));
    }

    public function deleteItem(Request $request): Response
    {
        $raw = $request->query['variant_id'] ?? null;
        $variantId = is_numeric($raw) ? (int) $raw : 0;
        if ($variantId <= 0) {
            return Response::jsonError(
                ['code' => 'VALIDATION_ERROR', 'message' => 'Query variant_id is required.'],
                422
            );
        }

        $cartId = $this->carts->resolveCartId();
        $this->carts->deleteLine($cartId, $variantId);

        return Response::jsonOk($this->carts->getCartPayload($cartId));
    }
}
