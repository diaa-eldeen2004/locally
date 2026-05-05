<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;

final class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Effective unit price expression (MySQL).
     */
    private static function effectivePriceSql(): string
    {
        return 'IF(p.discount_price IS NOT NULL AND p.discount_price < p.price, p.discount_price, p.price)';
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listPaginated(
        int $page,
        int $perPage,
        ?string $categorySlug,
        ?string $search,
        string $sort,
    ): array {
        $page = max(1, $page);
        $perPage = min(48, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['p.category_id IN (SELECT id FROM categories WHERE is_active = 1)'];
        $params = [];

        if ($categorySlug !== null && $categorySlug !== '') {
            $where[] = 'c.slug = :category_slug';
            $params['category_slug'] = $categorySlug;
        }

        if ($search !== null && $search !== '') {
            $where[] = '(p.name LIKE :q OR p.description LIKE :q2)';
            $params['q'] = '%' . $search . '%';
            $params['q2'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $order = match ($sort) {
            'price_asc' => self::effectivePriceSql() . ' ASC, p.id ASC',
            'price_desc' => self::effectivePriceSql() . ' DESC, p.id DESC',
            default => 'p.created_at DESC, p.id DESC',
        };

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $eff = self::effectivePriceSql();
        $sql = "SELECT p.id, p.name, p.slug, p.price, p.discount_price, {$eff} AS effective_price,
                       p.availability_status, p.is_featured, p.is_trending, p.average_rating, p.review_count,
                       c.slug AS category_slug, c.name AS category_name,
                       (SELECT pi.path FROM product_images pi
                        WHERE pi.product_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                        LIMIT 1) AS image_path
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                WHERE {$whereSql}
                ORDER BY {$order}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $eff = self::effectivePriceSql();
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.slug AS category_slug, c.name AS category_name, {$eff} AS effective_price
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             WHERE p.slug = :slug AND c.is_active = 1
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function imagesForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, path, alt_text, sort_order, is_primary
             FROM product_images
             WHERE product_id = :pid
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->execute(['pid' => $productId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function variantsForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, sku, size, color, stock_quantity, price_adjustment
             FROM product_variants
             WHERE product_id = :pid
             ORDER BY id ASC'
        );
        $stmt->execute(['pid' => $productId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function productsForCategory(int $categoryId, int $limit): array
    {
        $limit = min(24, max(1, $limit));
        $eff = self::effectivePriceSql();
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.price, p.discount_price, {$eff} AS effective_price,
                    p.availability_status, p.is_featured, p.is_trending, p.average_rating, p.review_count,
                    c.slug AS category_slug, c.name AS category_name,
                    (SELECT pi.path FROM product_images pi
                     WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1) AS image_path
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             WHERE p.category_id = :cid AND c.is_active = 1
             ORDER BY p.is_featured DESC, p.is_trending DESC, p.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['cid' => $categoryId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function homepageSections(int $perSection): array
    {
        $stmt = $this->pdo->query(
            'SELECT hs.id, hs.title, hs.display_order, hs.category_id, c.slug AS category_slug, c.name AS category_name
             FROM homepage_sections hs
             LEFT JOIN categories c ON c.id = hs.category_id
             WHERE hs.is_active = 1
             ORDER BY hs.display_order ASC, hs.id ASC'
        );
        $sections = $stmt->fetchAll();
        if (!is_array($sections)) {
            return [];
        }

        $out = [];
        foreach ($sections as $section) {
            $cid = isset($section['category_id']) ? (int) $section['category_id'] : 0;
            $products = $cid > 0 ? $this->productsForCategory($cid, $perSection) : [];
            $out[] = [
                'id' => (int) $section['id'],
                'title' => (string) $section['title'],
                'display_order' => (int) $section['display_order'],
                'category' => $cid > 0 ? [
                    'id' => $cid,
                    'slug' => (string) ($section['category_slug'] ?? ''),
                    'name' => (string) ($section['category_name'] ?? ''),
                ] : null,
                'products' => $products,
            ];
        }

        return $out;
    }

    public function variantStock(int $variantId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $variantId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return null;
        }

        return (int) $v;
    }

    public function variantBelongsToProduct(int $variantId, int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM product_variants WHERE id = :vid AND product_id = :pid LIMIT 1'
        );
        $stmt->execute(['vid' => $variantId, 'pid' => $productId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Approved reviews for storefront PDP.
     *
     * @return list<array<string, mixed>>
     */
    public function approvedReviewsForProduct(int $productId, int $limit): array
    {
        $limit = min(50, max(1, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.rating, r.title, r.body, r.created_at,
                    u.first_name AS author_first_name
             FROM reviews r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.product_id = :pid AND r.is_approved = 1
             ORDER BY r.created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['pid' => $productId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminListPaginated(int $page, int $perPage, ?string $search): array
    {
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = '1=1';
        $params = [];
        if ($search !== null && $search !== '') {
            $where .= ' AND (p.name LIKE :q OR p.slug LIKE :q2 OR p.description LIKE :q3)';
            $like = '%' . $search . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM products p WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $eff = self::effectivePriceSql();
        $sql = "SELECT p.id, p.category_id, p.name, p.slug, p.description, p.price, p.discount_price,
                       {$eff} AS effective_price, p.availability_status, p.is_featured, p.is_trending,
                       p.average_rating, p.review_count, p.created_at,
                       c.name AS category_name, c.slug AS category_slug, c.is_active AS category_is_active
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE {$where}
                ORDER BY p.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForAdmin(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $eff = self::effectivePriceSql();
        $stmt = $this->pdo->prepare(
            "SELECT p.*, {$eff} AS effective_price, c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function slugTaken(string $slug, ?int $excludeProductId = null): bool
    {
        if ($excludeProductId !== null && $excludeProductId > 0) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE slug = :s AND id <> :id LIMIT 1');
            $stmt->execute(['s' => $slug, 'id' => $excludeProductId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE slug = :s LIMIT 1');
            $stmt->execute(['s' => $slug]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   name?:string,
     *   slug?:string,
     *   description?:string|null,
     *   category_id?:int,
     *   price?:float,
     *   discount_price?:float|null,
     *   availability_status?:string,
     *   is_featured?:bool,
     *   is_trending?:bool
     * } $patch
     *
     * @throws \PDOException
     */
    public function updateAdmin(int $id, array $patch): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid product id.');
        }

        $sets = [];
        $params = ['id' => $id];

        if (array_key_exists('name', $patch) && is_string($patch['name'])) {
            $sets[] = 'name = :name';
            $params['name'] = $patch['name'];
        }
        if (array_key_exists('slug', $patch) && is_string($patch['slug'])) {
            $sets[] = 'slug = :slug';
            $params['slug'] = $patch['slug'];
        }
        if (array_key_exists('description', $patch)) {
            $sets[] = 'description = :description';
            $params['description'] = is_string($patch['description']) ? $patch['description'] : null;
        }
        if (array_key_exists('category_id', $patch) && is_int($patch['category_id'])) {
            $sets[] = 'category_id = :category_id';
            $params['category_id'] = $patch['category_id'];
        }
        if (array_key_exists('price', $patch) && is_numeric($patch['price'])) {
            $sets[] = 'price = :price';
            $params['price'] = (float) $patch['price'];
        }
        if (array_key_exists('discount_price', $patch)) {
            $sets[] = 'discount_price = :discount_price';
            $params['discount_price'] = $patch['discount_price'] === null ? null : (is_numeric($patch['discount_price']) ? (float) $patch['discount_price'] : null);
        }
        if (array_key_exists('availability_status', $patch) && is_string($patch['availability_status'])) {
            $sets[] = 'availability_status = :availability_status';
            $params['availability_status'] = $patch['availability_status'];
        }
        if (array_key_exists('is_featured', $patch) && is_bool($patch['is_featured'])) {
            $sets[] = 'is_featured = :is_featured';
            $params['is_featured'] = $patch['is_featured'] ? 1 : 0;
        }
        if (array_key_exists('is_trending', $patch) && is_bool($patch['is_trending'])) {
            $sets[] = 'is_trending = :is_trending';
            $params['is_trending'] = $patch['is_trending'] ? 1 : 0;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Creates a product and a default variant row (required for cart / storefront flows).
     *
     * @throws \PDOException
     */
    public function createWithDefaultVariant(
        int $categoryId,
        string $name,
        string $slug,
        ?string $description,
        float $price,
        ?float $discountPrice,
        string $availabilityStatus,
        bool $isFeatured,
        bool $isTrending,
    ): int {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (category_id, name, slug, description, price, discount_price,
                    availability_status, is_featured, is_trending)
                 VALUES (:cid, :name, :slug, :desc, :price, :dprice, :avail, :feat, :trend)'
            );
            $stmt->execute([
                'cid' => $categoryId,
                'name' => $name,
                'slug' => $slug,
                'desc' => $description,
                'price' => $price,
                'dprice' => $discountPrice,
                'avail' => $availabilityStatus,
                'feat' => $isFeatured ? 1 : 0,
                'trend' => $isTrending ? 1 : 0,
            ]);
            $pid = (int) $this->pdo->lastInsertId();
            $v = $this->pdo->prepare(
                'INSERT INTO product_variants (product_id, sku, size, color, stock_quantity, price_adjustment)
                 VALUES (:pid, NULL, "", "Default", 0, 0.00)'
            );
            $v->execute(['pid' => $pid]);
            $this->pdo->commit();

            return $pid;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function nextImageSortOrder(int $productId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM product_images WHERE product_id = :pid'
        );
        $stmt->execute(['pid' => $productId]);
        $n = $stmt->fetchColumn();

        return (int) $n;
    }

    /**
     * @throws \PDOException
     */
    public function insertProductImage(int $productId, string $webPath, ?string $altText, int $sortOrder, bool $isPrimary): int
    {
        $this->pdo->beginTransaction();
        try {
            if ($isPrimary) {
                $u = $this->pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :pid');
                $u->execute(['pid' => $productId]);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO product_images (product_id, path, alt_text, sort_order, is_primary)
                 VALUES (:pid, :path, :alt, :sort, :prim)'
            );
            $stmt->execute([
                'pid' => $productId,
                'path' => $webPath,
                'alt' => $altText,
                'sort' => $sortOrder,
                'prim' => $isPrimary ? 1 : 0,
            ]);
            $imgId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $imgId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
