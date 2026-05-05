<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;
use PDOException;

final class HomepageSectionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT hs.id, hs.title, hs.category_id, hs.display_order, hs.is_active,
                    c.slug AS category_slug, c.name AS category_name
             FROM homepage_sections hs
             LEFT JOIN categories c ON c.id = hs.category_id
             ORDER BY hs.display_order ASC, hs.id ASC'
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<int> $orderedIds
     *
     * @throws PDOException
     */
    public function reorder(array $orderedIds): void
    {
        $orderedIds = array_values(array_filter(array_map('intval', $orderedIds), static fn (int $v): bool => $v > 0));
        if ($orderedIds === []) {
            throw new \InvalidArgumentException('ids must be a non-empty list of section ids.');
        }

        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $chk = $this->pdo->prepare("SELECT COUNT(*) FROM homepage_sections WHERE id IN ({$placeholders})");
        $chk->execute($orderedIds);
        $found = (int) $chk->fetchColumn();
        if ($found !== count($orderedIds)) {
            throw new \InvalidArgumentException('One or more homepage section ids are invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $ord = 0;
            $stmt = $this->pdo->prepare('UPDATE homepage_sections SET display_order = :ord WHERE id = :id');
            foreach ($orderedIds as $id) {
                $ord += 10;
                $stmt->execute(['ord' => $ord, 'id' => $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
