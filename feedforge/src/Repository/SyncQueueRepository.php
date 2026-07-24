<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class SyncQueueRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_sync_queue';
    }

    /**
     * Add an item to the sync queue.
     */
    public function enqueue(int $productId, int $attributeId, int $shopId, string $action, int $priority = 0): void
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->insert($this->table, [
            'id_product' => $productId,
            'id_product_attribute' => $attributeId,
            'id_shop' => $shopId,
            'action' => $action,
            'priority' => $priority,
            'status' => 'pending',
            'scheduled_at' => $now,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    /**
     * Fetch a batch of pending items, ordered by priority (desc) then scheduled time (asc).
     */
    public function fetchBatch(int $shopId, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('status = :status')
            ->setParameter('shopId', $shopId)
            ->setParameter('status', 'pending')
            ->orderBy('priority', 'DESC')
            ->addOrderBy('scheduled_at', 'ASC')
            ->setMaxResults($limit)
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Mark a set of queue items as processing, scoped to a shop.
     */
    public function markProcessing(array $ids, ?int $shopId = null): void
    {
        if (empty($ids)) {
            return;
        }

        $qb = $this->connection->createQueryBuilder();

        $qb->update($this->table)
            ->set('status', ':status')
            ->set('started_at', ':now')
            ->set('date_upd', ':now')
            ->where($qb->expr()->in('id_feedforge_sync_queue', ':ids'))
            ->setParameter('status', 'processing')
            ->setParameter('now', date('Y-m-d H:i:s'))
            ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY);

        if ($shopId !== null) {
            $qb->andWhere('id_shop = :shopId')
                ->setParameter('shopId', $shopId);
        }

        $qb->execute();
    }

    /**
     * Release items back to pending state (called when the worker ran out of
     * time mid-batch and didn't actually process the claimed items).
     */
    public function releaseProcessing(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $qb = $this->connection->createQueryBuilder();

        $qb->update($this->table)
            ->set('status', ':status')
            ->set('started_at', ':null')
            ->set('date_upd', ':now')
            ->where($qb->expr()->in('id_feedforge_sync_queue', ':ids'))
            ->andWhere('status = :processing')
            ->setParameter('status', 'pending')
            ->setParameter('null', null)
            ->setParameter('now', date('Y-m-d H:i:s'))
            ->setParameter('processing', 'processing')
            ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY);

        $qb->execute();
    }

    /**
     * Mark a queue item as completed, scoped to a shop.
     */
    public function markCompleted(int $id, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_sync_queue' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->update(
            $this->table,
            [
                'status' => 'completed',
                'completed_at' => $now,
                'date_upd' => $now,
            ],
            $criteria
        );
    }

    /**
     * Mark a queue item as failed with an error message, scoped to a shop.
     */
    public function markFailed(int $id, string $errorMessage, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_sync_queue' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $now = date('Y-m-d H:i:s');
        $this->connection->update(
            $this->table,
            [
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => $now,
                'date_upd' => $now,
            ],
            $criteria
        );
    }

    /**
     * Reset a single failed item back to pending for retry.
     */
    public function retrySingle(int $id, int $shopId): bool
    {
        $now = date('Y-m-d H:i:s');
        $affected = (int) $this->connection->executeStatement(
            "UPDATE {$this->table} SET status = 'pending', error_message = NULL, started_at = NULL, completed_at = NULL, scheduled_at = :now, date_upd = :now WHERE id_feedforge_sync_queue = :id AND id_shop = :shopId AND status = 'failed'",
            ['id' => $id, 'shopId' => $shopId, 'now' => $now]
        );

        return $affected > 0;
    }

    /**
     * Reset all failed items back to pending for retry. Returns the count of retried items.
     */
    public function retryFailed(int $shopId): int
    {
        $qb = $this->connection->createQueryBuilder();

        return (int) $qb->update($this->table)
            ->set('status', ':newStatus')
            ->set('error_message', 'NULL')
            ->set('started_at', 'NULL')
            ->set('completed_at', 'NULL')
            ->set('scheduled_at', ':now')
            ->set('date_upd', ':now')
            ->where('id_shop = :shopId')
            ->andWhere('status = :oldStatus')
            ->setParameter('newStatus', 'pending')
            ->setParameter('now', date('Y-m-d H:i:s'))
            ->setParameter('shopId', $shopId)
            ->setParameter('oldStatus', 'failed')
            ->execute();
    }

    /**
     * Remove all completed items for a shop. Returns the count of cleared items.
     */
    public function clearCompleted(int $shopId): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE id_shop = :shopId AND status = :status",
            ['shopId' => $shopId, 'status' => 'completed']
        );
    }

    /**
     * Remove all failed items for a shop. Returns the count of cleared items.
     */
    public function clearFailed(int $shopId): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE id_shop = :shopId AND status = :status",
            ['shopId' => $shopId, 'status' => 'failed']
        );
    }

    /**
     * Get queue statistics: counts by status.
     */
    public function getStats(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        $rows = $qb->select('status', 'COUNT(*) AS total')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->groupBy('status')
            ->execute()
            ->fetchAllAssociative();

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * Count queue items matching optional filters.
     */
    public function countFiltered(int $shopId, array $filters = []): int
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('COUNT(*)')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId);

        if (!empty($filters['status'])) {
            $qb->andWhere('status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['action'])) {
            $qb->andWhere('action = :action')
                ->setParameter('action', $filters['action']);
        }

        return (int) $qb->execute()->fetchOne();
    }

    /**
     * List queue items with pagination and optional filters.
     */
    public function listPaginated(int $shopId, int $page, int $perPage, array $filters = []): array
    {
        $prefix = _DB_PREFIX_;
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');
        $qb = $this->connection->createQueryBuilder();

        $qb->select('q.*', 'pl.name AS ps_name')
            ->from($this->table, 'q')
            ->leftJoin('q', $prefix . 'product_lang', 'pl',
                'pl.id_product = q.id_product AND pl.id_lang = :langId AND pl.id_shop = q.id_shop')
            ->where('q.id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->setParameter('langId', $langId)
            ->orderBy('q.date_add', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (!empty($filters['status'])) {
            $qb->andWhere('q.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['action'])) {
            $qb->andWhere('q.action = :action')
                ->setParameter('action', $filters['action']);
        }

        return $qb->execute()->fetchAllAssociative();
    }
}
