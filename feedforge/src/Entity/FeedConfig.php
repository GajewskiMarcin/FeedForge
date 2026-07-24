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
 * Data class representing a row in the ps_feedforge_feed_config table.
 *
 * Holds the per-shop feed configuration that controls which country,
 * language, and currency are used when building product data for
 * Google Merchant Center.
 */
class FeedConfig
{
    public const DESCRIPTION_SOURCE_DESCRIPTION = 'description';
    public const DESCRIPTION_SOURCE_SHORT = 'description_short';
    public const DESCRIPTION_SOURCE_BOTH = 'both';

    public int $id_feedforge_feed_config = 0;
    public int $id_shop = 0;
    public string $country_code = '';
    public string $language_code = '';
    public string $currency_code = '';
    public bool $active = true;
    public string $title_template = '';
    public string $description_source = self::DESCRIPTION_SOURCE_DESCRIPTION;
    public bool $include_tax = false;
    /** @var array<mixed> */
    public array $shipping_config = [];
    /** @var array<mixed> */
    public array $filters = [];
    public ?string $data_source_id = null;
    public ?string $data_source_name = null;
    public string $offer_id_prefix = '';
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create a FeedConfig instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_feed_config = (int) ($data['id_feedforge_feed_config'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->country_code = (string) ($data['country_code'] ?? '');
        $entity->language_code = (string) ($data['language_code'] ?? '');
        $entity->currency_code = (string) ($data['currency_code'] ?? '');
        $entity->active = (bool) ($data['active'] ?? true);
        $entity->title_template = (string) ($data['title_template'] ?? '');
        $entity->description_source = (string) ($data['description_source'] ?? self::DESCRIPTION_SOURCE_DESCRIPTION);
        $entity->include_tax = (bool) ($data['include_tax'] ?? false);
        $entity->shipping_config = self::decodeJson($data['shipping_config'] ?? []);
        $entity->filters = self::decodeJson($data['filters'] ?? []);
        $entity->data_source_id = isset($data['data_source_id']) && $data['data_source_id'] !== '' ? (string) $data['data_source_id'] : null;
        $entity->data_source_name = isset($data['data_source_name']) && $data['data_source_name'] !== '' ? (string) $data['data_source_name'] : null;
        $entity->offer_id_prefix = (string) ($data['offer_id_prefix'] ?? '');
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }

    /**
     * Decode a value that may be a JSON string or already an array.
     *
     * @return array<mixed>
     */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
