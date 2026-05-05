<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;
use PDOException;

final class ReviewRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function userHasReview(int $userId, int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM reviews WHERE user_id = :u AND product_id = :p LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'p' => $productId]);

        return (bool) $stmt->fetchColumn();
    }

    public function productExists(int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM products p
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @throws PDOException
     */
    public function insert(int $userId, int $productId, int $rating, ?string $title, ?string $body): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reviews (user_id, product_id, rating, title, body, is_approved)
             VALUES (:u, :p, :r, :t, :b, 1)'
        );
        $stmt->execute([
            'u' => $userId,
            'p' => $productId,
            'r' => $rating,
            't' => $title,
            'b' => $body,
        ]);
    }

    public function refreshProductStats(int $productId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products p
             SET p.average_rating = COALESCE(
                   (SELECT AVG(r.rating) FROM reviews r WHERE r.product_id = p.id AND r.is_approved = 1),
                   0
                 ),
                 p.review_count = (
                   SELECT COUNT(*) FROM reviews r2 WHERE r2.product_id = p.id AND r2.is_approved = 1
                 )
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $productId]);
    }
}
