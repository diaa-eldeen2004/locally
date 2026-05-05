<?php

declare(strict_types=1);

namespace Locally\Repository;

use DateTimeImmutable;
use PDO;

final class AnalyticsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $properties
     *
     * @throws \PDOException
     */
    public function insert(
        ?int $userId,
        ?string $sessionId,
        string $eventName,
        ?string $entityType,
        ?int $entityId,
        ?array $properties,
    ): void {
        $propsJson = null;
        if ($properties !== null && $properties !== []) {
            try {
                $propsJson = json_encode($properties, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $propsJson = null;
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO analytics_events (user_id, session_id, event_name, entity_type, entity_id, properties)
             VALUES (:uid, :sid, :ename, :etype, :eid, :props)'
        );
        $stmt->execute([
            'uid' => $userId,
            'sid' => $sessionId !== null && $sessionId !== '' ? substr($sessionId, 0, 64) : null,
            'ename' => $eventName,
            'etype' => $entityType,
            'eid' => $entityId,
            'props' => $propsJson,
        ]);
    }

    /**
     * @return list<array{event_name: string, count: int}>
     */
    public function countsByEventSince(DateTimeImmutable $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_name, COUNT(*) AS c
             FROM analytics_events
             WHERE created_at >= :since
             GROUP BY event_name
             ORDER BY c DESC, event_name ASC'
        );
        $stmt->execute(['since' => $since->format('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'event_name' => (string) ($row['event_name'] ?? ''),
                'count' => (int) ($row['c'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit): array
    {
        $limit = min(100, max(1, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, event_name, entity_type, entity_id, created_at
             FROM analytics_events
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }
}
