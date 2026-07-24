<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Entity\FeedConfig;
use FeedForge\Repository\FeedConfigRepository;
use FeedForge\Repository\GmcProductRepository;
use FeedForge\Repository\SyncQueueRepository;
use Google\ApiCore\ApiException;

/**
 * Processes sync queue items by sending product upserts/deletes to the Merchant API.
 *
 * Migration note (v2.0.0):
 * - Content API had a `customBatch` endpoint that grouped up to ~1000 entries into a single
 *   HTTP request. We grouped queue items by action (insert/delete) and sent each batch with
 *   one API call.
 * - Merchant API has NO batch endpoint. We do sequential calls instead — one HTTP request per
 *   product per feed. This is slower (~5–10x for full syncs) but:
 *     1. Easier to debug — every error tells us exactly which product failed.
 *     2. Better progress visibility — we can update sync_log row by row.
 *     3. Naturally rate-limited by the round-trip time (no need for explicit pacing).
 * - Each insert/delete now requires the feed config's `data_source_name` (Merchant API DataSource
 *   resource), so we resolve it from FeedConfig.data_source_id before each call.
 * - Multi-feed: a single PS product can be sent to multiple feeds (one DataSource per feed).
 *   We iterate feeds inside the per-product loop, exactly like before.
 */
class QueueProcessor
{
    /** Soft pause between consecutive Merchant API requests to avoid hammering Google. */
    private const REQUEST_INTERVAL_MS = 100;

    /** @var FeedConfig[]|null Cached active feed configs for current shop */
    private ?array $activeFeedConfigs = null;
    private ?int $activeFeedConfigsShopId = null;

    public function __construct(
        private readonly ProductService $productService,
        private readonly DataSourceService $dataSourceService,
        private readonly GoogleApiClient $googleApiClient,
        private readonly SyncQueueRepository $syncQueueRepository,
        private readonly GmcProductRepository $gmcProductRepository,
        private readonly DataMapper $dataMapper,
        private readonly FeedConfigRepository $feedConfigRepository,
    ) {
    }

    /**
     * Process a batch of queue items.
     *
     * @return array{processed: int, inserted: int, updated: int, deleted: int, failed: int}
     */
    public function processBatch(int $shopId, int $batchSize, ?int $deadlineEpoch = null): array
    {
        $stats = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'failed' => 0,
        ];

        $items = $this->syncQueueRepository->fetchBatch($shopId, $batchSize);
        if (empty($items)) {
            return $stats;
        }

        $ids = array_column($items, 'id_feedforge_sync_queue');
        $this->syncQueueRepository->markProcessing($ids);

        $unprocessed = [];

        foreach ($items as $item) {
            // Honour the per-run deadline mid-batch so a single batch can't
            // overrun the HTTP timeout when batch_size is large.
            if ($deadlineEpoch !== null && time() >= $deadlineEpoch) {
                $unprocessed[] = (int) $item['id_feedforge_sync_queue'];
                continue;
            }

            $action = (string) ($item['action'] ?? 'update');
            $queueId = (int) $item['id_feedforge_sync_queue'];

            $itemStats = match ($action) {
                'delete' => $this->processDeleteItem($shopId, $queueId, $item),
                default => $this->processUpsertItem($shopId, $queueId, $item),
            };

            $stats['inserted'] += $itemStats['inserted'] ?? 0;
            $stats['updated'] += $itemStats['updated'] ?? 0;
            $stats['deleted'] += $itemStats['deleted'] ?? 0;
            $stats['failed'] += $itemStats['failed'] ?? 0;
            $stats['processed']++;

            usleep(self::REQUEST_INTERVAL_MS * 1000);
        }

        // Items we claimed but ran out of time on — release them back so the
        // next cron run picks them up instead of leaving them stuck in
        // "processing" state.
        if (!empty($unprocessed)) {
            $this->syncQueueRepository->releaseProcessing($unprocessed);
        }

