<?php
/**
 * Feed Forge - upgrade to v2.0.2
 *
 * Adds the gcp_registered column to ps_feedforge_account so we can cache whether the
 * GCP project has already been registered as a Merchant API developer for this account
 * (avoids hitting Google on every page render just to know which UI state to show).
 *
 * Idempotent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_2(Module $module): bool
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'feedforge_account';

    $existing = $db->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'gcp_registered'");
    if (!empty($existing)) {
        return true;
    }

    return (bool) $db->execute(
        "ALTER TABLE `{$table}` "
        . "ADD COLUMN `gcp_registered` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `encryption_iv`"
    );
}
