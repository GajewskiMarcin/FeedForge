<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class AttributeMapRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_attribute_map';
    }

    /**
     * Find all attribute mappings for a shop.
     */
    public function findByShop(int $shopId): array
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->orderBy('gmc_field', 'ASC')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Find an attribute mapping by GMC field name and shop.
     */
    public function findByGmcField(string $gmcField, int $shopId): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('gmc_field = :gmcField')
            ->andWhere('id_shop = :shopId')
            ->setParameter('gmcField', $gmcField)
            ->setParameter('shopId', $shopId)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Save an attribute mapping (insert or update based on presence of id).
     */
    public function save(array $data): void
    {
        $data['date_upd'] = date('Y-m-d H:i:s');

        if (!empty($data['id_feedforge_attribute_map'])) {
            $id = $data['id_feedforge_attribute_map'];
            unset($data['id_feedforge_attribute_map']);

            $this->connection->update(
                $this->table,
                $data,
                ['id_feedforge_attribute_map' => $id]
            );
        } else {
            $data['date_add'] = date('Y-m-d H:i:s');
            $this->connection->insert($this->table, $data);
        }
    }

    /**
     * Delete an attribute mapping by ID.
     */
    public function delete(int $id): void
    {
        $this->connection->delete($this->table, [
            'id_feedforge_attribute_map' => $id,
        ]);
    }
}
