<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class RuleRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_rule';
    }

    /**
     * Find all rules for a shop, optionally filtered by type.
     */
    public function findByShop(int $shopId, ?string $type = null): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('priority', 'ASC');

        if ($type !== null) {
            $qb->andWhere('type = :type')
                ->setParameter('type', $type);
        }

        return $qb->execute()->fetchAllAssociative();
    }

    /**
     * Find a rule by its ID, scoped to a shop.
     */
    public function findById(int $id, ?int $shopId = null): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->select('*')
            ->from($this->table)
            ->where('id_feedforge_rule = :id')
            ->setParameter('id', $id);

        if ($shopId !== null) {
            $qb->andWhere('id_shop = :shopId')
                ->setParameter('shopId', $shopId);
        }

        $result = $qb->execute()->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Save a rule (insert or update). Returns the rule ID.
     * The $data array must contain 'id_shop' for shop scoping.
     */
    public function save(array $data): int
    {
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (!empty($data['id_feedforge_rule'])) {
            $id = (int) $data['id_feedforge_rule'];
            $shopId = (int) ($data['id_shop'] ?? 0);
            unset($data['id_feedforge_rule']);

            $criteria = ['id_feedforge_rule' => $id];
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
     * Delete a rule by ID, scoped to a shop.
     */
    public function delete(int $id, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_rule' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $this->connection->delete($this->table, $criteria);
    }

    /**
     * Update the priority of a rule, scoped to a shop.
     */
    public function updatePriority(int $id, int $priority, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_rule' => $id];
        if ($shopId !== null) {
            $criteria['id_shop'] = $shopId;
        }

        $this->connection->update(
            $this->table,
            [
                'priority' => $priority,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            $criteria
        );
    }

    /**
     * Toggle the active state of a rule, scoped to a shop.
     */
    public function toggleActive(int $id, bool $active, ?int $shopId = null): void
    {
        $criteria = ['id_feedforge_rule' => $id];
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
}
