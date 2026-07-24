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
 * Data class representing a row in the ps_feedforge_product table.
 *
 * Tracks the synchronisation state of a PrestaShop product (or combination)
 * with its corresponding Google Merchant Center listing.
 */
class GmcProduct
{
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISAPPROVED = 'disapproved';
    public const STATUS_EXPIRING = 'expiring';
    public const STATUS_UNKNOWN = 'unknown';

    public int $id_feedforge_product = 0;
    public int $id_product = 0;
    public int $id_product_attribute = 0;
    public int $id_shop = 0;
    public int $id_feed_config = 0;
    public string $gmc_id = '';
    public string $gmc_status = self::STATUS_UNKNOWN;
    public string $content_hash = '';
    public ?string $last_sync_at = null;
    public ?string $last_error = null;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create a GmcProduct instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_product = (int) ($data['id_feedforge_product'] ?? 0);
        $entity->id_product = (int) ($data['id_product'] ?? 0);
        $entity->id_product_attribute = (int) ($data['id_product_attribute'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->id_feed_config = (int) ($data['id_feed_config'] ?? 0);
        $entity->gmc_id = (string) ($data['gmc_id'] ?? '');
        $entity->gmc_status = (string) ($data['gmc_status'] ?? self::STATUS_UNKNOWN);
        $entity->content_hash = (string) ($data['content_hash'] ?? '');
        $entity->last_sync_at = $data['last_sync_at'] ?? null;
        $entity->last_error = $data['last_error'] ?? null;
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}
