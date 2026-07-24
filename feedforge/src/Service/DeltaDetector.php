<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\FeedConfigRepository;
use FeedForge\Repository\GmcProductRepository;

/**
 * Detects product changes by comparing SHA-1 content hashes.
 * Only products whose mapped data has changed since last sync will be queued.
 */
class DeltaDetector
{
    public function __construct(
        private readonly DataMapper $dataMapper,
        private readonly GmcProductRepository $gmcProductRepository,
        private readonly FeedConfigRepository $feedConfigRepository,
    ) {
    }

    /**
     * Scan all active products and detect which ones have changed.
     * Returns arrays of products needing insert, update, or delete.
     *
     * @return array{insert: array[], update: array[], delete: array[]}
     */
    public function detect(int $shopId): array
    {
        $feedConfigs = $this->feedConfigRepository->findActive($shopId);
        if (empty($feedConfigs)) {
            return ['insert' => [], 'update' => [], 'delete' => []];
        }

        $inserts = [];
        $updates = [];
        $deletes = [];

        foreach ($feedConfigs as $feedConfig) {
            $feedConfigId = (int) $feedConfig['id_feedforge_feed_config'];
            $result = $this->detectForFeed($shopId, $feedConfigId);

            $inserts = array_merge($inserts, $result['insert']);
            $updates = array_merge($updates, $result['update']);
            $deletes = array_merge($deletes, $result['delete']);
        }

        return [
            'insert' => $inserts,
            'update' => $updates,
            'delete' => $deletes,
        ];
    }

    /**
     * Detect changes for a single feed configuration.
     *
     * @return array{insert: array[], update: array[], delete: array[]}
     */
    public function detectForFeed(int $shopId, int $feedConfigId): array
    {
        $inserts = [];
        $updates = [];

        // Get active PS product IDs filtered by feed config criteria
        $activeProducts = $this->dataMapper->getActiveProductIds($shopId, $feedConfigId);
        $activeSet = []; // Track which products are still active

        foreach ($activeProducts as $row) {
            $productId = (int) $row['id_product'];
            $attributeId = (int) $row['id_product_attribute'];
            $key = "{$productId}-{$attributeId}";

            // Map the product
            $mapped = $this->dataMapper->mapProduct($productId, $attributeId, $shopId, $feedConfigId);

            if ($mapped === null) {
                // Product excluded by rules — don't add to activeSet
                // so detectDeletions will pick it up for removal from GMC
                continue;
            }

            $activeSet[$key] = true;

            $newHash = $this->dataMapper->computeHash($mapped);

            // Check existing record
            $existing = $this->gmcProductRepository->findByProduct(
                $productId, $attributeId, $shopId, $feedConfigId
            );

            if ($existing === null) {
                // New product - needs insert
                $inserts[] = [
                    'id_product' => $productId,
                    'id_product_attribute' => $attributeId,
                    'id_feed_config' => $feedConfigId,
                    'hash' => $newHash,
                ];
            } elseif ($existing['content_hash'] !== $newHash) {
                // Hash changed - needs update
                $updates[] = [
                    'id_product' => $productId,
                    'id_product_attribute' => $attributeId,
                    'id_feed_config' => $feedConfigId,
                    'id_feedforge_product' => (int) $existing['id_feedforge_product'],
                    'hash' => $newHash,
                ];
            }
            // If hash matches, product is unchanged - skip
        }

        // Detect deletions: products in GMC that are no longer active in PS
        $deletes = $this->detectDeletions($shopId, $feedConfigId, $activeSet);

        return [
            'insert' => $inserts,
            'update' => $updates,
            'delete' => $deletes,
        ];
    }

    /**
     * Check a single product for changes. Returns the action needed.
     *
     * @return array{action: string, hash: string}|null Null if no change needed
     */
    public function checkProduct(int $productId, int $attributeId, int $shopId, int $feedConfigId): ?array
    {
        $mapped = $this->dataMapper->mapProduct($productId, $attributeId, $shopId, $feedConfigId);

        $existing = $this->gmcProductRepository->findByProduct(
            $productId, $attributeId, $shopId, $feedConfigId
        );

        if ($mapped === null) {
            // Product excluded
            if ($existing && !empty($existing['gmc_id'])) {
                return ['action' => 'delete', 'hash' => ''];
            }
            return null;
        }

        $newHash = $this->dataMapper->computeHash($mapped);

        if ($existing === null) {
            return ['action' => 'insert', 'hash' => $newHash];
        }

        if ($existing['content_hash'] !== $newHash) {
            return ['action' => 'update', 'hash' => $newHash];
        }

        return null; // No change
    }

    /**
     * Detect products that exist in our DB but are no longer active in PS.
     */
    private function detectDeletions(int $shopId, int $feedConfigId, array $activeSet): array
    {
        $deletes = [];

        // Get all synced products for this feed config
        $page = 1;
        $perPage = 500;

        do {
            $existing = $this->gmcProductRepository->findByShopPaginated(
                $shopId,
                $page,
                $perPage,
                ['id_feed_config' => (string) $feedConfigId]
            );

            foreach ($existing as $record) {
                $productId = (int) $record['id_product'];
                $attributeId = (int) $record['id_product_attribute'];
                $key = "{$productId}-{$attributeId}";

                if (!isset($activeSet[$key])) {
                    $deletes[] = [
                        'id_product' => $productId,
                        'id_product_attribute' => $attributeId,
                        'id_feed_config' => $feedConfigId,
                        'id_feedforge_product' => (int) $record['id_feedforge_product'],
                        'gmc_id' => $record['gmc_id'] ?? '',
                    ];
                }
            }

            $page++;
        } while (count($existing) === $perPage);

        return $deletes;
    }
}
