<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;

final class FavoriteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private static function effectivePriceSql(): string
    {
        return 'IF(p.discount_price IS NOT NULL AND p.discount_price < p.price, p.discount_price, p.price)';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCardsForUser(int $userId): array
    {
        $eff = self::effectivePriceSql();
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.price, p.discount_price, {$eff} AS effective_price,
                    p.availability_status, p.is_featured, p.is_trending, p.average_rating, p.review_count,
                    c.slug AS category_slug, c.name AS category_name,
                    (SELECT pi.path FROM product_images pi
                     WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1) AS image_path
             FROM favorites f
             INNER JOIN products p ON p.id = f.product_id
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             WHERE f.user_id = :uid
             ORDER BY f.created_at DESC"
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function userHasFavorite(int $userId, int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM favorites WHERE user_id = :u AND product_id = :p LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);

        return (bool) $stmt->fetchColumn();
    }

    public function productExistsActive(int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM products p
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);

        return (bool) $stmt->fetchColumn();
    }

    public function add(int $userId, int $productId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO favorites (user_id, product_id) VALUES (:u, :p)'
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);
    }

    public function remove(int $userId, int $productId): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM favorites WHERE user_id = :u AND product_id = :p'
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);

        return $stmt->rowCount();
    }
}
