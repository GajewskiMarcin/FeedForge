<?php

declare(strict_types=1);

namespace FeedForge\Repository;

use Doctrine\DBAL\Connection;

class TaxonomyRepository
{
    private string $table;

    public function __construct(private readonly Connection $connection)
    {
        $this->table = _DB_PREFIX_ . 'feedforge_taxonomy';
    }

    /**
     * Search taxonomy entries using FULLTEXT matching.
     */
    public function search(string $query, string $lang, int $limit = 20): array
    {
        $sql = "SELECT *, MATCH(value) AGAINST(:query IN BOOLEAN MODE) AS relevance
                FROM {$this->table}
                WHERE lang = :lang
                  AND MATCH(value) AGAINST(:query IN BOOLEAN MODE)
                ORDER BY relevance DESC
                LIMIT :limit";

        return $this->connection->fetchAllAssociative($sql, [
            'query' => $query,
            'lang' => $lang,
            'limit' => $limit,
        ], [
            'query' => \Doctrine\DBAL\ParameterType::STRING,
            'lang' => \Doctrine\DBAL\ParameterType::STRING,
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);
    }

    /**
     * Search taxonomy entries by taxonomy_id prefix (numeric query support).
     * Exact match first, then prefix matches. Useful when the user knows
     * the Google category ID and wants to look it up directly.
     */
    public function searchById(string $idPrefix, string $lang, int $limit = 20): array
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE lang = :lang
                  AND CAST(taxonomy_id AS CHAR) LIKE :idPrefix
                ORDER BY taxonomy_id ASC
                LIMIT :limit";

        return $this->connection->fetchAllAssociative($sql, [
            'idPrefix' => $idPrefix . '%',
            'lang' => $lang,
            'limit' => $limit,
        ], [
            'idPrefix' => \Doctrine\DBAL\ParameterType::STRING,
            'lang' => \Doctrine\DBAL\ParameterType::STRING,
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);
    }

    /**
     * Find a taxonomy entry by its ID and language.
     */
    public function findById(int $taxonomyId, string $lang): ?array
    {
        $qb = $this->connection->createQueryBuilder();

        $result = $qb->select('*')
            ->from($this->table)
            ->where('taxonomy_id = :taxonomyId')
            ->andWhere('lang = :lang')
            ->setParameter('taxonomyId', $taxonomyId)
            ->setParameter('lang', $lang)
            ->execute()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Import a batch of taxonomy rows.
     */
    public function importBatch(array $rows): void
    {
        foreach ($rows as $row) {
            $this->connection->insert($this->table, $row);
        }
    }

    /**
     * Remove all taxonomy entries for a given language.
     */
    public function clearByLang(string $lang): void
    {
        $this->connection->delete($this->table, ['lang' => $lang]);
    }
}
