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
 * Data class representing a row in the ps_feedforge_rule table.
 *
 * Defines a user-configured rule that modifies product data during
 * synchronisation (e.g. exclusion filters, pricing adjustments,
 * identifier overrides, or custom-label assignments).
 */
class SyncRule
{
    public const TYPE_EXCLUSION = 'exclusion';
    public const TYPE_PRICING = 'pricing';
    public const TYPE_IDENTIFIER = 'identifier';
    public const TYPE_CUSTOM_LABEL = 'custom_label';

    public int $id_feedforge_rule = 0;
    public int $id_shop = 0;
    public string $type = self::TYPE_EXCLUSION;
    public string $name = '';
    /** @var array<mixed> */
    public array $conditions = [];
    /** @var array<mixed> */
    public array $actions = [];
    public int $priority = 0;
    public bool $active = true;
    public ?string $date_add = null;
    public ?string $date_upd = null;

    /**
     * Create a SyncRule instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_rule = (int) ($data['id_feedforge_rule'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->type = (string) ($data['type'] ?? self::TYPE_EXCLUSION);
        $entity->name = (string) ($data['name'] ?? '');
        $entity->conditions = self::decodeJson($data['conditions'] ?? []);
        $entity->actions = self::decodeJson($data['actions'] ?? []);
        $entity->priority = (int) ($data['priority'] ?? 0);
        $entity->active = (bool) ($data['active'] ?? true);
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
