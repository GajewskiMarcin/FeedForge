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
 * Data class representing a row in the ps_feedforge_category_map table.
 *
 * Maps a PrestaShop category to a Google product taxonomy entry,
 * enabling automatic google_product_category assignment.
 */
class CategoryMap
{
    public int $id_category_map = 0;
    public int $id_category = 0;
    public int $id_shop = 0;
    public int $google_taxonomy_id = 0;
    public string $google_taxonomy_path = '';
    public bool $active = true;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create a CategoryMap instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_category_map = (int) ($data['id_category_map'] ?? 0);
        $entity->id_category = (int) ($data['id_category'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->google_taxonomy_id = (int) ($data['google_taxonomy_id'] ?? 0);
        $entity->google_taxonomy_path = (string) ($data['google_taxonomy_path'] ?? '');
        $entity->active = (bool) ($data['active'] ?? true);
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}
