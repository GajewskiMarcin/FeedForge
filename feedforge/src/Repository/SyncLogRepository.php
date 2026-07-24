<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class SyncLogRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_sync_log';
    }

    /**
     * Create a new sync log entry. Returns the log ID.
     */
    public function create(int $shopId, string $syncType): int
    {
        $this->connection->insert($this->table, [
            'id_shop' => $shopId,
            'sync_type' => $syncType,
            'status' => 'running',
            'started_at' => date('Y-m-d H:i:s'),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Finish a sync log entry with final stats, optionally scoped to a shop.
     */
    public function finish(int $id, array $stats, ?int $shopId = null): void
    {
        $data = [
            'status' => $stats['status'] ?? 'completed',
            'finished_at' => date('Y-m-d H:i:s'),
            'products_processed' => $stats['products_processed'] ?? 0,
            'products_inserted' => $stats['products_inserted'] ?? 0,
            'products_updated' => $stats['products_updated'] ?? 0,
            'products_deleted' => $stats['products_deleted'] ?? 0,
            'products_failed' => $stats['products_failed'] ?? 0,
            'error_summary' => $stats['error_summary'] ?? null,
        ];

        $criteria = ['id_feedforge_sync_log' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $this->connection->update($this->table, $data, $criteria);
    }

    /**
     * Get the most recent sync log for a shop.
     */
    public function getLatest(int $shopId): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('date_add', 'DESC')
            ->setMaxResults(1)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * List sync logs with pagination.
     */
    public function listPaginated(int $shopId, int $page, int $perPage): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('date_add', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->execute()
            ->fetchAllAssociative();
    }
}
