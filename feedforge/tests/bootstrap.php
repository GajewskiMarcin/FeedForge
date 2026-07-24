<?php

declare(strict_types=1);

// Minimal bootstrap for unit tests outside PrestaShop environment.
// Defines stubs for PS globals so services can be instantiated.

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!defined('_COOKIE_KEY_')) {
    define('_COOKIE_KEY_', 'test_cookie_key_for_phpunit_feedforge_2024');
}

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';
