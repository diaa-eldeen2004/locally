<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;
use PDOException;

final class CartRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function mergeGuestIntoUser(int $userId, ?string $guestKey): void
    {
        if ($guestKey === null || $guestKey === '') {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $guestCartId = $this->findCartIdByGuestKeyForUpdate($guestKey);
            if ($guestCartId === null) {
                $this->pdo->commit();
                unset($_SESSION['cart_guest_key']);

                return;
            }

            $userCartId = $this->getOrCreateUserCartIdLocked($userId);

            $stmt = $this->pdo->prepare('SELECT variant_id, quantity FROM cart_items WHERE cart_id = :cid FOR UPDATE');
            $stmt->execute(['cid' => $guestCartId]);
            $items = $stmt->fetchAll();
            if (is_array($items)) {
                foreach ($items as $it) {
                    $variantId = (int) $it['variant_id'];
                    $guestQty = (int) $it['quantity'];
                    $stock = $this->variantStockLocked($variantId);
                    if ($stock === null) {
                        continue;
                    }
                    $guestQty = min($guestQty, max(0, $stock));
                    if ($guestQty <= 0) {
                        continue;
                    }
                    $existing = $this->lineQuantityLocked($userCartId, $variantId);
                    $merged = min($existing + $guestQty, $stock);
                    $this->upsertAbsoluteLocked($userCartId, $variantId, $merged);
                }
            }

            $this->pdo->prepare('DELETE FROM carts WHERE id = :id')->execute(['id' => $guestCartId]);
            $this->pdo->commit();
            unset($_SESSION['cart_guest_key']);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resolveCartId(): int
    {
        $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($uid > 0) {
            return $this->getOrCreateUserCartId($uid);
        }

        return $this->getOrCreateGuestCartId($this->ensureGuestKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getCartPayload(int $cartId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ci.id AS line_id, ci.variant_id, ci.quantity,
                    pv.size, pv.color, pv.sku, pv.stock_quantity,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    p.price AS base_price, p.discount_price,
                    (IF(p.discount_price IS NOT NULL AND p.discount_price < p.price, p.discount_price, p.price) + pv.price_adjustment) AS unit_price,
                    (SELECT pi.path FROM product_images pi
                     WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                     LIMIT 1) AS image_path
             FROM cart_items ci
             INNER JOIN product_variants pv ON pv.id = ci.variant_id
             INNER JOIN products p ON p.id = pv.product_id
             WHERE ci.cart_id = :cid
             ORDER BY ci.id ASC"
        );
        $stmt->execute(['cid' => $cartId]);
        $lines = $stmt->fetchAll();
        if (!is_array($lines)) {
            $lines = [];
        }

        $subtotal = 0.0;
        foreach ($lines as &$line) {
            $unit = (float) $line['unit_price'];
            $qty = (int) $line['quantity'];
            $line['unit_price'] = $unit;
            $line['line_total'] = round($unit * $qty, 2);
            $subtotal += $line['line_total'];
        }
        unset($line);

        return [
            'cart_id' => $cartId,
            'currency' => 'USD',
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'item_count' => array_sum(array_map(static fn (array $l): int => (int) $l['quantity'], $lines)),
        ];
    }

    public function upsertLine(int $cartId, int $variantId, int $quantity): void
    {
        $this->pdo->beginTransaction();
        try {
            $stock = $this->variantStockLocked($variantId);
            if ($stock === null) {
                $this->pdo->rollBack();

                throw new \InvalidArgumentException('Variant not found.');
            }

            if ($quantity <= 0) {
                $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :c AND variant_id = :v')
                    ->execute(['c' => $cartId, 'v' => $variantId]);
                $this->pdo->commit();

                return;
            }

            $qty = min($quantity, $stock);
            $this->upsertAbsoluteLocked($cartId, $variantId, $qty);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteLine(int $cartId, int $variantId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :c AND variant_id = :v LIMIT 1');
        $stmt->execute(['c' => $cartId, 'v' => $variantId]);
    }

    public function clearCartItems(int $cartId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid');
        $stmt->execute(['cid' => $cartId]);
    }

    private function ensureGuestKey(): string
    {
        $k = $_SESSION['cart_guest_key'] ?? null;
        if (is_string($k) && strlen($k) === 64) {
            return $k;
        }
        $k = bin2hex(random_bytes(32));
        $_SESSION['cart_guest_key'] = $k;

        return $k;
    }

    private function getOrCreateUserCartId(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE user_id = :u LIMIT 1');
        $stmt->execute(['u' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        try {
            $ins = $this->pdo->prepare('INSERT INTO carts (user_id, guest_key, currency) VALUES (:u, NULL, :cur)');
            $ins->execute(['u' => $userId, 'cur' => 'USD']);
        } catch (PDOException) {
            $stmt->execute(['u' => $userId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
            throw new \RuntimeException('Unable to create user cart.');
        }

        return (int) $this->pdo->lastInsertId();
    }

    private function getOrCreateGuestCartId(string $guestKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE guest_key = :g LIMIT 1');
        $stmt->execute(['g' => $guestKey]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ins = $this->pdo->prepare('INSERT INTO carts (user_id, guest_key, currency) VALUES (NULL, :g, :cur)');
        $ins->execute(['g' => $guestKey, 'cur' => 'USD']);

        return (int) $this->pdo->lastInsertId();
    }

    private function findCartIdByGuestKeyForUpdate(string $guestKey): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE guest_key = :g LIMIT 1 FOR UPDATE');
        $stmt->execute(['g' => $guestKey]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    private function getOrCreateUserCartIdLocked(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE user_id = :u LIMIT 1 FOR UPDATE');
        $stmt->execute(['u' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ins = $this->pdo->prepare('INSERT INTO carts (user_id, guest_key, currency) VALUES (:u, NULL, :cur)');
        $ins->execute(['u' => $userId, 'cur' => 'USD']);

        return (int) $this->pdo->lastInsertId();
    }

    private function variantStockLocked(int $variantId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $variantId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return null;
        }

        return (int) $v;
    }

    private function lineQuantityLocked(int $cartId, int $variantId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT quantity FROM cart_items WHERE cart_id = :c AND variant_id = :v LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['c' => $cartId, 'v' => $variantId]);
        $q = $stmt->fetchColumn();
        if ($q === false) {
            return 0;
        }

        return (int) $q;
    }

    private function upsertAbsoluteLocked(int $cartId, int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :c AND variant_id = :v')
                ->execute(['c' => $cartId, 'v' => $variantId]);

            return;
        }

        $sql = 'INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (:c, :v, :q)
                ON DUPLICATE KEY UPDATE quantity = :q2, updated_at = CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([
            'c' => $cartId,
            'v' => $variantId,
            'q' => $quantity,
            'q2' => $quantity,
        ]);
    }
}
