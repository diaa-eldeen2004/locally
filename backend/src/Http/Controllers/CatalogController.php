<?php

declare(strict_types=1);

namespace Locally\Http\Controllers;

use Locally\Auth\Access;
use Locally\Http\Request;
use Locally\Http\Response;
use Locally\Repository\CategoryRepository;
use Locally\Repository\FavoriteRepository;
use Locally\Repository\ProductRepository;

final class CatalogController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly FavoriteRepository $favorites,
    ) {
    }

    public function categories(Request $request): Response
    {
        $rows = $this->categories->listActive();
        $out = array_map(fn (array $r): array => $this->shapeCategory($r), $rows);

        return Response::jsonOk(['categories' => $out]);
    }

    public function products(Request $request): Response
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(48, max(1, (int) ($request->query['per_page'] ?? 24)));
        $category = isset($request->query['category']) && is_string($request->query['category'])
            ? trim($request->query['category'])
            : null;
        if ($category === '') {
            $category = null;
        }

        $q = isset($request->query['q']) && is_string($request->query['q']) ? trim($request->query['q']) : null;
        if ($q === '') {
            $q = null;
        }

        $sort = isset($request->query['sort']) && is_string($request->query['sort'])
            ? trim($request->query['sort'])
            : 'newest';
        if (!in_array($sort, ['newest', 'price_asc', 'price_desc'], true)) {
            $sort = 'newest';
        }

        $result = $this->products->listPaginated($page, $perPage, $category, $q, $sort);
        $items = array_map(fn (array $r): array => $this->shapeProductCard($r), $result['items']);

        return Response::jsonOk([
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $result['total'],
        ]);
    }

    public function product(Request $request, string $slug): Response
    {
        $slug = trim($slug);
        if ($slug === '') {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
        }

        $row = $this->products->findBySlug($slug);
        if ($row === null) {
            return Response::jsonError(['code' => 'NOT_FOUND', 'message' => 'Product not found.'], 404);
        }

        $pid = (int) $row['id'];
        $images = $this->products->imagesForProduct($pid);
        $variants = $this->products->variantsForProduct($pid);
        $reviewRows = $this->products->approvedReviewsForProduct($pid, 24);
        $uid = Access::userId();
        $isFavorite = $uid !== null && $this->favorites->userHasFavorite($uid, $pid);

        return Response::jsonOk([
            'product' => $this->shapeProductDetail($row),
            'images' => array_map(fn (array $i): array => [
                'id' => (int) $i['id'],
                'path' => (string) $i['path'],
                'alt_text' => $i['alt_text'] !== null ? (string) $i['alt_text'] : null,
                'sort_order' => (int) $i['sort_order'],
                'is_primary' => (bool) (int) $i['is_primary'],
            ], $images),
            'variants' => array_map(fn (array $v): array => [
                'id' => (int) $v['id'],
                'sku' => $v['sku'] !== null ? (string) $v['sku'] : null,
                'size' => (string) $v['size'],
                'color' => (string) $v['color'],
                'stock_quantity' => (int) $v['stock_quantity'],
                'price_adjustment' => (float) $v['price_adjustment'],
            ], $variants),
            'reviews' => array_map(fn (array $r): array => [
                'id' => (int) $r['id'],
                'rating' => (int) $r['rating'],
                'title' => $r['title'] !== null ? (string) $r['title'] : null,
                'body' => $r['body'] !== null ? (string) $r['body'] : null,
                'created_at' => (string) $r['created_at'],
                'author' => [
                    'first_name' => (string) ($r['author_first_name'] ?? ''),
                ],
            ], $reviewRows),
            'is_favorite' => $isFavorite,
        ]);
    }

    public function homepage(Request $request): Response
    {
        $sections = $this->products->homepageSections(8);
        foreach ($sections as &$section) {
            $section['products'] = array_map(
                fn (array $p): array => $this->shapeProductCard($p),
                $section['products']
            );
        }
        unset($section);

        return Response::jsonOk(['sections' => $sections]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shapeCategory(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'description' => $r['description'] !== null ? (string) $r['description'] : null,
            'sort_order' => (int) $r['sort_order'],
        ];
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

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function shapeProductDetail(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'slug' => (string) $p['slug'],
            'description' => $p['description'] !== null ? (string) $p['description'] : null,
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
        ];
    }
}
