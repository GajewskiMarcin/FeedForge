<?php

declare(strict_types=1);

namespace FeedForge\Service;

use Doctrine\DBAL\Connection;

class ProductFilterCounter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Count products matching the given filters for a shop.
     */
    public function countFiltered(int $shopId, array $filters): int
    {
        $prefix = _DB_PREFIX_;
        $params = ['shopId' => $shopId];
        $joins = [];
        $wheres = ['ps.id_shop = :shopId', 'ps.active = 1'];

        // Categories (include or exclude mode)
        if (!empty($filters['categories'])) {
            $catIds = implode(',', array_map('intval', $filters['categories']));
            if (($filters['categories_mode'] ?? 'include') === 'exclude') {
                $wheres[] = "p.id_product NOT IN (SELECT cp.id_product FROM {$prefix}category_product cp WHERE cp.id_category IN ({$catIds}))";
            } else {
                $joins[] = "JOIN {$prefix}category_product cp ON p.id_product = cp.id_product";
                $wheres[] = "cp.id_category IN ({$catIds})";
            }
        }

        // Manufacturers (include or exclude mode)
        if (!empty($filters['manufacturers'])) {
            $mfrIds = implode(',', array_map('intval', $filters['manufacturers']));
            if (($filters['manufacturers_mode'] ?? 'include') === 'exclude') {
                $wheres[] = "p.id_manufacturer NOT IN ({$mfrIds})";
            } else {
                $wheres[] = "p.id_manufacturer IN ({$mfrIds})";
            }
        }

        // Price ranges (include or exclude mode)
        if (!empty($filters['price_ranges']) && is_array($filters['price_ranges'])) {
            $rangeClauses = [];
            foreach ($filters['price_ranges'] as $idx => $range) {
                $hasMin = isset($range['min']) && $range['min'] !== '' && $range['min'] !== null;
                $hasMax = isset($range['max']) && $range['max'] !== '' && $range['max'] !== null;
                if (!$hasMin && !$hasMax) {
                    continue;
                }
                $parts = [];
                if ($hasMin) {
                    $paramKey = "priceMin{$idx}";
                    $parts[] = "ps.price >= :{$paramKey}";
                    $params[$paramKey] = (float) $range['min'];
                }
                if ($hasMax) {
                    $paramKey = "priceMax{$idx}";
                    $parts[] = "ps.price <= :{$paramKey}";
                    $params[$paramKey] = (float) $range['max'];
                }
                $rangeClauses[] = '(' . implode(' AND ', $parts) . ')';
            }
            if (!empty($rangeClauses)) {
                $combined = '(' . implode(' OR ', $rangeClauses) . ')';
                if (($filters['price_mode'] ?? 'include') === 'exclude') {
                    $wheres[] = 'NOT ' . $combined;
                } else {
                    $wheres[] = $combined;
                }
            }
        }

        // Stock availability
        $needsStockJoin = !empty($filters['skip_out_of_stock']) || !empty($filters['min_stock']);
        if ($needsStockJoin) {
            $joins[] = "JOIN {$prefix}stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0";
            if (!empty($filters['skip_out_of_stock'])) {
                $wheres[] = 'sa.quantity > 0';
            }
            if (!empty($filters['min_stock']) && (int) $filters['min_stock'] > 0) {
                $wheres[] = 'sa.quantity >= :minStock';
                $params['minStock'] = (int) $filters['min_stock'];
            }
        }

        // Require EAN
        if (!empty($filters['require_ean'])) {
            $wheres[] = "p.ean13 IS NOT NULL AND p.ean13 != ''";
        }

        // Require image
        if (!empty($filters['require_image'])) {
            $wheres[] = "EXISTS (SELECT 1 FROM {$prefix}image i WHERE i.id_product = p.id_product)";
        }

        // Condition
        if (!empty($filters['condition'])) {
            $conditions = array_map(function ($c) {
                return "'" . pSQL((string) $c) . "'";
            }, $filters['condition']);
            $wheres[] = 'p.condition IN (' . implode(',', $conditions) . ')';
        }

        // Visibility
        if (!empty($filters['visibility'])) {
            $visibilities = array_map(function ($v) {
                return "'" . pSQL((string) $v) . "'";
            }, $filters['visibility']);
            $wheres[] = 'ps.visibility IN (' . implode(',', $visibilities) . ')';
        }

        // Suppliers (include or exclude mode)
        if (!empty($filters['suppliers'])) {
            $supIds = implode(',', array_map('intval', $filters['suppliers']));
            if (($filters['suppliers_mode'] ?? 'include') === 'exclude') {
                $wheres[] = "p.id_product NOT IN (SELECT psu.id_product FROM {$prefix}product_supplier psu WHERE psu.id_supplier IN ({$supIds}))";
            } else {
                $joins[] = "JOIN {$prefix}product_supplier psu ON p.id_product = psu.id_product";
                $wheres[] = "psu.id_supplier IN ({$supIds})";
            }
        }

        // Tags (include: product must have at least one; exclude: must not have any)
        if (!empty($filters['tags_include'])) {
            $incTagIds = implode(',', array_map('intval', $filters['tags_include']));
            $wheres[] = "EXISTS (SELECT 1 FROM {$prefix}product_tag pti WHERE pti.id_product = p.id_product AND pti.id_tag IN ({$incTagIds}))";
        }
        if (!empty($filters['tags_exclude'])) {
            $excTagIds = implode(',', array_map('intval', $filters['tags_exclude']));
            $wheres[] = "NOT EXISTS (SELECT 1 FROM {$prefix}product_tag pte WHERE pte.id_product = p.id_product AND pte.id_tag IN ({$excTagIds}))";
        }

        // Features (include or exclude mode)
        if (!empty($filters['features'])) {
            $isExclude = ($filters['features_mode'] ?? 'include') === 'exclude';
            if ($isExclude) {
                // Exclude: product must NOT have ANY of the feature+value pairs
                foreach ($filters['features'] as $idx => $feat) {
                    $fid = (int) ($feat['id_feature'] ?? 0);
                    $fvid = (int) ($feat['id_feature_value'] ?? 0);
                    if ($fid > 0 && $fvid > 0) {
                        $wheres[] = "NOT EXISTS (SELECT 1 FROM {$prefix}feature_product efp{$idx} WHERE efp{$idx}.id_product = p.id_product AND efp{$idx}.id_feature = {$fid} AND efp{$idx}.id_feature_value = {$fvid})";
                    }
                }
            } else {
                // Include: product must have ALL feature+value pairs (AND)
                foreach ($filters['features'] as $idx => $feat) {
                    $fid = (int) ($feat['id_feature'] ?? 0);
                    $fvid = (int) ($feat['id_feature_value'] ?? 0);
                    if ($fid > 0 && $fvid > 0) {
                        $alias = "fp{$idx}";
                        $joins[] = "JOIN {$prefix}feature_product {$alias} ON p.id_product = {$alias}.id_product AND {$alias}.id_feature = {$fid} AND {$alias}.id_feature_value = {$fvid}";
                    }
                }
            }
        }

        // Excluded products
        if (!empty($filters['excluded_products'])) {
            $excIds = implode(',', array_map('intval', $filters['excluded_products']));
            $wheres[] = "p.id_product NOT IN ({$excIds})";
        }

        $joinSql = implode("\n", $joins);
        $whereSql = implode(' AND ', $wheres);

        $sql = "SELECT COUNT(DISTINCT p.id_product)
            FROM {$prefix}product p
            JOIN {$prefix}product_shop ps ON p.id_product = ps.id_product
            {$joinSql}
            WHERE {$whereSql}";

        return (int) $this->connection->fetchOne($sql, $params);
    }

    /**
     * Count all active products for a shop.
     */
    public function countTotal(int $shopId): int
    {
        $prefix = _DB_PREFIX_;

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$prefix}product_shop WHERE id_shop = :shopId AND active = 1",
            ['shopId' => $shopId]
        );
    }
}
