<?php

declare(strict_types=1);

/**
 * Feed Forge - Google Merchant Center integration for PrestaShop
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   MIT
 */

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

/**
 * DBAL repository for the ps_feedforge_promotion table.
 */
class PromotionRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_promotion';
    }

    /**
     * Find all promotions for a shop.
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
     * Find active promotions for a shop.
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
     * Find a promotion by its ID, optionally scoped to a shop.
     */
    public function findById(int $id, ?int $shopId = null): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('*')
            ->from($this->table)
            ->where('id_feedforge_promotion = :id')
            ->setParameter('id', $id);

        if ($shopId !== null) {
            $qb->andWhere('id_shop = :shopId')
                ->setParameter('shopId', $shopId);
        }

        $result = $qb->execute()->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Find a promotion by cart rule ID and shop.
     */
    public function findByCartRule(int $cartRuleId, int $shopId): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('id_cart_rule = :cartRuleId')
            ->andWhere('id_shop = :shopId')
            ->setParameter('cartRuleId', $cartRuleId)
            ->setParameter('shopId', $shopId)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Save a promotion (insert or update). Returns the promotion ID.
     */
    public function save(array $data): int
    {
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (isset($data['product_filters']) && is_array($data['product_filters'])) {
            $data['product_filters'] = json_encode($data['product_filters']);
        }

        if (!empty($data['id_feedforge_promotion'])) {
            $id = (int) $data['id_feedforge_promotion'];
            $shopId = (int) ($data['id_shop'] ?? 0);
            unset($data['id_feedforge_promotion']);

            $criteria = ['id_feedforge_promotion' => $id];
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
     * Delete a promotion by ID, scoped to a shop.
     */
    public function delete(int $id, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_promotion' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $this->connection->delete($this->table, $criteria);
    }

    /**
     * Update the GMC status and google promotion ID for a promotion.
     */
    public function updateStatus(int $id, string $status, ?string $googleId = null, ?string $error = null): void
    {
        $data = [
            'gmc_status' => $status,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($googleId !== null) {
            $data['google_promotion_id'] = $googleId;
        }

        $data['last_error'] = $error;

        $this->connection->update($this->table, $data, ['id_feedforge_promotion' => $id]);
    }
}
