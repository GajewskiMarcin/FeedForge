<?php
/**
 * Feed Forge - Google Merchant Center integration for PrestaShop 8/9
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use FeedForge\EventSubscriber\ProductSubscriber;
use FeedForge\EventSubscriber\PromotionSubscriber;

class FeedForge extends Module
{
    /** @var string[] Configuration keys used by this module */
    public const CONFIG_KEYS = [
        'FEEDFORGE_GOOGLE_CLIENT_ID',
        'FEEDFORGE_GOOGLE_CLIENT_SECRET',
        'FEEDFORGE_SYNC_INTERVAL',
        'FEEDFORGE_BATCH_SIZE',
        'FEEDFORGE_MAX_RETRIES',
        'FEEDFORGE_MAX_EXECUTION_TIME',
        'FEEDFORGE_DELTA_SYNC_ENABLED',
        'FEEDFORGE_AUTO_REMOVE_DELETED',
        'FEEDFORGE_DEBUG_LOGGING',
        'FEEDFORGE_CRON_TOKEN',
        'FEEDFORGE_TITLE_TEMPLATE',
        'FEEDFORGE_DESCRIPTION_SOURCE',
        'FEEDFORGE_GTIN_SOURCE',
        'FEEDFORGE_MPN_SOURCE',
    ];

    /** @var string[] Tabs registered by this module */
    /**
     * Tab definitions. Only the main entry is visible in the sidebar (under Secret Sauce).
     * All other tabs are hidden (id_parent = -1) and accessible via internal navigation.
     */
    private const TABS = [
        // Main visible link in Secret Sauce menu
        [
            'class_name' => 'AdminFeedForgeDashboard',
            'name' => 'Feed Forge',
            'route_name' => 'feedforge_dashboard',
            'visible' => true,
        ],
        // Hidden tabs (accessible via internal navigation)
        [
            'class_name' => 'AdminFeedForgeProducts',
            'name' => 'Feed Forge Products',
            'route_name' => 'feedforge_products',
            'visible' => false,
        ],
        [
            'class_name' => 'AdminFeedForgeQueue',
            'name' => 'Feed Forge Queue',
            'route_name' => 'feedforge_queue',
            'visible' => false,
        ],
        [
            'class_name' => 'AdminFeedForgeReports',
            'name' => 'Feed Forge Reports',
            'route_name' => 'feedforge_reports',
            'visible' => false,
        ],
        [
            'class_name' => 'AdminFeedForgeRules',
            'name' => 'Feed Forge Rules',
            'route_name' => 'feedforge_rules',
            'visible' => false,
        ],
        [
            'class_name' => 'AdminFeedForgeConfig',
            'name' => 'Feed Forge Config',
            'route_name' => 'feedforge_config',
            'visible' => false,
        ],
        [
            'class_name' => 'AdminFeedForgeSupport',
            'name' => 'Feed Forge Support',
            'route_name' => 'feedforge_support',
            'visible' => false,
        ],
    ];

    public function __construct()
    {
        $this->name = 'feedforge';
        $this->tab = 'advertising_marketing';
        $this->version = '2.0.2';
        $this->author = 'Feed Forge';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Feed Forge', [], 'Modules.Feedforge.Admin');
        $this->description = $this->trans(
            'Google Merchant Center integration via Content API for Shopping. Sync products, monitor statuses, view analytics.',
            [],
            'Modules.Feedforge.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall Feed Forge? All module data will be removed.',
            [],
            'Modules.Feedforge.Admin'
        );
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /**
     * Module installation
     */
    public function install(): bool
    {
        return parent::install()
            && $this->executeSqlFile('install')
            && $this->installTabs()
            && $this->installConfiguration()
            && $this->registerHook('actionAdminControllerSetMedia')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('actionProductSave')
            && $this->registerHook('actionUpdateQuantity')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('actionObjectProductDeleteAfter')
            && $this->registerHook('actionObjectCartRuleAddAfter')
            && $this->registerHook('actionObjectCartRuleUpdateAfter')
            && $this->registerHook('actionObjectCartRuleDeleteAfter');
    }

    /**
     * Module upgrade - apply schema changes for existing installs
     */
    public function upgrade(string $version): bool
    {
        $db = Db::getInstance();

        // v1.1.0: Add filters column to feed_config
        $table = _DB_PREFIX_ . 'feedforge_feed_config';
        $columns = $db->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'filters'");
        if (empty($columns)) {
            $db->execute(
                "ALTER TABLE `{$table}` ADD COLUMN `filters` JSON DEFAULT NULL AFTER `shipping_config`"
            );
        }

        // v1.1.0: Fix sync_queue schema (processed_at → started_at + completed_at)
        $queueTable = _DB_PREFIX_ . 'feedforge_sync_queue';
        $queueCols = $db->executeS("SHOW COLUMNS FROM `{$queueTable}` LIKE 'processed_at'");
        if (!empty($queueCols)) {
            $db->execute("ALTER TABLE `{$queueTable}` CHANGE `processed_at` `started_at` DATETIME DEFAULT NULL");
            $db->execute("ALTER TABLE `{$queueTable}` ADD COLUMN `completed_at` DATETIME DEFAULT NULL AFTER `started_at`");
        }

        // v1.3.0: Allow multiple feeds per country/lang/currency (remove UNIQUE, keep regular INDEX)
        $indexRows = $db->executeS("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_shop_country_lang_currency' AND Non_unique = 0");
        if (!empty($indexRows)) {
            $db->execute("ALTER TABLE `{$table}` DROP INDEX `idx_shop_country_lang_currency`, ADD INDEX `idx_shop_country_lang_currency` (`id_shop`, `country_code`, `language_code`, `currency_code`)");
        }

        // v1.2.0: Add promotion table
        $promoTable = _DB_PREFIX_ . 'feedforge_promotion';
        $promoExists = $db->executeS("SHOW TABLES LIKE '{$promoTable}'");
        if (empty($promoExists)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `{$promoTable}` (
                    `id_feedforge_promotion` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
                    `id_cart_rule` INT(11) UNSIGNED NOT NULL,
                    `google_promotion_id` VARCHAR(100) DEFAULT NULL,
                    `promotion_title` VARCHAR(500) NOT NULL,
                    `coupon_value_type` ENUM('MONEY_OFF','PERCENT_OFF','BUY_M_GET_N','FREE_GIFT','FREE_SHIPPING') NOT NULL,
                    `discount_value` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
                    `discount_currency` CHAR(3) DEFAULT NULL,
                    `target_country` CHAR(2) NOT NULL,
                    `content_language` VARCHAR(5) NOT NULL,
                    `redemption_channel` VARCHAR(50) NOT NULL DEFAULT 'ONLINE',
                    `product_applicability` ENUM('ALL_PRODUCTS','SPECIFIC_PRODUCTS') NOT NULL DEFAULT 'ALL_PRODUCTS',
                    `offer_type` ENUM('NO_CODE','GENERIC_CODE') NOT NULL DEFAULT 'GENERIC_CODE',
                    `coupon_code` VARCHAR(255) DEFAULT NULL,
                    `effective_dates_start` DATETIME DEFAULT NULL,
                    `effective_dates_end` DATETIME DEFAULT NULL,
                    `minimum_purchase_amount` DECIMAL(20,6) DEFAULT NULL,
                    `minimum_purchase_currency` CHAR(3) DEFAULT NULL,
                    `product_filters` JSON DEFAULT NULL,
                    `gmc_status` ENUM('pending','active','ended','rejected','unknown') NOT NULL DEFAULT 'unknown',
                    `last_sync_at` DATETIME DEFAULT NULL,
                    `last_error` TEXT DEFAULT NULL,
                    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                    `date_add` DATETIME NOT NULL,
                    `date_upd` DATETIME NOT NULL,
                    PRIMARY KEY (`id_feedforge_promotion`),
                    UNIQUE KEY `idx_shop_cart_rule` (`id_shop`, `id_cart_rule`),
                    KEY `idx_gmc_status` (`gmc_status`),
                    KEY `idx_active` (`active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // v2.0.0: Migration to Merchant API — add data_source columns and offer_id_prefix
        $dsCol = $db->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'data_source_id'");
        if (empty($dsCol)) {
            $db->execute(
                "ALTER TABLE `{$table}` "
                . "ADD COLUMN `data_source_id` VARCHAR(100) DEFAULT NULL AFTER `filters`, "
                . "ADD COLUMN `data_source_name` VARCHAR(255) DEFAULT NULL AFTER `data_source_id`, "
                . "ADD COLUMN `offer_id_prefix` VARCHAR(20) NOT NULL DEFAULT '' AFTER `data_source_name`, "
                . "ADD INDEX `idx_data_source` (`data_source_id`)"
            );
        }

        return true;
    }

    /**
     * Module uninstallation
     */
    public function uninstall(): bool
    {
        return $this->uninstallTabs()
            && $this->executeSqlFile('uninstall')
            && $this->uninstallConfiguration()
            && parent::uninstall();
    }

    /**
     * Redirect module configuration page to dashboard
     */
    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminFeedForgeDashboard')
        );
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * CSS/JS are loaded via Twig blocks in layout.html.twig (extra_stylesheets + javascripts).
     * These hook methods must exist even if empty — PS9 validates registered hooks.
     */
    public function hookActionAdminControllerSetMedia(): void
    {
    }

    public function hookDisplayBackOfficeHeader(): void
    {
    }

    /**
     * Hook: product saved (create/update)
     */
    public function hookActionProductSave(array $params): void
    {
        $this->getProductSubscriber()?->onProductSave($params);
    }

    /**
     * Hook: stock quantity updated
     */
    public function hookActionUpdateQuantity(array $params): void
    {
        $this->getProductSubscriber()?->onQuantityUpdate($params);
    }

    /**
     * Hook: product deleted
     */
    public function hookActionProductDelete(array $params): void
    {
        $this->getProductSubscriber()?->onProductDelete($params);
    }

    /**
     * Hook: product object deleted (fallback)
     */
    public function hookActionObjectProductDeleteAfter(array $params): void
    {
        $this->getProductSubscriber()?->onProductObjectDelete($params);
    }

    /**
     * Hook: cart rule created
     */
    public function hookActionObjectCartRuleAddAfter(array $params): void
    {
        $this->getPromotionSubscriber()?->onCartRuleChange($params);
    }

    /**
     * Hook: cart rule updated
     */
    public function hookActionObjectCartRuleUpdateAfter(array $params): void
    {
        $this->getPromotionSubscriber()?->onCartRuleChange($params);
    }

    /**
     * Hook: cart rule deleted
     */
    public function hookActionObjectCartRuleDeleteAfter(array $params): void
    {
        $this->getPromotionSubscriber()?->onCartRuleDelete($params);
    }

    // -------------------------------------------------------------------------
    // Installation helpers
    // -------------------------------------------------------------------------

    /**
     * Execute SQL file (install or uninstall)
     */
    private function executeSqlFile(string $filename): bool
    {
        $filePath = __DIR__ . '/sql/' . $filename . '.sql';

        if (!file_exists($filePath)) {
            return false;
        }

        $sql = file_get_contents($filePath);
        if ($sql === false) {
            return false;
        }

        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn (string $s) => $s !== ''
        );

        foreach ($statements as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install admin tabs using the shared "Secret Sauce" navigation group.
     */
    private function installTabs(): bool
    {
        // ── Step 1: Find or create "Secret Sauce" category ──
        $secretSauceClass = 'AdminSecretSauce';
        $secretSauceId = (int) Tab::getIdFromClassName($secretSauceClass);

        if (!$secretSauceId) {
            $improveId = (int) Tab::getIdFromClassName('IMPROVE');
            if (!$improveId) {
                $improveId = (int) Tab::getIdFromClassName('AdminParentModulesSf');
            }

            $secretSauce = new Tab();
            $secretSauce->active = 1;
            $secretSauce->class_name = $secretSauceClass;
            $secretSauce->id_parent = $improveId ?: 0;
            $secretSauce->module = ''; // No owner — shared across modules
            if (property_exists($secretSauce, 'icon')) {
                $secretSauce->icon = 'science';
            }
            foreach (Language::getLanguages(false) as $lang) {
                $secretSauce->name[(int) $lang['id_lang']] = 'Secret Sauce';
            }
            if (!$secretSauce->add()) {
                return false;
            }
            $secretSauceId = (int) $secretSauce->id;
        }

        // ── Step 2: Remove old root tab "AdminFeedForge" if it exists ──
        $oldRootId = (int) Tab::getIdFromClassName('AdminFeedForge');
        if ($oldRootId) {
            $oldRoot = new Tab($oldRootId);
            $oldRoot->delete();
        }

        // ── Step 3: Install module tabs ──
        foreach (self::TABS as $tabData) {
            $existingId = (int) Tab::getIdFromClassName($tabData['class_name']);

            if ($existingId) {
                $tab = new Tab($existingId);
                $tab->id_parent = $tabData['visible'] ? $secretSauceId : -1;
                $tab->active = 1;
                if (!empty($tabData['route_name'])) {
                    $tab->route_name = $tabData['route_name'];
                }
                $tab->save();
                continue;
            }

            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $tabData['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = $tabData['visible'] ? $secretSauceId : -1;

            if (!empty($tabData['route_name'])) {
                $tab->route_name = $tabData['route_name'];
            }

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int) $lang['id_lang']] = $tabData['name'];
            }

            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Uninstall admin tabs. Removes only Feed Forge tabs.
     * Secret Sauce category is removed only when it has no remaining children.
     */
    private function uninstallTabs(): bool
    {
        // Remove Feed Forge tabs
        foreach (self::TABS as $tabData) {
            $tabId = (int) Tab::getIdFromClassName($tabData['class_name']);
            if ($tabId) {
                $tab = new Tab($tabId);
                $tab->delete();
            }
        }

        // Remove legacy root tab if still present
        $oldRootId = (int) Tab::getIdFromClassName('AdminFeedForge');
        if ($oldRootId) {
            $oldRoot = new Tab($oldRootId);
            $oldRoot->delete();
        }

        // Remove Secret Sauce only if it has no remaining children
        $secretSauceId = (int) Tab::getIdFromClassName('AdminSecretSauce');
        if ($secretSauceId) {
            $children = Tab::getTabs(Context::getContext()->language->id, $secretSauceId);
            if (empty($children)) {
                $ss = new Tab($secretSauceId);
                $ss->delete();
            }
        }

        return true;
    }

    /**
     * Install default configuration values
     */
    private function installConfiguration(): bool
    {
        $defaults = [
            'FEEDFORGE_GOOGLE_CLIENT_ID' => '',
            'FEEDFORGE_GOOGLE_CLIENT_SECRET' => '',
            'FEEDFORGE_SYNC_INTERVAL' => '4', // hours
            'FEEDFORGE_BATCH_SIZE' => '100',
            'FEEDFORGE_MAX_RETRIES' => '5',
            'FEEDFORGE_MAX_EXECUTION_TIME' => '300', // seconds
            'FEEDFORGE_DELTA_SYNC_ENABLED' => '1',
            'FEEDFORGE_AUTO_REMOVE_DELETED' => '1',
            'FEEDFORGE_DEBUG_LOGGING' => '0',
            'FEEDFORGE_CRON_TOKEN' => bin2hex(random_bytes(16)),
            'FEEDFORGE_TITLE_TEMPLATE' => '{name}',
            'FEEDFORGE_DESCRIPTION_SOURCE' => 'description_short',
            'FEEDFORGE_GTIN_SOURCE' => 'ean',
            'FEEDFORGE_MPN_SOURCE' => 'reference',
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove configuration values
     */
    private function uninstallConfiguration(): bool
    {
        foreach (self::CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Get ProductSubscriber service (lazy load)
     */
    private function getProductSubscriber(): ?ProductSubscriber
    {
        try {
            /** @var ProductSubscriber $subscriber */
            $subscriber = $this->get('FeedForge\EventSubscriber\ProductSubscriber');

            return $subscriber;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get PromotionSubscriber service (lazy load)
     */
    private function getPromotionSubscriber(): ?PromotionSubscriber
    {
        try {
            /** @var PromotionSubscriber $subscriber */
            $subscriber = $this->get('FeedForge\EventSubscriber\PromotionSubscriber');

            return $subscriber;
        } catch (\Exception $e) {
            return null;
        }
    }
}
