<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class ProductStatusRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_product_status';
    }

    /**
     * Find all status/issue records for a given feedforge product.
     */
    public function findByFeedforgeProduct(int $feedforgeProductId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_feedforge_product = :productId')
            ->setParameter('productId', $feedforgeProductId)
            ->orderBy('issue_severity', 'DESC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Replace all issues for a product: delete existing, then insert new ones.
     */
    public function replaceForProduct(int $feedforgeProductId, array $issues): void
    {
        $this->connection->delete($this->table, [
            'id_feedforge_product' => $feedforgeProductId,
        ]);

        $now = date('Y-m-d H:i:s');

        foreach ($issues as $issue) {
            $issue['id_feedforge_product'] = $feedforgeProductId;
            $issue['date_add'] = $now;

            $this->connection->insert($this->table, $issue);
        }
    }

    /**
     * Count issues grouped by severity for a shop.
     */
    public function countBySeverity(int $shopId): array
    {
        $productTable = _DB_PREFIX_ . 'feedforge_product';

        $qb = $this->connection->createQueryBuilder();

        $rows = $qb->select('ps.issue_severity', 'COUNT(*) AS total')
            ->from($this->table, 'ps')
            ->innerJoin('ps', $productTable, 'p', 'ps.id_feedforge_product = p.id_feedforge_product')
            ->where('p.id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->groupBy('ps.issue_severity')
            ->execute()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['issue_severity']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Get the most common issues for a shop, limited to the top N.
     */
    public function getTopIssues(int $shopId, int $limit = 10): array
    {
        $productTable = _DB_PREFIX_ . 'feedforge_product';

        $qb = $this->connection->createQueryBuilder();

        return $qb->select('ps.issue_code', 'ps.issue_severity', 'ps.issue_message', 'COUNT(*) AS total')
            ->from($this->table, 'ps')
            ->innerJoin('ps', $productTable, 'p', 'ps.id_feedforge_product = p.id_feedforge_product')
            ->where('p.id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->groupBy('ps.issue_code', 'ps.issue_severity', 'ps.issue_message')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->execute()
            ->fetchAllAssociative();
    }
}
