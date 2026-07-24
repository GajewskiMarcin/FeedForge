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
 * Data class representing a row in the ps_feedforge_taxonomy table.
 *
 * Holds a single entry from the Google product taxonomy list, used for
 * category-mapping look-ups and autocomplete suggestions.
 */
class Taxonomy
{
    public int $id_feedforge_taxonomy = 0;
    public int $taxonomy_id = 0;
    public string $value = '';
    public string $lang = '';

    /**
     * Create a Taxonomy instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_taxonomy = (int) ($data['id_feedforge_taxonomy'] ?? 0);
        $entity->taxonomy_id = (int) ($data['taxonomy_id'] ?? 0);
        $entity->value = (string) ($data['value'] ?? '');
        $entity->lang = (string) ($data['lang'] ?? '');

        return $entity;
    }
}
