<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class GmcProductRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_product';
    }

    /**
     * Find a product by its composite key.
     */
    public function findByProduct(int $productId, int $attributeId, int $shopId, int $feedConfigId): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('id_product = :productId')
            ->andWhere('id_product_attribute = :attributeId')
            ->andWhere('id_shop = :shopId')
            ->andWhere('id_feed_config = :feedConfigId')
            ->setParameter('productId', $productId)
            ->setParameter('attributeId', $attributeId)
            ->setParameter('shopId', $shopId)
            ->setParameter('feedConfigId', $feedConfigId)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Retrieve products for a shop with pagination and optional filters.
     */
    public function findByShopPaginated(int $shopId, int $page, int $perPage, array $filters = []): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('date_upd', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applyFilters($qb, $filters);

        return $qb->execute()->fetchAllAssociative();
    }

    /**
     * Count products for a shop, optionally filtered.
     */
    public function countByShop(int $shopId, array $filters = []): int
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('COUNT(*) AS total')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId);

        $this->applyFilters($qb, $filters);

        return (int) $qb->execute()->fetchOne();
    }

    /**
     * Count products grouped by GMC status.
     */
    public function countByStatus(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('gmc_status', 'COUNT(*) AS total')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->groupBy('gmc_status')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Insert or update a product record. Returns the record ID.
     */
    public function upsert(array $data): int
    {
        $existing = null;

        if (
            isset($data['id_product'], $data['id_product_attribute'], $data['id_shop'], $data['id_feed_config'])
        ) {
            $existing = $this->findByProduct(
                (int) $data['id_product'],
                (int) $data['id_product_attribute'],
                (int) $data['id_shop'],
                (int) $data['id_feed_config']
            );
        }

        $data['date_upd'] = date('Y-m-d H:i:s');

        if ($existing) {
            $id = (int) $existing['id_feedforge_product'];
            $this->connection->update(
                $this->table,
                $data,
                ['id_feedforge_product' => $id]
            );

            return $id;
        }

        $data['date_add'] = date('Y-m-d H:i:s');
        $this->connection->insert($this->table, $data);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update the content hash for a product.
     */
    public function updateHash(int $id, string $hash): void
    {
        $this->connection->update(
            $this->table,
            [
                'content_hash' => $hash,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            ['id_feedforge_product' => $id]
        );
    }

    /**
     * Update the GMC status for a product.
     */
    public function updateStatus(int $id, string $status): void
    {
        $this->connection->update(
            $this->table,
            [
                'gmc_status' => $status,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            ['id_feedforge_product' => $id]
        );
    }

    /**
     * Delete all records for a given product in a shop.
     */
    public function deleteByProduct(int $productId, int $shopId): void
    {
        $this->connection->delete(
            $this->table,
            [
                'id_product' => $productId,
                'id_shop' => $shopId,
            ]
        );
    }

    /**
     * Apply optional filters to a query builder.
     */
    private function applyFilters(\Doctrine\DBAL\Query\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['gmc_status'])) {
            $qb->andWhere('gmc_status = :gmcStatus')
                ->setParameter('gmcStatus', $filters['gmc_status']);
        }

        if (!empty($filters['id_feed_config'])) {
            $qb->andWhere('id_feed_config = :feedConfigId')
                ->setParameter('feedConfigId', $filters['id_feed_config']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('id_product = :search')
                ->setParameter('search', $filters['search']);
        }
    }
}
