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
 * Data class representing a row in the ps_feedforge_product_status table.
 *
 * Stores individual issues reported by Google Merchant Center for a
 * synchronised product, such as disapproval reasons or data-quality warnings.
 */
class ProductStatus
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_SUGGESTION = 'suggestion';

    public int $id_feedforge_product_status = 0;
    public int $id_feedforge_product = 0;
    public string $issue_severity = self::SEVERITY_WARNING;
    public string $issue_code = '';
    public string $issue_message = '';
    public string $issue_detail = '';
    public string $destination = '';
    public ?string $date_add = null;

    /**
     * Create a ProductStatus instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_product_status = (int) ($data['id_feedforge_product_status'] ?? 0);
        $entity->id_feedforge_product = (int) ($data['id_feedforge_product'] ?? 0);
        $entity->issue_severity = (string) ($data['issue_severity'] ?? self::SEVERITY_WARNING);
        $entity->issue_code = (string) ($data['issue_code'] ?? '');
        $entity->issue_message = (string) ($data['issue_message'] ?? '');
        $entity->issue_detail = (string) ($data['issue_detail'] ?? '');
        $entity->destination = (string) ($data['destination'] ?? '');
        $entity->date_add = $data['date_add'] ?? null;

        return $entity;
    }
}
