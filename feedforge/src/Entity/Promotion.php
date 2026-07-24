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
 * Data class representing a row in the ps_feedforge_promotion table.
 *
 * Maps PrestaShop cart rules to Google Merchant Center promotions.
 */
class Promotion
{
    public const VALUE_TYPE_MONEY_OFF = 'MONEY_OFF';
    public const VALUE_TYPE_PERCENT_OFF = 'PERCENT_OFF';
    public const VALUE_TYPE_FREE_SHIPPING = 'FREE_SHIPPING';

    public const OFFER_TYPE_NO_CODE = 'NO_CODE';
    public const OFFER_TYPE_GENERIC_CODE = 'GENERIC_CODE';

    public const APPLICABILITY_ALL = 'ALL_PRODUCTS';
    public const APPLICABILITY_SPECIFIC = 'SPECIFIC_PRODUCTS';

    public int $id_feedforge_promotion = 0;
    public int $id_shop = 0;
    public int $id_cart_rule = 0;
    public ?string $google_promotion_id = null;
    public string $promotion_title = '';
    public string $coupon_value_type = self::VALUE_TYPE_PERCENT_OFF;
    public float $discount_value = 0.0;
    public ?string $discount_currency = null;
    public string $target_country = '';
    public string $content_language = '';
    public string $redemption_channel = 'ONLINE';
    public string $product_applicability = self::APPLICABILITY_ALL;
    public string $offer_type = self::OFFER_TYPE_GENERIC_CODE;
    public ?string $coupon_code = null;
    public ?string $effective_dates_start = null;
    public ?string $effective_dates_end = null;
    public ?float $minimum_purchase_amount = null;
    public ?string $minimum_purchase_currency = null;
    public ?array $product_filters = null;
    public string $gmc_status = 'unknown';
    public ?string $last_sync_at = null;
    public ?string $last_error = null;
    public bool $active = true;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create a Promotion instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_promotion = (int) ($data['id_feedforge_promotion'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->id_cart_rule = (int) ($data['id_cart_rule'] ?? 0);
        $entity->google_promotion_id = $data['google_promotion_id'] ?? null;
        $entity->promotion_title = (string) ($data['promotion_title'] ?? '');
        $entity->coupon_value_type = (string) ($data['coupon_value_type'] ?? self::VALUE_TYPE_PERCENT_OFF);
        $entity->discount_value = (float) ($data['discount_value'] ?? 0.0);
        $entity->discount_currency = $data['discount_currency'] ?? null;
        $entity->target_country = (string) ($data['target_country'] ?? '');
        $entity->content_language = (string) ($data['content_language'] ?? '');
        $entity->redemption_channel = (string) ($data['redemption_channel'] ?? 'ONLINE');
        $entity->product_applicability = (string) ($data['product_applicability'] ?? self::APPLICABILITY_ALL);
        $entity->offer_type = (string) ($data['offer_type'] ?? self::OFFER_TYPE_GENERIC_CODE);
        $entity->coupon_code = $data['coupon_code'] ?? null;
        $entity->effective_dates_start = $data['effective_dates_start'] ?? null;
        $entity->effective_dates_end = $data['effective_dates_end'] ?? null;
        $entity->minimum_purchase_amount = isset($data['minimum_purchase_amount']) ? (float) $data['minimum_purchase_amount'] : null;
        $entity->minimum_purchase_currency = $data['minimum_purchase_currency'] ?? null;
        $entity->product_filters = isset($data['product_filters']) && is_string($data['product_filters'])
            ? json_decode($data['product_filters'], true)
            : ($data['product_filters'] ?? null);
        $entity->gmc_status = (string) ($data['gmc_status'] ?? 'unknown');
        $entity->last_sync_at = $data['last_sync_at'] ?? null;
        $entity->last_error = $data['last_error'] ?? null;
        $entity->active = (bool) ($data['active'] ?? true);
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}
