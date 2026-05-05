<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\FavoriteRepository;
use JsonException;
use PDOException;

final class FavoriteController
{
    public function __construct(
        private readonly FavoriteRepository $favorites,
    ) {
    }

    public function list(Request $request): Response
    {
        $block = Access::ensureAuth();
        if ($block !== null) {
            return $block;
        }

        $uid = Access::userId() ?? 0;
        $rows = $this->favorites->listCardsForUser($uid);
        $items = array_map(fn (array $r): array => $this->shapeProductCard($r), $rows);

        return Response::jsonOk([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function add(Request $request): Response
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

        $pid = isset($body['product_id']) ? (int) $body['product_id'] : 0;
        if ($pid <= 0) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'product_id is required.'], 422);
        }

        if (!$this->favorites->productExistsActive($pid)) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
        }

        $uid = Access::userId() ?? 0;
        if ($this->favorites->userHasFavorite($uid, $pid)) {
            return Response::jsonError(['code' => 'ALREADY_FAVORITE', 'message' => 'Already in your favorites.'], 409);
        }

        try {
            $this->favorites->add($uid, $pid);
        } catch (PDOException) {
            return Response::jsonError(['code' => 'FAVORITE_FAILED', 'message' => 'Could not save favorite.'], 500);
        }

        return Response::jsonOk(['product_id' => $pid, 'saved' => true], 201);
    }

    public function remove(Request $request): Response
    {
        $block = Access::ensureAuth();
        if ($block !== null) {
            return $block;
        }

        $raw = isset($request->query['product_id']) ? (string) $request->query['product_id'] : '';
        $pid = ctype_digit($raw) ? (int) $raw : 0;
        if ($pid <= 0) {
            return Response::jsonError(['code' => 'VALIDATION_ERROR', 'message' => 'product_id query parameter is required.'], 422);
        }

        $uid = Access::userId() ?? 0;
        $n = $this->favorites->remove($uid, $pid);
        if ($n === 0) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Favorite not found.'], 404);
        }

        return Response::jsonOk(['product_id' => $pid, 'removed' => true]);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function shapeProductCard(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'slug' => (string) $p['slug'],
            'price' => (float) $p['price'],
            'discount_price' => $p['discount_price'] !== null ? (float) $p['discount_price'] : null,
            'effective_price' => (float) $p['effective_price'],
            'availability_status' => (string) $p['availability_status'],
            'is_featured' => (bool) (int) $p['is_featured'],
            'is_trending' => (bool) (int) $p['is_trending'],
            'average_rating' => (float) $p['average_rating'],
            'review_count' => (int) $p['review_count'],
            'category' => [
                'slug' => (string) $p['category_slug'],
                'name' => (string) $p['category_name'],
            ],
            'image_path' => isset($p['image_path']) && $p['image_path'] !== null ? (string) $p['image_path'] : null,
        ];
    }
}
