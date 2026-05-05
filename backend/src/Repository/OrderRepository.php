<?php

declare(strict_types=1);

namespace Locally\Repository;

use Locally\Domain\CheckoutException;
use PDO;
use PDOException;

final class OrderRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CartRepository $carts,
    ) {
    }

    /**
     * Reserves inventory at checkout: decrements variant stock, creates `pending_approval` order, clears cart.
     * Confirmer **approve** keeps stock reserved; **reject** restores stock from line items.
     *
     * @return array<string, mixed>
     */
    public function createFromCart(int $userId, ?array $shippingAddress, ?string $customerNote): array
    {
        $customerNote = $customerNote !== null ? substr(trim($customerNote), 0, 500) : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE user_id = :u LIMIT 1 FOR UPDATE');
            $stmt->execute(['u' => $userId]);
            $cartId = $stmt->fetchColumn();
            if ($cartId === false) {
                throw new CheckoutException('EMPTY_CART', 422, 'Your cart is empty.');
            }
            $cartId = (int) $cartId;

            $linesStmt = $this->pdo->prepare(
                'SELECT ci.variant_id, ci.quantity, pv.product_id, pv.size, pv.color, p.name AS product_name,
                        (IF(p.discount_price IS NOT NULL AND p.discount_price < p.price, p.discount_price, p.price) + pv.price_adjustment) AS unit_price
                 FROM cart_items ci
                 INNER JOIN product_variants pv ON pv.id = ci.variant_id
                 INNER JOIN products p ON p.id = pv.product_id
                 WHERE ci.cart_id = :cid
                 ORDER BY ci.variant_id ASC'
            );
            $linesStmt->execute(['cid' => $cartId]);
            $lines = $linesStmt->fetchAll();
            if (!is_array($lines) || $lines === []) {
                throw new CheckoutException('EMPTY_CART', 422, 'Your cart is empty.');
            }

            $locked = [];
            foreach ($lines as $line) {
                $vid = (int) $line['variant_id'];
                $lock = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id LIMIT 1 FOR UPDATE');
                $lock->execute(['id' => $vid]);
                $stock = $lock->fetchColumn();
                if ($stock === false) {
                    throw new CheckoutException('INVALID_VARIANT', 422, 'A product in your cart is no longer available.');
                }
                $stock = (int) $stock;
                $qty = (int) $line['quantity'];
                if ($qty <= 0) {
                    throw new CheckoutException('INVALID_CART', 422, 'Invalid cart line quantity.');
                }
                if ($qty > $stock) {
                    throw new CheckoutException('INSUFFICIENT_STOCK', 422, 'Not enough stock for one or more items.');
                }
                $locked[] = [
                    'variant_id' => $vid,
                    'quantity' => $qty,
                    'product_id' => (int) $line['product_id'],
                    'size' => (string) $line['size'],
                    'color' => (string) $line['color'],
                    'product_name' => (string) $line['product_name'],
                    'unit_price' => (float) $line['unit_price'],
                ];
            }

            $subtotal = 0.0;
            foreach ($locked as $l) {
                $subtotal += round($l['unit_price'] * $l['quantity'], 2);
            }
            $subtotal = round($subtotal, 2);

            $orderNumber = $this->allocateOrderNumber();
            try {
                $shipJson = $shippingAddress !== null ? json_encode($shippingAddress, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException) {
                throw new CheckoutException('INVALID_ADDRESS', 422, 'shipping_address must be JSON-serializable.');
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO orders (user_id, order_number, status, currency, subtotal, tax_total, shipping_total, grand_total, shipping_address, customer_note)
                 VALUES (:uid, :num, \'pending_approval\', \'USD\', :sub, 0, 0, :grand, :ship, :note)'
            );
            $ins->execute([
                'uid' => $userId,
                'num' => $orderNumber,
                'sub' => $subtotal,
                'grand' => $subtotal,
                'ship' => $shipJson,
                'note' => $customerNote,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_label, unit_price, quantity, line_total)
                 VALUES (:oid, :pid, :vid, :pname, :vlabel, :unit, :qty, :ltotal)'
            );
            $dec = $this->pdo->prepare(
                'UPDATE product_variants SET stock_quantity = stock_quantity - :q WHERE id = :id AND stock_quantity >= :need'
            );

            foreach ($locked as $l) {
                $lineTotal = round($l['unit_price'] * $l['quantity'], 2);
                $dec->execute([
                    'q' => $l['quantity'],
                    'id' => $l['variant_id'],
                    'need' => $l['quantity'],
                ]);
                if ($dec->rowCount() !== 1) {
                    throw new CheckoutException('STOCK_RACE', 409, 'Stock changed while checking out. Please review your cart.');
                }

                $itemStmt->execute([
                    'oid' => $orderId,
                    'pid' => $l['product_id'],
                    'vid' => $l['variant_id'],
                    'pname' => $l['product_name'],
                    'vlabel' => trim($l['color'] . ' / ' . $l['size']),
                    'unit' => $l['unit_price'],
                    'qty' => $l['quantity'],
                    'ltotal' => $lineTotal,
                ]);
            }

            $this->carts->clearCartItems($cartId);

            $hist = $this->pdo->prepare(
                'INSERT INTO order_status_history (order_id, to_status, note, actor_user_id)
                 VALUES (:oid, \'pending_approval\', NULL, :actor)'
            );
            $hist->execute(['oid' => $orderId, 'actor' => $userId]);

            $this->pdo->commit();

            return $this->getOrderForUser($orderId, $userId) ?? ['id' => $orderId, 'order_number' => $orderNumber];
        } catch (CheckoutException $e) {
            $this->pdo->rollBack();
            throw $e;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listForUser(int $userId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :u');
        $count->execute(['u' => $userId]);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT o.*,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
             FROM orders o
             WHERE o.user_id = :u
             ORDER BY o.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * Admin order queue — same data shape as confirmer list (joined customer).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listForAdmin(?string $status, string $search, int $page, int $perPage): array
    {
        return $this->listForConfirmer($status, $search, $page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminDashboardMetrics(): array
    {
        $ordersByStatus = [];
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS c FROM orders GROUP BY status');
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                if (is_array($row) && isset($row['status'])) {
                    $ordersByStatus[(string) $row['status']] = (int) $row['c'];
                }
            }
        }

        $revStmt = $this->pdo->query(
            "SELECT COALESCE(SUM(grand_total), 0) AS rev
             FROM orders
             WHERE status IN ('approved', 'processing', 'shipped', 'delivered')"
        );
        $revenue = 0.0;
        if ($revStmt !== false) {
            $r = $revStmt->fetch();
            if (is_array($r) && isset($r['rev'])) {
                $revenue = (float) $r['rev'];
            }
        }

        $users = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $products = (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $categories = (int) $this->pdo->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();

        $lowStmt = $this->pdo->query(
            'SELECT COUNT(*) FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
             WHERE pv.stock_quantity > 0 AND pv.stock_quantity < 6'
        );
        $lowStockVariants = $lowStmt !== false ? (int) $lowStmt->fetchColumn() : 0;

        return [
            'orders_by_status' => $ordersByStatus,
            'revenue_fulfilled_pipeline_usd' => round($revenue, 2),
            'users_total' => $users,
            'products_total' => $products,
            'categories_active' => $categories,
            'low_stock_variants_under_6' => $lowStockVariants,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderForUser(int $orderId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id AND user_id = :u LIMIT 1');
        $stmt->execute(['id' => $orderId, 'u' => $userId]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            return null;
        }

        $items = $this->pdo->prepare(
            'SELECT id, product_id, variant_id, product_name, variant_label, unit_price, quantity, line_total
             FROM order_items WHERE order_id = :oid ORDER BY id ASC'
        );
        $items->execute(['oid' => $orderId]);
        $lines = $items->fetchAll();

        $order['items'] = is_array($lines) ? $lines : [];

        return $order;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listForConfirmer(?string $status, string $search, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];
        if ($status !== null && $status !== '' && strtolower($status) !== 'all') {
            $where[] = 'o.status = :st';
            $params['st'] = $status;
        }
        if ($search !== '') {
            $where[] = '(u.email LIKE :q OR u.first_name LIKE :qfn OR u.last_name LIKE :qln OR o.order_number LIKE :qon OR CAST(o.id AS CHAR) LIKE :qid)';
            $like = '%' . $search . '%';
            $params['q'] = $like;
            $params['qfn'] = $like;
            $params['qln'] = $like;
            $params['qon'] = $like;
            $params['qid'] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM orders o INNER JOIN users u ON u.id = o.user_id WHERE {$whereSql}";
        $cstmt = $this->pdo->prepare($countSql);
        $cstmt->execute($params);
        $total = (int) $cstmt->fetchColumn();

        $sql = "SELECT o.*, u.email AS customer_email, u.first_name AS customer_first_name, u.last_name AS customer_last_name,
                       (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
                FROM orders o
                INNER JOIN users u ON u.id = o.user_id
                WHERE {$whereSql}
                ORDER BY o.created_at DESC
                LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderForStaff(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, u.email AS customer_email, u.first_name AS customer_first_name, u.last_name AS customer_last_name
             FROM orders o
             INNER JOIN users u ON u.id = o.user_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!is_array($order)) {
            return null;
        }

        $items = $this->pdo->prepare(
            'SELECT id, product_id, variant_id, product_name, variant_label, unit_price, quantity, line_total
             FROM order_items WHERE order_id = :oid ORDER BY id ASC'
        );
        $items->execute(['oid' => $orderId]);
        $lines = $items->fetchAll();
        $order['items'] = is_array($lines) ? $lines : [];

        return $order;
    }

    public function approve(int $orderId, int $actorUserId, ?string $note): void
    {
        $note = $note !== null ? substr(trim($note), 0, 500) : null;

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id, status FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
            $lock->execute(['id' => $orderId]);
            $row = $lock->fetch();
            if (!is_array($row)) {
                throw new CheckoutException('NOT_FOUND', 404, 'Order not found.');
            }
            if ((string) $row['status'] !== 'pending_approval') {
                throw new CheckoutException('INVALID_STATE', 409, 'Order is not awaiting approval.');
            }

            $upd = $this->pdo->prepare(
                'UPDATE orders SET status = \'approved\', confirmer_id = :c WHERE id = :id AND status = \'pending_approval\''
            );
            $upd->execute(['c' => $actorUserId, 'id' => $orderId]);
            if ($upd->rowCount() !== 1) {
                throw new CheckoutException('INVALID_STATE', 409, 'Unable to approve this order.');
            }

            $hist = $this->pdo->prepare(
                'INSERT INTO order_status_history (order_id, to_status, note, actor_user_id)
                 VALUES (:oid, \'approved\', :note, :actor)'
            );
            $hist->execute(['oid' => $orderId, 'note' => $note, 'actor' => $actorUserId]);

            $this->pdo->commit();
        } catch (CheckoutException $e) {
            $this->pdo->rollBack();
            throw $e;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function reject(int $orderId, int $actorUserId, ?string $reason): void
    {
        $reason = $reason !== null ? substr(trim($reason), 0, 500) : null;

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id, status FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
            $lock->execute(['id' => $orderId]);
            $row = $lock->fetch();
            if (!is_array($row)) {
                throw new CheckoutException('NOT_FOUND', 404, 'Order not found.');
            }
            if ((string) $row['status'] !== 'pending_approval') {
                throw new CheckoutException('INVALID_STATE', 409, 'Order is not awaiting approval.');
            }

            $items = $this->pdo->prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = :oid FOR UPDATE');
            $items->execute(['oid' => $orderId]);
            $lines = $items->fetchAll();
            if (!is_array($lines)) {
                $lines = [];
            }

            $restore = $this->pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + :q WHERE id = :id');
            foreach ($lines as $line) {
                $vid = (int) $line['variant_id'];
                if ($vid <= 0) {
                    continue;
                }
                $restore->execute(['q' => (int) $line['quantity'], 'id' => $vid]);
            }

            $upd = $this->pdo->prepare(
                'UPDATE orders SET status = \'rejected\', confirmer_id = :c, internal_note = :note WHERE id = :id AND status = \'pending_approval\''
            );
            $upd->execute([
                'c' => $actorUserId,
                'note' => $reason,
                'id' => $orderId,
            ]);
            if ($upd->rowCount() !== 1) {
                throw new CheckoutException('INVALID_STATE', 409, 'Unable to reject this order.');
            }

            $hist = $this->pdo->prepare(
                'INSERT INTO order_status_history (order_id, to_status, note, actor_user_id)
                 VALUES (:oid, \'rejected\', :note, :actor)'
            );
            $hist->execute(['oid' => $orderId, 'note' => $reason, 'actor' => $actorUserId]);

            $this->pdo->commit();
        } catch (CheckoutException $e) {
            $this->pdo->rollBack();
            throw $e;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function allocateOrderNumber(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $num = 'LC-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $chk = $this->pdo->prepare('SELECT 1 FROM orders WHERE order_number = :n LIMIT 1');
            $chk->execute(['n' => $num]);
            if (!$chk->fetchColumn()) {
                return $num;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique order number.');
    }
}
