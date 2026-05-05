<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, slug, description, sort_order
             FROM categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, name ASC'
        );
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findIdBySlug(string $slug): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE slug = :slug AND is_active = 1 LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllAdmin(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, parent_id, name, slug, description, sort_order, is_active
             FROM categories
             ORDER BY sort_order ASC, name ASC'
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, parent_id, name, slug, description, sort_order, is_active
             FROM categories WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function slugTaken(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null && $excludeId > 0) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM categories WHERE slug = :s AND id <> :id LIMIT 1');
            $stmt->execute(['s' => $slug, 'id' => $excludeId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM categories WHERE slug = :s LIMIT 1');
            $stmt->execute(['s' => $slug]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @throws \PDOException
     */
    public function insert(
        string $name,
        string $slug,
        ?string $description,
        ?int $parentId,
        int $sortOrder,
        bool $isActive,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (parent_id, name, slug, description, sort_order, is_active)
             VALUES (:pid, :name, :slug, :desc, :sort, :active)'
        );
        $stmt->execute([
            'pid' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'desc' => $description,
            'sort' => $sortOrder,
            'active' => $isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{name?:string, slug?:string, description?:string|null, parent_id?:int|null, sort_order?:int, is_active?:bool} $patch
     *
     * @throws \PDOException
     */
    public function update(int $id, array $patch): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid category id.');
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
        if (array_key_exists('parent_id', $patch)) {
            $sets[] = 'parent_id = :parent_id';
            $params['parent_id'] = $patch['parent_id'];
        }
        if (array_key_exists('sort_order', $patch) && is_int($patch['sort_order'])) {
            $sets[] = 'sort_order = :sort_order';
            $params['sort_order'] = $patch['sort_order'];
        }
        if (array_key_exists('is_active', $patch) && is_bool($patch['is_active'])) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = $patch['is_active'] ? 1 : 0;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE categories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
