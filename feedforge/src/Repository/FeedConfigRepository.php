<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class FeedConfigRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_feed_config';
    }

    /**
     * Find all feed configurations for a shop.
     */
    public function findByShop(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('date_add', 'DESC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Find a feed configuration by its ID, optionally scoped to a shop.
     */
    public function findById(int $id, ?int $shopId = null): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('*')
            ->from($this->table)
            ->where('id_feedforge_feed_config = :id')
            ->setParameter('id', $id);

        if ($shopId !== null) {
            $qb->andWhere('id_shop = :shopId')
                ->setParameter('shopId', $shopId);
        }

        $result = $qb->execute()->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Find all active feed configurations for a shop.
     */
    public function findActive(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('active = 1')
            ->setParameter('shopId', $shopId)
            ->orderBy('date_add', 'DESC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Save a feed configuration (insert or update). Returns the config ID.
     * The $data array must contain 'id_shop' for shop scoping.
     */
    public function save(array $data): int
    {
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (!empty($data['id_feedforge_feed_config'])) {
            $id = (int) $data['id_feedforge_feed_config'];
            $shopId = (int) ($data['id_shop'] ?? 0);
            unset($data['id_feedforge_feed_config']);

            $criteria = ['id_feedforge_feed_config' => $id];
            if ($shopId > 0) {
                $criteria['id_shop'] = $shopId;
            }

            $this->connection->update($this->table, $data, $criteria);

            return $id;
        }

        $data['date_add'] = date('Y-m-d H:i:s');
        $this->connection->insert($this->table, $data);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Delete a feed configuration by ID, scoped to a shop.
     */
    public function delete(int $id, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_feed_config' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $this->connection->delete($this->table, $criteria);
    }

    /**
     * Toggle the active state of a feed configuration, scoped to a shop.
     */
    public function toggleActive(int $id, bool $active, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_feed_config' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $this->connection->update(
            $this->table,
            [
                'active' => (int) $active,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            $criteria
        );
    }

    /**
     * Persist Merchant API DataSource binding for a feed config.
     * Called once per feed when its DataSource is created in Google Merchant Center.
     */
    public function setDataSource(int $id, string $dataSourceId, string $dataSourceName): void
    {
        $this->connection->update(
            $this->table,
            [
                'data_source_id' => $dataSourceId,
                'data_source_name' => $dataSourceName,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            ['id_feedforge_feed_config' => $id]
        );
    }

    /**
     * Find feed configs that have no DataSource bound yet (need DataSource provisioning).
     */
    public function findWithoutDataSource(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('(data_source_id IS NULL OR data_source_id = \'\')')
            ->setParameter('shopId', $shopId)
            ->execute()
            ->fetchAllAssociative();
    }
}
