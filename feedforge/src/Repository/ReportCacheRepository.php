<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class ReportCacheRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_report_cache';
    }

    /**
     * Insert or update a daily report cache entry.
     */
    public function upsertDaily(array $data): void
    {
        $existing = null;

        if (isset($data['id_shop'], $data['report_date'], $data['country_code'])) {
            $qb = $this->connection->createQueryBuilder();

            $existing = $qb->select('id_feedforge_report_cache')
                ->from($this->table)
                ->where('id_shop = :shopId')
                ->andWhere('report_date = :date')
                ->andWhere('country_code = :country')
                ->andWhere('id_product = :productId')
                ->setParameter('shopId', $data['id_shop'])
                ->setParameter('date', $data['report_date'])
                ->setParameter('country', $data['country_code'])
                ->setParameter('productId', $data['id_product'] ?? 0)
                ->execute()
                ->fetchAssociative();
        }

        $data['date_upd'] = date('Y-m-d H:i:s');

        if ($existing) {
            $this->connection->update(
                $this->table,
                $data,
                ['id_feedforge_report_cache' => $existing['id_feedforge_report_cache']]
            );
        } else {
            $data['date_add'] = date('Y-m-d H:i:s');
            $this->connection->insert($this->table, $data);
        }
    }

    /**
     * Get aggregated report metrics for a date range, optionally filtered by country.
     */
    public function getAggregated(int $shopId, string $startDate, string $endDate, ?string $countryCode = null): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select(
            'SUM(impressions) AS total_impressions',
            'SUM(clicks) AS total_clicks'
        )
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('report_date >= :startDate')
            ->andWhere('report_date <= :endDate')
            ->setParameter('shopId', $shopId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($countryCode !== null) {
            $qb->andWhere('country_code = :country')
                ->setParameter('country', $countryCode);
        }

        $result = $qb->execute()->fetchAssociative();

        return $result ?: [];
    }

    /**
     * Get a daily time series of report metrics.
     */
    public function getTimeSeries(int $shopId, string $startDate, string $endDate): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select(
            'report_date',
            'SUM(impressions) AS impressions',
            'SUM(clicks) AS clicks'
        )
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('report_date >= :startDate')
            ->andWhere('report_date <= :endDate')
            ->setParameter('shopId', $shopId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('report_date')
            ->orderBy('report_date', 'ASC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Get top products by clicks within a date range.
     */
    public function getByProduct(int $shopId, string $startDate, string $endDate, int $limit = 10): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select(
            'id_product',
            'SUM(impressions) AS impressions',
            'SUM(clicks) AS clicks'
        )
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('report_date >= :startDate')
            ->andWhere('report_date <= :endDate')
            ->setParameter('shopId', $shopId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('id_product')
            ->orderBy('clicks', 'DESC')
            ->setMaxResults($limit)
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Get report data broken down by country.
     */
    public function getByCountry(int $shopId, string $startDate, string $endDate): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select(
            'country_code',
            'SUM(impressions) AS impressions',
            'SUM(clicks) AS clicks'
        )
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->andWhere('report_date >= :startDate')
            ->andWhere('report_date <= :endDate')
            ->setParameter('shopId', $shopId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->groupBy('country_code')
            ->orderBy('clicks', 'DESC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Delete report cache entries older than the retention period.
     */
    public function cleanup(int $retentionDays): int
    {
        $cutoff = date('Y-m-d', strtotime("-{$retentionDays} days"));

        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE report_date < :cutoff",
            ['cutoff' => $cutoff]
        );
    }
}
