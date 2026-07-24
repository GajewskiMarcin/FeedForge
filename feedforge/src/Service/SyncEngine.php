<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\FeedConfigRepository;
use FeedForge\Repository\GmcProductRepository;
use FeedForge\Repository\SyncLogRepository;
use FeedForge\Repository\SyncQueueRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Orchestrates product synchronization between PrestaShop and Google Merchant Center.
 * Supports full sync, delta sync, and manual single-product sync.
 */
class SyncEngine
{

    public function __construct(
        private readonly DeltaDetector $deltaDetector,
        private readonly QueueProcessor $queueProcessor,
        private readonly SyncQueueRepository $syncQueueRepository,
        private readonly SyncLogRepository $syncLogRepository,
        private readonly GmcProductRepository $gmcProductRepository,
        private readonly FeedConfigRepository $feedConfigRepository,
        private readonly GoogleApiClient $googleApiClient,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Run a full sync: scan all products, detect changes, enqueue, and process.
     *
     * @return array{logId: int, stats: array}
     */
    public function fullSync(int $shopId): array
    {
        $this->validateConnection($shopId);

        $logId = $this->syncLogRepository->create($shopId, 'full');

        try {
            // Phase 1: Delta detection — scan all products for changes
            $changes = $this->deltaDetector->detect($shopId);

            // Phase 2: Enqueue changes
            $enqueued = $this->enqueueChanges($shopId, $changes);

            // Phase 3: Process queue
            $batchSize = (int) (\Configuration::get('FEEDFORGE_BATCH_SIZE') ?: 100);
            $maxExecution = (int) (\Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300);

            $processStats = $this->queueProcessor->processUntilDone($shopId, $batchSize, $maxExecution);

            // Finalize log
            $stats = [
                'status' => 'completed',
                'products_processed' => $processStats['processed'],
                'products_inserted' => $processStats['inserted'],
                'products_updated' => $processStats['updated'],
                'products_deleted' => $processStats['deleted'],
                'products_failed' => $processStats['failed'],
                'error_summary' => null,
            ];

            $this->syncLogRepository->finish($logId, $stats);

            return ['logId' => $logId, 'stats' => $stats];
        } catch (\Exception $e) {
            $this->syncLogRepository->finish($logId, [
                'status' => 'failed',
                'products_processed' => 0,
                'products_failed' => 0,
                'error_summary' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Run a delta sync: process only items already in the queue (from hooks).
     * Faster than full sync because it skips the detection phase.
     *
     * @return array{logId: int, stats: array}
     */
    public function deltaSync(int $shopId): array
    {
        $this->validateConnection($shopId);

        $logId = $this->syncLogRepository->create($shopId, 'delta');

        try {
            $batchSize = (int) (\Configuration::get('FEEDFORGE_BATCH_SIZE') ?: 100);
            $maxExecution = (int) (\Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300);

            $processStats = $this->queueProcessor->processUntilDone($shopId, $batchSize, $maxExecution);

            $stats = [
                'status' => 'completed',
                'products_processed' => $processStats['processed'],
                'products_inserted' => $processStats['inserted'],
                'products_updated' => $processStats['updated'],
                'products_deleted' => $processStats['deleted'],
                'products_failed' => $processStats['failed'],
                'error_summary' => null,
            ];

            $this->syncLogRepository->finish($logId, $stats);

            return ['logId' => $logId, 'stats' => $stats];
        } catch (\Exception $e) {
            $this->syncLogRepository->finish($logId, [
                'status' => 'failed',
                'products_processed' => 0,
                'products_failed' => 0,
                'error_summary' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Manually sync specific products (triggered from admin UI).
     *
     * @param int[] $productIds PS product IDs to sync
     * @return array{logId: int, stats: array}
     */
    public function manualSync(int $shopId, array $productIds): array
    {
        $this->validateConnection($shopId);

        $logId = $this->syncLogRepository->create($shopId, 'manual');

        try {
            // Enqueue specified products with high priority
            foreach ($productIds as $productId) {
                $this->syncQueueRepository->enqueue(
                    (int) $productId,
                    0,
                    $shopId,
                    'update',
                    2 // High priority for manual sync
                );
            }

            $batchSize = (int) (\Configuration::get('FEEDFORGE_BATCH_SIZE') ?: 100);
            $maxExecution = min(60, (int) (\Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: 300));

            $processStats = $this->queueProcessor->processUntilDone($shopId, $batchSize, $maxExecution);

            $stats = [
                'status' => 'completed',
                'products_processed' => $processStats['processed'],
                'products_inserted' => $processStats['inserted'],
                'products_updated' => $processStats['updated'],
                'products_deleted' => $processStats['deleted'],
                'products_failed' => $processStats['failed'],
                'error_summary' => null,
            ];

            $this->syncLogRepository->finish($logId, $stats);

            return ['logId' => $logId, 'stats' => $stats];
        } catch (\Exception $e) {
            $this->syncLogRepository->finish($logId, [
                'status' => 'failed',
                'products_processed' => 0,
                'products_failed' => 0,
                'error_summary' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Determine which sync type to run based on configuration and last sync.
     * Used by cron to decide automatically.
     *
     * @return string 'full'|'delta'|'skip'
     */
    public function determineSyncType(int $shopId): string
    {
        // Check sync interval
        $intervalHours = (int) (\Configuration::get('FEEDFORGE_SYNC_INTERVAL') ?: 4);
        if ($intervalHours === 0) {
            return 'skip'; // Auto-sync disabled
        }

        $lastSync = $this->syncLogRepository->getLatest($shopId);

        if (!$lastSync) {
            return 'full'; // First sync ever
        }

        $lastSyncTime = strtotime($lastSync['started_at'] ?? '2000-01-01');
        $intervalSeconds = $intervalHours * 3600;

        if ((time() - $lastSyncTime) < $intervalSeconds) {
            // Not enough time has passed, but process queue items if any
            $queueStats = $this->syncQueueRepository->getStats($shopId);
            if (($queueStats['pending'] ?? 0) > 0) {
                return 'delta';
            }
            return 'skip';
        }

        // Check if delta sync is enabled
        if (\Configuration::get('FEEDFORGE_DELTA_SYNC_ENABLED')) {
            // Run full sync periodically (every 24h), delta otherwise
            $timeSinceLastFull = $this->getTimeSinceLastFullSync($shopId);
            if ($timeSinceLastFull > 86400) { // 24 hours
                return 'full';
            }
            return 'delta';
        }

        return 'full';
    }

    /**
     * Run automatic sync (called by cron).
     *
     * @return array{syncType: string, logId: int|null, stats: array|null}
     */
    public function autoSync(int $shopId): array
    {
        $syncType = $this->determineSyncType($shopId);

        if ($syncType === 'skip') {
            return ['syncType' => 'skip', 'logId' => null, 'stats' => null];
        }

        $result = match ($syncType) {
            'full' => $this->fullSync($shopId),
            'delta' => $this->deltaSync($shopId),
        };

        return [
            'syncType' => $syncType,
            'logId' => $result['logId'],
            'stats' => $result['stats'],
        ];
    }

    /**
     * Enqueue detected changes into the sync queue.
     * Deduplicates by product+attribute since the queue is product-level
     * and QueueProcessor handles all feeds per queue item.
     */
    private function enqueueChanges(int $shopId, array $changes): int
    {
        $enqueued = [];

        foreach ($changes['insert'] as $item) {
            $key = $item['id_product'] . '-' . $item['id_product_attribute'];
            if (isset($enqueued[$key])) {
                continue;
            }
            $this->syncQueueRepository->enqueue(
                $item['id_product'],
                $item['id_product_attribute'],
                $shopId,
                'insert',
                5
            );
            $enqueued[$key] = true;
        }

        foreach ($changes['update'] as $item) {
            $key = $item['id_product'] . '-' . $item['id_product_attribute'];
            if (isset($enqueued[$key])) {
                continue;
            }
            $this->syncQueueRepository->enqueue(
                $item['id_product'],
                $item['id_product_attribute'],
                $shopId,
                'update',
                5
            );
            $enqueued[$key] = true;
        }

        foreach ($changes['delete'] as $item) {
            $key = $item['id_product'] . '-' . $item['id_product_attribute'];
            if (isset($enqueued[$key])) {
                continue;
            }
            $this->syncQueueRepository->enqueue(
                $item['id_product'],
                $item['id_product_attribute'],
                $shopId,
                'delete',
                1 // High priority for deletes
            );
            $enqueued[$key] = true;
        }

        return count($enqueued);
    }

    /**
     * Validate that the shop has a working GMC connection.
     */
    private function validateConnection(int $shopId): void
    {
        if (!$this->googleApiClient->isConnected($shopId)) {
            throw new \RuntimeException($this->translator->trans('Google Merchant Center is not connected. Configure the connection in settings.', [], 'Modules.Feedforge.Admin'));
        }

        $feedConfigs = $this->feedConfigRepository->findActive($shopId);
        if (empty($feedConfigs)) {
            throw new \RuntimeException($this->translator->trans('No active feeds. Add at least one feed in configuration.', [], 'Modules.Feedforge.Admin'));
        }
    }

    /**
     * Get seconds since the last full sync.
     */
    private function getTimeSinceLastFullSync(int $shopId): int
    {
        // We need to find the last 'full' sync specifically
        // Since SyncLogRepository doesn't have a typed filter, use the latest
        // and assume it was full if it processed enough products
        $latest = $this->syncLogRepository->getLatest($shopId);

        if (!$latest) {
            return PHP_INT_MAX;
        }

        if (($latest['sync_type'] ?? '') === 'full') {
            return time() - strtotime($latest['started_at'] ?? '2000-01-01');
        }

        // No recent full sync found — return a large number to trigger one
        return PHP_INT_MAX;
    }
}
