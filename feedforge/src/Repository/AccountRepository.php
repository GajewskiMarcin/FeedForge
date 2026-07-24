<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class AccountRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_account';
    }

    /**
     * Find an account by shop ID.
     */
    public function findByShop(int $shopId): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('id_shop = :shopId')
            ->setParameter('shopId', $shopId)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Insert or update an account for the given shop.
     */
    public function upsert(int $shopId, array $data): void
    {
        $existing = $this->findByShop($shopId);

        $data['date_upd'] = date('Y-m-d H:i:s');

        if ($existing) {
            $this->connection->update(
                $this->table,
                $data,
                ['id_shop' => $shopId]
            );
        } else {
            $data['id_shop'] = $shopId;
            $data['date_add'] = date('Y-m-d H:i:s');

            $this->connection->insert($this->table, $data);
        }
    }

    /**
     * Delete an account by shop ID.
     */
    public function delete(int $shopId): void
    {
        $this->connection->delete($this->table, ['id_shop' => $shopId]);
    }
}
