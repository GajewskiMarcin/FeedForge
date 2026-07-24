<?php

declare(strict_types=1);

namespace FeedForge\EventSubscriber;

use FeedForge\Repository\GmcProductRepository;
use FeedForge\Repository\SyncQueueRepository;

/**
 * Listens to PrestaShop product hooks and enqueues sync operations.
 *
 * Important: hooks do NOT call Google API directly.
 * They only insert records into the sync queue for later batch processing.
 */
class ProductSubscriber
{
    public function __construct(
        private readonly SyncQueueRepository $syncQueueRepository,
        private readonly GmcProductRepository $gmcProductRepository,
    ) {
    }

    /**
     * Called when a product is saved (created or updated).
     * Hook: actionProductSave
     */
    public function onProductSave(array $params): void
    {
        $productId = $this->extractProductId($params);
        if ($productId === null) {
            return;
        }

        $shopId = $this->getShopId();

        // Check if product already exists in GMC
        $existing = $this->gmcProductRepository->findByProduct($productId, 0, $shopId, 0);
        $action = $existing ? 'update' : 'insert';

        // Priority 5 = normal (stock/price changes get higher priority via onQuantityUpdate)
        $this->enqueue($productId, 0, $shopId, $action, 5);
    }

    /**
     * Called when stock quantity changes.
     * Hook: actionUpdateQuantity
     *
     * Higher priority (3) because availability changes are critical for GMC.
     */
    public function onQuantityUpdate(array $params): void
    {
        $productId = (int) ($params['id_product'] ?? 0);
        if ($productId <= 0) {
            return;
        }

        $attributeId = (int) ($params['id_product_attribute'] ?? 0);
        $shopId = $this->getShopId();

        $this->enqueue($productId, $attributeId, $shopId, 'update', 3);
    }

    /**
     * Called when a product is deleted.
     * Hook: actionProductDelete
     */
    public function onProductDelete(array $params): void
    {
        $productId = $this->extractProductId($params);
        if ($productId === null) {
            return;
        }

        $shopId = $this->getShopId();

        // Priority 1 = highest (deletions should be processed ASAP)
        $this->enqueue($productId, 0, $shopId, 'delete', 1);
    }

    /**
     * Called after product object is deleted (fallback).
     * Hook: actionObjectProductDeleteAfter
     */
    public function onProductObjectDelete(array $params): void
    {
        $object = $params['object'] ?? null;
        if (!$object || !isset($object->id)) {
            return;
        }

        $productId = (int) $object->id;
        if ($productId <= 0) {
            return;
        }

        $shopId = $this->getShopId();
        $this->enqueue($productId, 0, $shopId, 'delete', 1);
    }

    /**
     * Extract product ID from hook parameters.
     */
    private function extractProductId(array $params): ?int
    {
        // Different hooks pass product ID in different ways
        $productId = (int) ($params['id_product'] ?? $params['product']->id ?? 0);

        if ($productId <= 0) {
            return null;
        }

        return $productId;
    }

    /**
     * Get current shop ID from context.
     */
    private function getShopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    /**
     * Add item to sync queue (with deduplication).
     */
    private function enqueue(int $productId, int $attributeId, int $shopId, string $action, int $priority): void
    {
        try {
            $this->syncQueueRepository->enqueue(
                $productId,
                $attributeId,
                $shopId,
                $action,
                $priority
            );
        } catch (\Exception $e) {
            // Silently fail - we don't want hook errors to break the shop
            if (\Configuration::get('FEEDFORGE_DEBUG_LOGGING')) {
                \PrestaShopLogger::addLog(
                    'FeedForge: Failed to enqueue product ' . $productId . ': ' . $e->getMessage(),
                    3, // warning
                    null,
                    'Product',
                    $productId
                );
            }
        }
    }
}
