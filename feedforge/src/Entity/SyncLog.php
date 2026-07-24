<?php

declare(strict_types=1);

/**
 * Feed Forge - Google Merchant Center integration for PrestaShop
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   MIT
 */

namespace FeedForge\Entity;

/**
 * Data class representing a row in the ps_feedforge_sync_log table.
 *
 * Records the outcome of each synchronisation run, including counters
 * for processed, inserted, updated, deleted, and failed products.
 */
class SyncLog
{
    public const SYNC_TYPE_FULL = 'full';
    public const SYNC_TYPE_DELTA = 'delta';
    public const SYNC_TYPE_MANUAL = 'manual';
    public const SYNC_TYPE_HOOK = 'hook';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public int $id_feedforge_sync_log = 0;
    public int $id_shop = 0;
    public string $sync_type = self::SYNC_TYPE_DELTA;
    public ?string $started_at = null;
    public ?string $finished_at = null;
    public int $products_processed = 0;
    public int $products_inserted = 0;
    public int $products_updated = 0;
    public int $products_deleted = 0;
    public int $products_failed = 0;
    public string $status = self::STATUS_RUNNING;
    public ?string $error_summary = null;
    public ?string $date_add = null;

    /**
     * Create a SyncLog instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_sync_log = (int) ($data['id_feedforge_sync_log'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->sync_type = (string) ($data['sync_type'] ?? self::SYNC_TYPE_DELTA);
        $entity->started_at = $data['started_at'] ?? null;
        $entity->finished_at = $data['finished_at'] ?? null;
        $entity->products_processed = (int) ($data['products_processed'] ?? 0);
        $entity->products_inserted = (int) ($data['products_inserted'] ?? 0);
        $entity->products_updated = (int) ($data['products_updated'] ?? 0);
        $entity->products_deleted = (int) ($data['products_deleted'] ?? 0);
        $entity->products_failed = (int) ($data['products_failed'] ?? 0);
        $entity->status = (string) ($data['status'] ?? self::STATUS_RUNNING);
        $entity->error_summary = $data['error_summary'] ?? null;
        $entity->date_add = $data['date_add'] ?? null;

        return $entity;
    }
}