        return $stats;
    }

    /**
     * Process queue items in a loop until empty or time limit reached.
     *
     * @return array{processed: int, inserted: int, updated: int, deleted: int, failed: int, batches: int}
     */
    public function processUntilDone(int $shopId, int $batchSize, int $maxExecutionSeconds): array
    {
        $startTime = time();
        $deadline = $startTime + $maxExecutionSeconds;
        $totalStats = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'failed' => 0,
            'batches' => 0,
        ];

        while (true) {
            if (time() >= $deadline) {
                break;
            }

            // Pass the deadline into the batch so it can abort mid-loop
            // rather than running a full batch past the deadline.
            $batchStats = $this->processBatch($shopId, $batchSize, $deadline);

            if ($batchStats['processed'] === 0) {
                break;
            }

            $totalStats['processed'] += $batchStats['processed'];
            $totalStats['inserted'] += $batchStats['inserted'];
            $totalStats['updated'] += $batchStats['updated'];
            $totalStats['deleted'] += $batchStats['deleted'];
            $totalStats['failed'] += $batchStats['failed'];
            $totalStats['batches']++;
        }

        return $totalStats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-item processors
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Insert/update a single product across all active feeds.
     *
     * @return array{inserted: int, updated: int, failed: int}
     */
    private function processUpsertItem(int $shopId, int $queueId, array $item): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'failed' => 0];

        $feedConfigs = $this->getActiveFeedConfigs($shopId);
        if (empty($feedConfigs)) {
            $this->syncQueueRepository->markFailed($queueId, 'No active feed configuration');
            $stats['failed']++;
            return $stats;
        }

        $productId = (int) $item['id_product'];
        $attributeId = (int) $item['id_product_attribute'];
        $action = (string) ($item['action'] ?? 'update');

        $anyMapped = false;
        $anyFailed = false;
        $errorMessages = [];

        foreach ($feedConfigs as $feedConfig) {
            $feedConfigId = $feedConfig->id_feedforge_feed_config;

            $mappedData = $this->dataMapper->mapProduct($productId, $attributeId, $shopId, $feedConfigId);
            if ($mappedData === null) {
                // Product is excluded by this feed's filters/rules — nothing to do for this feed.
                continue;
            }
            $anyMapped = true;

            // Make sure this feed has a Merchant API DataSource ready.
            try {
                $dataSourceName = $this->dataSourceService->ensureForFeed($shopId, $feedConfig);
            } catch (\Throwable $e) {
                $msg = 'DataSource provisioning failed: ' . $e->getMessage();
                $this->handleItemError($productId, $attributeId, $shopId, $feedConfigId, $msg);
                $errorMessages[] = $msg;
                $anyFailed = true;
                continue;
            }

            try {
                $result = $this->productService->insert($shopId, $mappedData, $dataSourceName);
            } catch (ApiException $e) {
                $msg = $this->extractApiError($e);
                $this->handleItemError($productId, $attributeId, $shopId, $feedConfigId, $msg);
                $errorMessages[] = sprintf('feed %d: %s', $feedConfigId, $msg);
                $anyFailed = true;
                continue;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->handleItemError($productId, $attributeId, $shopId, $feedConfigId, $msg);
                $errorMessages[] = sprintf('feed %d: %s', $feedConfigId, $msg);
                $anyFailed = true;
                continue;
            }

            // Persist the resource name + content hash so we can delete/diff later.
            $this->gmcProductRepository->upsert([
                'id_product' => $productId,
                'id_product_attribute' => $attributeId,
                'id_shop' => $shopId,
                'id_feed_config' => $feedConfigId,
                'gmc_id' => $result['name'] ?? '',
                'gmc_status' => 'pending',
                'content_hash' => $this->dataMapper->computeHash($mappedData),
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
            ]);

            if ($action === 'insert') {
                $stats['inserted']++;
            } else {
                $stats['updated']++;
            }
        }

        if (!$anyMapped) {
            // Product didn't match any feed (possibly because all feeds excluded it).
            $this->syncQueueRepository->markCompleted($queueId);
            return $stats;
        }

        if ($anyFailed) {
            $this->syncQueueRepository->markFailed($queueId, implode('; ', $errorMessages));
            $stats['failed']++;
        } else {
            $this->syncQueueRepository->markCompleted($queueId);
        }

        return $stats;
    }

    /**
     * Delete a single product from all feeds where it was previously inserted.
     *
     * @return array{deleted: int, failed: int}
     */
    private function processDeleteItem(int $shopId, int $queueId, array $item): array
    {
        $stats = ['deleted' => 0, 'failed' => 0];

        $feedConfigs = $this->getActiveFeedConfigs($shopId);
        $productId = (int) $item['id_product'];
        $attributeId = (int) $item['id_product_attribute'];

        $foundAny = false;
        $anyFailed = false;
        $errorMessages = [];

        foreach ($feedConfigs as $feedConfig) {
            $feedConfigId = $feedConfig->id_feedforge_feed_config;

            $existing = $this->gmcProductRepository->findByProduct($productId, $attributeId, $shopId, $feedConfigId);
            if (!$existing || empty($existing['gmc_id'])) {
                continue;
            }
            $foundAny = true;

            // The Merchant API requires the dataSource on delete (and the productInput name,
            // which is what we stored as gmc_id).
            try {
                $dataSourceName = $this->dataSourceService->ensureForFeed($shopId, $feedConfig);
            } catch (\Throwable $e) {
                $msg = 'DataSource provisioning failed: ' . $e->getMessage();
                $this->handleItemError($productId, $attributeId, $shopId, $feedConfigId, $msg);
                $errorMessages[] = $msg;
                $anyFailed = true;
                continue;
            }

            // gmc_id might be either the new format ("accounts/{m}/productInputs/{lang~feed~offer}")
            // or a legacy Content API id ("online:lang:CC:offer"). Convert if necessary.
            $productInputName = $this->resolveProductInputName(
                $existing['gmc_id'],
                $shopId,
                $feedConfig,
                $existing
            );

            try {
                $this->productService->delete($shopId, $productInputName, $dataSourceName);
                $this->gmcProductRepository->deleteByProduct($productId, $shopId);
                $stats['deleted']++;
            } catch (ApiException $e) {
                // 404 is benign — the product was already gone in Google.
                if ($e->getStatus() === 'NOT_FOUND' || $e->getCode() === 404) {
                    $this->gmcProductRepository->deleteByProduct($productId, $shopId);
                    $stats['deleted']++;
                    continue;
                }
                $msg = $this->extractApiError($e);
                $this->handleItemError($productId, $attributeId, $shopId, $feedConfigId, $msg);
                $errorMessages[] = sprintf('feed %d: %s', $feedConfigId, $msg);
                $anyFailed = true;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->handleItemError($productId, $attributeId, $shopId, $feedConfigId, $msg);
                $errorMessages[] = sprintf('feed %d: %s', $feedConfigId, $msg);
                $anyFailed = true;
            }
        }

        if (!$foundAny) {
            // Nothing to delete in Google — clean local row and mark done.
            $this->gmcProductRepository->deleteByProduct($productId, $shopId);
            $this->syncQueueRepository->markCompleted($queueId);
            return $stats;
        }

        if ($anyFailed) {
            $this->syncQueueRepository->markFailed($queueId, implode('; ', $errorMessages));
            $stats['failed']++;
        } else {
            $this->syncQueueRepository->markCompleted($queueId);
        }

        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the Merchant API ProductInput resource name needed for delete operations.
     * Falls back to building from feed metadata + product offer ID if gmc_id is in the
     * legacy Content API format.
     */
    private function resolveProductInputName(
        string $storedGmcId,
        int $shopId,
        FeedConfig $feedConfig,
        array $existingRow
    ): string {
        // Already in Merchant API format → use as-is.
        if (str_starts_with($storedGmcId, 'accounts/') && str_contains($storedGmcId, '/productInputs/')) {
            return $storedGmcId;
        }

        // Legacy Content API id "online:contentLanguage:targetCountry:offerId" → reconstruct.
        $merchantId = $this->googleApiClient->getMerchantId($shopId);

        $offerId = '';
        if (str_contains($storedGmcId, ':')) {
            $parts = explode(':', $storedGmcId);
            $offerId = (string) array_pop($parts);
        }

        if ($offerId === '') {
            // Last-ditch fallback: rebuild offerId from product/attribute IDs and the feed prefix.
            $bare = $existingRow['id_product_attribute'] > 0
                ? sprintf('%d-%d', $existingRow['id_product'], $existingRow['id_product_attribute'])
                : (string) $existingRow['id_product'];
            $offerId = $feedConfig->offer_id_prefix . $bare;
        }

        return $this->productService->buildProductInputName(
            $merchantId,
            $feedConfig->language_code,
            $feedConfig->country_code,
            $offerId
        );
    }

    /**
     * Update the local error column for a failed product without touching its hash/status.
     */
    private function handleItemError(int $productId, int $attributeId, int $shopId, int $feedConfigId, string $errorMsg): void
    {
        $existing = $this->gmcProductRepository->findByProduct($productId, $attributeId, $shopId, $feedConfigId);
        if ($existing) {
            $this->gmcProductRepository->upsert([
                'id_product' => $productId,
                'id_product_attribute' => $attributeId,
                'id_shop' => $shopId,
                'id_feed_config' => $feedConfigId,
                'last_error' => mb_substr($errorMsg, 0, 500),
            ]);
        }
    }

    /**
     * Pull the most useful error message from an ApiException. Falls back to the bare message.
     */
    private function extractApiError(ApiException $e): string
    {
        $message = $e->getMessage();

        // ApiException::getMessage() typically contains both the status and a JSON-encoded body.
        // We trim it to keep the queue's error_message column readable.
        return mb_substr($message, 0, 500);
    }

    /**
     * Get all active feed configurations for a shop, hydrated as FeedConfig entities.
     * Cached per request to avoid hitting the database for every queue item.
     *
     * @return FeedConfig[]
     */
    private function getActiveFeedConfigs(int $shopId): array
    {
        if ($this->activeFeedConfigsShopId === $shopId && $this->activeFeedConfigs !== null) {
            return $this->activeFeedConfigs;
        }

        $this->activeFeedConfigsShopId = $shopId;
        $rows = $this->feedConfigRepository->findActive($shopId);
        $this->activeFeedConfigs = array_map(
            static fn (array $row): FeedConfig => FeedConfig::fromArray($row),
            $rows
        );

        return $this->activeFeedConfigs;
    }
}
