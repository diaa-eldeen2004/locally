<?php

declare(strict_types=1);

namespace Locally\Repository;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function roleIdBySlug(string $slug): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        return (int) $id;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{email:string,password_hash:string,first_name:string,last_name:string,role_id:int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (role_id, email, password_hash, first_name, last_name, is_active)
             VALUES (:role_id, :email, :password_hash, :first_name, :last_name, 1)'
        );
        $stmt->execute([
            'role_id' => $data['role_id'],
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.password_hash, u.first_name, u.last_name, u.is_active, r.slug AS role_slug
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findByIdWithRole(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.role_id, u.first_name, u.last_name, u.is_active, u.theme_preference,
                    u.created_at, u.last_login_at, r.slug AS role_slug, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function roleSlugById(int $roleId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT slug FROM roles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $roleId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return null;
        }

        return (string) $v;
    }

    public function countActiveAdminsExcluding(int $excludeUserId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.slug = :admin AND u.is_active = 1 AND u.id <> :ex'
        );
        $stmt->execute(['admin' => 'admin', 'ex' => $excludeUserId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listForAdmin(string $search, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (u.email LIKE :q OR u.first_name LIKE :q2 OR u.last_name LIKE :q3 OR r.slug LIKE :q4)';
            $like = '%' . $search . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT u.id, u.email, u.role_id, u.first_name, u.last_name, u.is_active, u.theme_preference,
                       u.created_at, u.last_login_at, r.slug AS role_slug, r.name AS role_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE {$where}
                ORDER BY u.id DESC
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
     * @param array{role_id?: int, is_active?: bool, theme_preference?: string} $patch
     */
    public function updateAdminFields(int $id, array $patch): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid user id.');
        }

        $sets = [];
        $params = ['id' => $id];
        if (isset($patch['role_id']) && is_int($patch['role_id'])) {
            $sets[] = 'role_id = :role_id';
            $params['role_id'] = $patch['role_id'];
        }
        if (array_key_exists('is_active', $patch) && is_bool($patch['is_active'])) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = $patch['is_active'] ? 1 : 0;
        }
        if (isset($patch['theme_preference']) && is_string($patch['theme_preference'])) {
            $sets[] = 'theme_preference = :theme';
            $params['theme'] = $patch['theme_preference'];
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function shapeAdminUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'is_active' => (bool) ((int) ($row['is_active'] ?? 0)),
            'theme_preference' => (string) ($row['theme_preference'] ?? 'system'),
            'role_id' => (int) ($row['role_id'] ?? 0),
            'role_slug' => (string) ($row['role_slug'] ?? ''),
            'role_name' => (string) ($row['role_name'] ?? ''),
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'last_login_at' => isset($row['last_login_at']) && $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
        ];
    }

    public function touchLastLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }
}
