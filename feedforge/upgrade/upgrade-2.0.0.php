<?php
/**
 * Feed Forge - upgrade to v2.0.0 (Merchant API migration)
 *
 * Adds the columns required to bind feed configs to Merchant API DataSources:
 * - data_source_id   — short numeric portion of the DataSource resource name
 * - data_source_name — full resource name (accounts/{merchant}/dataSources/{id})
 * - offer_id_prefix  — optional prefix prepended to offer IDs (e.g. "PL" → "PL123")
 *
 * Idempotent: every ALTER is gated on a SHOW COLUMNS check so re-running this
 * upgrade against an already-upgraded shop is a no-op.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0(Module $module): bool
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'feedforge_feed_config';

    // Check whether the new column already exists — if so, the upgrade has already run.
    $existing = $db->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'data_source_id'");
    if (!empty($existing)) {
        return true;
    }

    return (bool) $db->execute(
        "ALTER TABLE `{$table}` "
        . "ADD COLUMN `data_source_id` VARCHAR(100) DEFAULT NULL AFTER `filters`, "
        . "ADD COLUMN `data_source_name` VARCHAR(255) DEFAULT NULL AFTER `data_source_id`, "
        . "ADD COLUMN `offer_id_prefix` VARCHAR(20) NOT NULL DEFAULT '' AFTER `data_source_name`, "
        . "ADD INDEX `idx_data_source` (`data_source_id`)"
    );
}
