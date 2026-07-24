<?php

declare(strict_types=1);

/**
 * Feed Forge - Google Merchant Center integration for PrestaShop
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace FeedForge\Entity;

/**
 * Data class representing a row in the ps_feedforge_sync_queue table.
 *
 * Holds queued product synchronisation jobs that are waiting to be
 * processed by the sync worker.
 */
class SyncQueue
{
    public const ACTION_INSERT = 'insert';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public int $id_feedforge_sync_queue = 0;
    public int $id_product = 0;
    public int $id_product_attribute = 0;
    public int $id_shop = 0;
    public string $action = self::ACTION_UPDATE;
    public int $priority = 0;
    public int $retries = 0;
    public int $max_retries = 3;
    public ?string $scheduled_at = null;
    public ?string $started_at = null;
    public ?string $completed_at = null;
    public string $status = self::STATUS_PENDING;
    public ?string $error_message = null;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create a SyncQueue instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_sync_queue = (int) ($data['id_feedforge_sync_queue'] ?? 0);
        $entity->id_product = (int) ($data['id_product'] ?? 0);
        $entity->id_product_attribute = (int) ($data['id_product_attribute'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->action = (string) ($data['action'] ?? self::ACTION_UPDATE);
        $entity->priority = (int) ($data['priority'] ?? 0);
        $entity->retries = (int) ($data['retries'] ?? 0);
        $entity->max_retries = (int) ($data['max_retries'] ?? 3);
        $entity->scheduled_at = $data['scheduled_at'] ?? null;
        $entity->started_at = $data['started_at'] ?? null;
        $entity->completed_at = $data['completed_at'] ?? null;
        $entity->status = (string) ($data['status'] ?? self::STATUS_PENDING);
        $entity->error_message = $data['error_message'] ?? null;
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}
