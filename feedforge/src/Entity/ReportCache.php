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
 * Data class representing a row in the ps_feedforge_report_cache table.
 *
 * Caches Google Merchant Center performance report metrics (impressions,
 * clicks, CTR) per product per country per day.
 */
class ReportCache
{
    public int $id_feedforge_report_cache = 0;
    public int $id_shop = 0;
    public ?string $report_date = null;
    public string $country_code = '';
    public int $id_product = 0;
    public int $impressions = 0;
    public int $clicks = 0;
    public float $ctr = 0.0;
    public ?string $date_add = null;

    /**
     * Create a ReportCache instance from an associative array (e.g. a database row).
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->id_feedforge_report_cache = (int) ($data['id_feedforge_report_cache'] ?? 0);
        $entity->id_shop = (int) ($data['id_shop'] ?? 0);
        $entity->report_date = $data['report_date'] ?? null;
        $entity->country_code = (string) ($data['country_code'] ?? '');
        $entity->id_product = (int) ($data['id_product'] ?? 0);
        $entity->impressions = (int) ($data['impressions'] ?? 0);
        $entity->clicks = (int) ($data['clicks'] ?? 0);
        $entity->ctr = (float) ($data['ctr'] ?? 0.0);
        $entity->date_add = $data['date_add'] ?? null;
        $entity->date_upd = $data['date_upd'] ?? null;

        return $entity;
    }
}
