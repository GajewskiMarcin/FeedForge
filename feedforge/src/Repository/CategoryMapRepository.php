<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class CategoryMapRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_category_map';
    }

    /**
     * Find all category mappings for a shop.
     */
    public function findByShop(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('id_category', 'ASC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Find a specific category mapping.
     */
    public function findByCategory(int $categoryId, int $shopId): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('id_category = :categoryId')
            ->andWhere('id_shop = :shopId')
            ->setParameter('categoryId', $categoryId)
            ->setParameter('shopId', $shopId)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Save a category mapping (insert or update based on presence of id).
     */
    public function save(array $data): void
    {
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (!empty($data['id_feedforge_category_map'])) {
            $id = $data['id_feedforge_category_map'];
            unset($data['id_feedforge_category_map']);

            $this->connection->update(
                $this->table,
                $data,
                ['id_feedforge_category_map' => $id]
            );
        } else {
            $data['date_add'] = date('Y-m-d H:i:s');

            $this->connection->insert($this->table, $data);
        }
    }

    /**
     * Delete a category mapping by ID.
     */
    public function delete(int $id): void
    {
        $this->connection->delete($this->table, [
            'id_feedforge_category_map' => $id,
        ]);
    }
}
