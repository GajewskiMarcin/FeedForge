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
 * Data class representing a row in the ps_feedforge_attribute_map table.
 *
 * Defines how a Google Merchant Center product field is populated from
 * a PrestaShop data source (attribute group, feature, fixed field, or
 * a custom value with an optional transform).
 */
class AttributeMap
{
    public const SOURCE_TYPE_ATTRIBUTE_GROUP = 'attribute_group';
    public const SOURCE_TYPE_FEATURE = 'feature';
    public const SOURCE_TYPE_FIELD = 'field';
    public const SOURCE_TYPE_CUSTOM = 'custom';

    public int $id_attribute_map = 0;
    public int $id_shop = 0;
    public string $gmc_field = '';
    public string $ps_source_type = self::SOURCE_TYPE_FIELD;
    public int $ps_source_id = 0;
    public string $ps_source_name = '';
    public string $transform = '';
    public bool $active = true;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create an AttributeMap instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_attribute_map = (int) ($data['id_attribute_map'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->gmc_field = (string) ($data['gmc_field'] ?? '');
        $entity->ps_source_type = (string) ($data['ps_source_type'] ?? self::SOURCE_TYPE_FIELD);
        $entity->ps_source_id = (int) ($data['ps_source_id'] ?? 0);
        $entity->ps_source_name = (string) ($data['ps_source_name'] ?? '');
        $entity->transform = (string) ($data['transform'] ?? '');
        $entity->active = (bool) ($data['active'] ?? true);
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}
