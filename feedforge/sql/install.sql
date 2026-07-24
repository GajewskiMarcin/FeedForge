CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_account` (
    `id_feedforge_account` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `merchant_id` VARCHAR(50) DEFAULT NULL,
    `merchant_name` VARCHAR(255) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `access_token` TEXT DEFAULT NULL,
    `refresh_token` TEXT DEFAULT NULL,
    `token_expires_at` DATETIME DEFAULT NULL,
    `encryption_iv` VARCHAR(255) DEFAULT NULL,
    `gcp_registered` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_account`),
    UNIQUE KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_product` (
    `id_feedforge_product` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_product` INT(11) UNSIGNED NOT NULL,
    `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `id_feed_config` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `gmc_id` VARCHAR(255) DEFAULT NULL,
    `gmc_status` ENUM('approved','pending','disapproved','expiring','unknown') NOT NULL DEFAULT 'unknown',
    `content_hash` CHAR(40) DEFAULT NULL,
    `last_sync_at` DATETIME DEFAULT NULL,
    `last_error` TEXT DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_product`),
    UNIQUE KEY `idx_product_attr_shop_feed` (`id_product`, `id_product_attribute`, `id_shop`, `id_feed_config`),
    KEY `idx_gmc_status` (`gmc_status`),
    KEY `idx_content_hash` (`content_hash`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_sync_queue` (
    `id_feedforge_sync_queue` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_product` INT(11) UNSIGNED NOT NULL,
    `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `action` ENUM('insert','update','delete') NOT NULL DEFAULT 'update',
    `priority` TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
    `retries` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    `max_retries` TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
    `scheduled_at` DATETIME NOT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_sync_queue`),
    KEY `idx_status_priority_scheduled` (`status`, `priority`, `scheduled_at`),
    KEY `idx_product` (`id_product`, `id_product_attribute`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_product_status` (
    `id_feedforge_product_status` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_feedforge_product` INT(11) UNSIGNED NOT NULL,
    `issue_severity` ENUM('critical','error','warning','suggestion') NOT NULL,
    `issue_code` VARCHAR(100) NOT NULL,
    `issue_message` TEXT NOT NULL,
    `issue_detail` TEXT DEFAULT NULL,
    `destination` VARCHAR(100) NOT NULL DEFAULT 'Shopping',
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_product_status`),
    KEY `idx_feedforge_product` (`id_feedforge_product`),
    KEY `idx_severity` (`issue_severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_category_map` (
    `id_feedforge_category_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_category` INT(11) UNSIGNED NOT NULL,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `google_taxonomy_id` INT(11) UNSIGNED DEFAULT NULL,
    `google_taxonomy_path` TEXT DEFAULT NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_category_map`),
    UNIQUE KEY `idx_category_shop` (`id_category`, `id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_taxonomy` (
    `id_feedforge_taxonomy` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `taxonomy_id` INT(11) UNSIGNED NOT NULL,
    `value` TEXT NOT NULL,
    `lang` VARCHAR(5) NOT NULL DEFAULT 'en',
    PRIMARY KEY (`id_feedforge_taxonomy`),
    KEY `idx_lang` (`lang`),
    FULLTEXT KEY `ft_value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_rule` (
    `id_feedforge_rule` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `type` ENUM('exclusion','pricing','identifier','custom_label') NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `conditions` JSON NOT NULL,
    `actions` JSON DEFAULT NULL,
    `priority` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_rule`),
    KEY `idx_type_active_priority` (`type`, `active`, `priority`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_report_cache` (
    `id_feedforge_report_cache` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `report_date` DATE NOT NULL,
    `country_code` CHAR(2) NOT NULL DEFAULT '',
    `id_product` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `impressions` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `clicks` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `ctr` DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_report_cache`),
    UNIQUE KEY `idx_date_country_product_shop` (`report_date`, `country_code`, `id_product`, `id_shop`),
    KEY `idx_report_date` (`report_date`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_sync_log` (
    `id_feedforge_sync_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `sync_type` ENUM('full','delta','manual','hook') NOT NULL,
    `started_at` DATETIME NOT NULL,
    `finished_at` DATETIME DEFAULT NULL,
    `products_processed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_inserted` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_updated` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_deleted` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_failed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('running','completed','failed','cancelled') NOT NULL DEFAULT 'running',
    `error_summary` TEXT DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_sync_log`),
    KEY `idx_status` (`status`),
    KEY `idx_started_at` (`started_at`),
    KEY `idx_shop` (`id_shop`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_feed_config` (
    `id_feedforge_feed_config` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `country_code` CHAR(2) NOT NULL,
    `language_code` VARCHAR(5) NOT NULL,
    `currency_code` CHAR(3) NOT NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `title_template` VARCHAR(500) NOT NULL DEFAULT '{name}',
    `description_source` ENUM('description','description_short','both') NOT NULL DEFAULT 'description_short',
    `include_tax` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `shipping_config` JSON DEFAULT NULL,
    `filters` JSON DEFAULT NULL,
    `data_source_id` VARCHAR(100) DEFAULT NULL,
    `data_source_name` VARCHAR(255) DEFAULT NULL,
    `offer_id_prefix` VARCHAR(20) NOT NULL DEFAULT '',
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_feed_config`),
    KEY `idx_shop_country_lang_currency` (`id_shop`, `country_code`, `language_code`, `currency_code`),
    KEY `idx_data_source` (`data_source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_promotion` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PREFIX_feedforge_attribute_map` (
    `id_feedforge_attribute_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `gmc_field` VARCHAR(100) NOT NULL,
    `ps_source_type` ENUM('attribute_group','feature','field','custom') NOT NULL,
    `ps_source_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `ps_source_name` VARCHAR(255) DEFAULT NULL,
    `transform` VARCHAR(100) DEFAULT NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_feedforge_attribute_map`),
    UNIQUE KEY `idx_shop_gmc_field` (`id_shop`, `gmc_field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
