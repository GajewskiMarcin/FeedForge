<?php

declare(strict_types=1);

namespace FeedForge\Controller\Admin;

use PrestaShopBundle\Service\Routing\Router as PsRouter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Main page controller - renders Twig templates for each admin section.
 * Each action corresponds to a tab in the Feed Forge navigation group.
 */
class FeedForgeController extends BaseController
{
    private PsRouter $psRouter;

    public function __construct(PsRouter $psRouter)
    {
        $this->psRouter = $psRouter;
    }

    /**
     * Dashboard - overview of sync status, stats, issues
     */
    public function dashboardAction(): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/dashboard.html.twig', array_merge([
            'page' => 'dashboard',
            'nav' => $this->getNavigation('dashboard'),
            'page_urls' => $this->getPageUrls(),
        ], $this->getToolbarParams()));
    }

    /**
     * Products - list of products with GMC sync status
     */
    public function productsAction(): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/products.html.twig', array_merge([
            'page' => 'products',
            'nav' => $this->getNavigation('products'),
        ], $this->getToolbarParams()));
    }

    /**
     * Product detail - single product GMC data, status, errors, history
     */
    public function productDetailAction(int $productId): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/product_detail.html.twig', array_merge([
            'page' => 'product_detail',
            'productId' => $productId,
            'nav' => $this->getNavigation('products'),
        ], $this->getToolbarParams()));
    }

    /**
     * Sync Queue - view and manage synchronization queue
     */
    public function queueAction(): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/queue.html.twig', array_merge([
            'page' => 'queue',
            'nav' => $this->getNavigation('queue'),
        ], $this->getToolbarParams()));
    }

    /**
     * Reports & Analytics - impressions, clicks, CTR from GMC
     */
    public function reportsAction(): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/reports.html.twig', array_merge([
            'page' => 'reports',
            'nav' => $this->getNavigation('reports'),
        ], $this->getToolbarParams()));
    }

    /**
     * Rules & Mapping - exclusions, pricing, identifiers, categories, custom labels
     */
    public function rulesAction(): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/rules.html.twig', array_merge([
            'page' => 'rules',
            'nav' => $this->getNavigation('rules'),
            'page_urls' => $this->getPageUrls(),
        ], $this->getToolbarParams()));
    }

    /**
     * Feed edit/create - dedicated subpage with product filtering
     */
    public function feedEditAction(int $feedId = 0): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/feed_edit.html.twig', array_merge([
            'page' => 'feed_edit',
            'feedId' => $feedId,
            'nav' => $this->getNavigation('configuration'),
        ], $this->getToolbarParams()));
    }

    /**
     * Configuration - OAuth, feeds, sync settings, attribute mapping
     */
    public function configAction(): Response
    {
        $oauthConnectUrl = $this->generateTokenizedUrl('feedforge_oauth_connect', 'AdminFeedForgeConfig');

        return $this->render('@Modules/feedforge/views/templates/admin/configuration.html.twig', array_merge([
            'page' => 'configuration',
            'oauthConnectUrl' => $oauthConnectUrl,
            'nav' => $this->getNavigation('configuration'),
        ], $this->getToolbarParams()));
    }

    /**
     * Support - Buy me a coffee, links, info
     */
    public function supportAction(): Response
    {
        return $this->render('@Modules/feedforge/views/templates/admin/support.html.twig', array_merge([
            'page' => 'support',
            'nav' => $this->getNavigation('support'),
        ], $this->getToolbarParams()));
    }

    /**
     * Build standard PS toolbar params.
     */
    private function getToolbarParams(): array
    {
        $module = \Module::getInstanceByName('feedforge');
        $helpLink = $this->generateSidebarLink('FeedForge', 'Feed Forge');

        $jsPath = _PS_MODULE_DIR_ . 'feedforge/views/js/feedforge.js';
        $cssPath = _PS_MODULE_DIR_ . 'feedforge/views/css/feedforge.css';
        $jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : '1';
        $cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : '1';

        return [
            'layoutTitle' => $module ? $module->displayName : 'Feed Forge',
            'layoutHeaderToolbarBtn' => $this->getToolbarButtons(),
            'help_link' => $helpLink,
            'enableSidebar' => !empty($helpLink),
            'jsTranslations' => $this->getJsTranslations(),
            'moduleVersion' => $module ? $module->version : '1.0.0',
            'jsVersion' => $jsVersion,
            'cssVersion' => $cssVersion,
        ];
    }

    /**
     * Build flat JS translation dictionary passed via data-translations attribute.
     */
    private function getJsTranslations(): array
    {
        return [
            // Action
            'action.delete' => $this->trans('Delete', 'Modules.Feedforge.Admin'),
            'action.insert' => $this->trans('Insert', 'Modules.Feedforge.Admin'),
            'action.update' => $this->trans('Update', 'Modules.Feedforge.Admin'),

            // Common
            'common.active' => $this->trans('Active', 'Modules.Feedforge.Admin'),
            'common.cancel' => $this->trans('Cancel', 'Modules.Feedforge.Admin'),
            'common.delete' => $this->trans('Delete', 'Modules.Feedforge.Admin'),
            'common.details' => $this->trans('Details', 'Modules.Feedforge.Admin'),
            'common.edit' => $this->trans('Edit', 'Modules.Feedforge.Admin'),
            'common.error' => $this->trans('Error', 'Modules.Feedforge.Admin'),
            'common.inactive' => $this->trans('Inactive', 'Modules.Feedforge.Admin'),
            'common.no' => $this->trans('No', 'Modules.Feedforge.Admin'),
            'common.products' => $this->trans('products', 'Modules.Feedforge.Admin'),
            'common.save' => $this->trans('Save', 'Modules.Feedforge.Admin'),
            'common.value' => $this->trans('Value', 'Modules.Feedforge.Admin'),
            'common.yes' => $this->trans('Yes', 'Modules.Feedforge.Admin'),
            'common.error_prefix' => $this->trans('Error: ', 'Modules.Feedforge.Admin'),
            'common.time_just_now' => $this->trans('just now', 'Modules.Feedforge.Admin'),
            'common.time_minutes_ago' => $this->trans('min ago', 'Modules.Feedforge.Admin'),
            'common.time_hours_ago' => $this->trans('hours ago', 'Modules.Feedforge.Admin'),
            'common.time_days_ago' => $this->trans('days ago', 'Modules.Feedforge.Admin'),
            'common.none' => $this->trans('None', 'Modules.Feedforge.Admin'),
            'common.items' => $this->trans('items', 'Modules.Feedforge.Admin'),
            'common.queue' => $this->trans('Queue', 'Modules.Feedforge.Admin'),
            'common.enabled' => $this->trans('Enabled', 'Modules.Feedforge.Admin'),
            'common.disabled' => $this->trans('Disabled', 'Modules.Feedforge.Admin'),
            'common.manual' => $this->trans('Manual', 'Modules.Feedforge.Admin'),
            'common.every_hours' => $this->trans('Every %h%h', 'Modules.Feedforge.Admin'),
            'common.link' => $this->trans('Link', 'Modules.Feedforge.Admin'),
            'common.reference' => $this->trans('Reference', 'Modules.Feedforge.Admin'),
            'common.selected' => $this->trans('selected', 'Modules.Feedforge.Admin'),

            // Config
            'config.account_disconnected' => $this->trans('Account disconnected', 'Modules.Feedforge.Admin'),
            'config.api_data_saved' => $this->trans('API data saved', 'Modules.Feedforge.Admin'),
            'config.data_saved_indicator' => $this->trans('Data saved', 'Modules.Feedforge.Admin'),
            'config.feed_deleted' => $this->trans('Feed deleted', 'Modules.Feedforge.Admin'),
            'config.no_attribute_maps' => $this->trans('No attribute mappings', 'Modules.Feedforge.Admin'),
            'config.no_feeds' => $this->trans('No configured feeds', 'Modules.Feedforge.Admin'),
            'config.ds_missing' => $this->trans('No DataSource', 'Modules.Feedforge.Admin'),
            'config.ds_none' => $this->trans('No data sources yet — save a feed to provision one automatically', 'Modules.Feedforge.Admin'),
            'config.ds_load_error' => $this->trans('Failed to load data sources', 'Modules.Feedforge.Admin'),
            'config.ds_provisioned' => $this->trans('Data sources provisioned', 'Modules.Feedforge.Admin'),
            'config.ds_provision_error' => $this->trans('Failed to provision data sources', 'Modules.Feedforge.Admin'),
            'config.no_merchant_id' => $this->trans('not set', 'Modules.Feedforge.Admin'),
            'config.merchant_loading' => $this->trans('Loading accounts...', 'Modules.Feedforge.Admin'),
            'config.merchant_load_error' => $this->trans('Could not load accounts', 'Modules.Feedforge.Admin'),
            'config.merchant_none_found' => $this->trans('No accounts detected — enter ID manually below', 'Modules.Feedforge.Admin'),
            'config.merchant_select' => $this->trans('— select an account —', 'Modules.Feedforge.Admin'),
            'config.merchant_invalid' => $this->trans('Merchant ID must be numeric', 'Modules.Feedforge.Admin'),
            'config.merchant_saved' => $this->trans('Merchant ID saved', 'Modules.Feedforge.Admin'),
            'config.merchant_save_error' => $this->trans('Could not save Merchant ID', 'Modules.Feedforge.Admin'),
            'config.register_gcp_prompt' => $this->trans('Enter the Google account email you logged in with:', 'Modules.Feedforge.Admin'),
            'config.register_gcp_email_required' => $this->trans('Email is required', 'Modules.Feedforge.Admin'),
            'config.settings_saved' => $this->trans('Settings saved', 'Modules.Feedforge.Admin'),
            'config.load_error' => $this->trans('Failed to load configuration: ', 'Modules.Feedforge.Admin'),
            'config.save_error' => $this->trans('Save error: ', 'Modules.Feedforge.Admin'),
            'config.client_id_required' => $this->trans('Provide Client ID', 'Modules.Feedforge.Admin'),
            'config.disconnect_confirm' => $this->trans('Are you sure you want to disconnect your Google account?', 'Modules.Feedforge.Admin'),
            'config.delete_feed_confirm' => $this->trans('Are you sure you want to delete this feed?', 'Modules.Feedforge.Admin'),
            'config.delete_attribute_map_confirm' => $this->trans('Are you sure you want to delete this mapping?', 'Modules.Feedforge.Admin'),
            'config.attribute_map_deleted' => $this->trans('Mapping deleted', 'Modules.Feedforge.Admin'),
            'config.attribute_map_saved' => $this->trans('Mapping saved', 'Modules.Feedforge.Admin'),
            'config.gmc_field_required' => $this->trans('Select GMC field', 'Modules.Feedforge.Admin'),
            'config.attribute_source_type' => $this->trans('Source type in PrestaShop', 'Modules.Feedforge.Admin'),
            'config.attribute_source_id' => $this->trans('Source ID (Feature ID / Attribute ID)', 'Modules.Feedforge.Admin'),
            'config.attribute_source_name' => $this->trans('Source name (optional label)', 'Modules.Feedforge.Admin'),
            'config.attribute_transform' => $this->trans('Transform (optional)', 'Modules.Feedforge.Admin'),
            'config.transform_options' => $this->trans('Available: lowercase, uppercase, trim, normalize_gender, normalize_age_group', 'Modules.Feedforge.Admin'),
            'config.gmc_field_label' => $this->trans('GMC field', 'Modules.Feedforge.Admin'),
            'config.source_type_feature' => $this->trans('Feature', 'Modules.Feedforge.Admin'),
            'config.source_type_attribute' => $this->trans('Attribute', 'Modules.Feedforge.Admin'),
            'config.source_type_field' => $this->trans('Product field', 'Modules.Feedforge.Admin'),
            'config.new_attribute_map' => $this->trans('New attribute mapping', 'Modules.Feedforge.Admin'),
            'config.edit_attribute_map' => $this->trans('Edit attribute mapping', 'Modules.Feedforge.Admin'),
            'config.add_attribute_map' => $this->trans('Add mapping', 'Modules.Feedforge.Admin'),
            'config.attribute_source_id_placeholder' => $this->trans('e.g. 5', 'Modules.Feedforge.Admin'),
            'config.attribute_source_name_placeholder' => $this->trans('e.g. Color', 'Modules.Feedforge.Admin'),
            'config.attribute_transform_placeholder' => $this->trans('e.g. lowercase', 'Modules.Feedforge.Admin'),
            'config.shipping_carriers' => $this->trans('carriers,', 'Modules.Feedforge.Admin'),
            'config.shipping_countries' => $this->trans('countries', 'Modules.Feedforge.Admin'),
            'config.shipping_services' => $this->trans('services', 'Modules.Feedforge.Admin'),
            'config.shipping_free' => $this->trans('Free', 'Modules.Feedforge.Admin'),
            'config.shipping_no_google_settings' => $this->trans('No settings in Google - click "Send to Google"', 'Modules.Feedforge.Admin'),
            'config.shipping_google_configured' => $this->trans('services configured in Google Merchant Center', 'Modules.Feedforge.Admin'),
            'config.sent_to_google' => $this->trans('Sent to Google', 'Modules.Feedforge.Admin'),
            'config.delete_promotion_confirm' => $this->trans('Are you sure you want to delete this promotion?', 'Modules.Feedforge.Admin'),
            'config.promotion_deleted' => $this->trans('Promotion deleted', 'Modules.Feedforge.Admin'),
            'config.promotion_mapped' => $this->trans('Promotion mapped', 'Modules.Feedforge.Admin'),
            'config.no_cart_rules_found' => $this->trans('No new cart rules found to map', 'Modules.Feedforge.Admin'),
            'config.scan_error' => $this->trans('Scan error: ', 'Modules.Feedforge.Admin'),
            'config.sync_result' => $this->trans('Result: ', 'Modules.Feedforge.Admin'),
            'config.no_code' => $this->trans('No code', 'Modules.Feedforge.Admin'),
            'config.map_btn' => $this->trans('Map', 'Modules.Feedforge.Admin'),

            // Dashboard
            'dashboard.connected' => $this->trans('Connected', 'Modules.Feedforge.Admin'),
            'dashboard.products_count' => $this->trans('%count% products', 'Modules.Feedforge.Admin'),
            'dashboard.load_error' => $this->trans('Failed to load data: ', 'Modules.Feedforge.Admin'),
            'dashboard.sync_started' => $this->trans('Started', 'Modules.Feedforge.Admin'),
            'dashboard.sync_errors' => $this->trans('Errors', 'Modules.Feedforge.Admin'),

            // Error
            'error.json_parse' => $this->trans('JSON parsing error (HTTP %status%)', 'Modules.Feedforge.Admin'),
            'error.server_connection' => $this->trans('No server connection', 'Modules.Feedforge.Admin'),
            'error.server_html_response' => $this->trans('Server returned HTML instead of JSON', 'Modules.Feedforge.Admin'),

            // Feed_edit
            'feed_edit.active_filters' => $this->trans('active filters', 'Modules.Feedforge.Admin'),
            'feed_edit.checking' => $this->trans('Checking...', 'Modules.Feedforge.Admin'),
            'feed_edit.feed_not_found' => $this->trans('Feed not found', 'Modules.Feedforge.Admin'),
            'feed_edit.feed_saved' => $this->trans('Feed saved', 'Modules.Feedforge.Admin'),
            'feed_edit.no_filters' => $this->trans('No filters', 'Modules.Feedforge.Admin'),
            'feed_edit.product_not_found' => $this->trans('Product not found', 'Modules.Feedforge.Admin'),
            'feed_edit.select_feature' => $this->trans('Select feature...', 'Modules.Feedforge.Admin'),
            'feed_edit.select_value' => $this->trans('Select value...', 'Modules.Feedforge.Admin'),
            'feed_edit.load_error' => $this->trans('Failed to load feed: ', 'Modules.Feedforge.Admin'),
            'feed_edit.no_items' => $this->trans('No items', 'Modules.Feedforge.Admin'),
            'feed_edit.no_categories' => $this->trans('No categories', 'Modules.Feedforge.Admin'),
            'feed_edit.no_results' => $this->trans('No results', 'Modules.Feedforge.Admin'),
            'feed_edit.invalid_id' => $this->trans('Enter valid ID', 'Modules.Feedforge.Admin'),
            'feed_edit.product_already_exists' => $this->trans('Product #%id% is already on the list', 'Modules.Feedforge.Admin'),
            'feed_edit.products_of' => $this->trans('of', 'Modules.Feedforge.Admin'),
            'feed_edit.count_error' => $this->trans('Failed to load product count', 'Modules.Feedforge.Admin'),
            'feed_edit.fill_required_fields' => $this->trans('Fill in country, language and currency', 'Modules.Feedforge.Admin'),
            'feed_edit.save_error' => $this->trans('Save error: ', 'Modules.Feedforge.Admin'),
            'feed_edit.product_hash' => $this->trans('Product #', 'Modules.Feedforge.Admin'),
            'feed_edit.categories_load_error' => $this->trans('Failed to load categories: ', 'Modules.Feedforge.Admin'),

            // Products
            'products.no_gmc_data' => $this->trans('No GMC data', 'Modules.Feedforge.Admin'),
            'products.product_synced' => $this->trans('Product synced', 'Modules.Feedforge.Admin'),
            'products.sync_complete' => $this->trans('Synchronization completed', 'Modules.Feedforge.Admin'),
            'products.sync_error' => $this->trans('Sync error: ', 'Modules.Feedforge.Admin'),
            'products.delete_queued' => $this->trans('Products added to deletion queue', 'Modules.Feedforge.Admin'),
            'products.load_error' => $this->trans('Failed to load data: ', 'Modules.Feedforge.Admin'),
            'products.empty_title' => $this->trans('No products', 'Modules.Feedforge.Admin'),
            'products.empty_description' => $this->trans('Run sync to load products', 'Modules.Feedforge.Admin'),
            'products.load_error_title' => $this->trans('Load error', 'Modules.Feedforge.Admin'),
            'products.confirm_remove_selected' => $this->trans('Are you sure you want to remove %count% products from Google Merchant Center?', 'Modules.Feedforge.Admin'),
            'products.not_mapped' => $this->trans('Product not yet mapped', 'Modules.Feedforge.Admin'),
            'products.gmc_field_title' => $this->trans('Title', 'Modules.Feedforge.Admin'),
            'products.gmc_field_description' => $this->trans('Description', 'Modules.Feedforge.Admin'),
            'products.gmc_field_link' => $this->trans('Link', 'Modules.Feedforge.Admin'),
            'products.gmc_field_image' => $this->trans('Main image', 'Modules.Feedforge.Admin'),
            'products.gmc_field_price' => $this->trans('Price', 'Modules.Feedforge.Admin'),
            'products.gmc_field_sale_price' => $this->trans('Sale price', 'Modules.Feedforge.Admin'),
            'products.gmc_field_availability' => $this->trans('Availability', 'Modules.Feedforge.Admin'),
            'products.gmc_field_condition' => $this->trans('Condition', 'Modules.Feedforge.Admin'),
            'products.gmc_field_brand' => $this->trans('Brand', 'Modules.Feedforge.Admin'),
            'products.gmc_field_gtin' => $this->trans('GTIN/EAN', 'Modules.Feedforge.Admin'),
            'products.gmc_field_mpn' => $this->trans('MPN', 'Modules.Feedforge.Admin'),
            'products.gmc_field_category' => $this->trans('Google Category', 'Modules.Feedforge.Admin'),
            'products.gmc_field_type' => $this->trans('Product type', 'Modules.Feedforge.Admin'),
            'products.gmc_field_color' => $this->trans('Color', 'Modules.Feedforge.Admin'),
            'products.gmc_field_size' => $this->trans('Size', 'Modules.Feedforge.Admin'),
            'products.gmc_field_gender' => $this->trans('Gender', 'Modules.Feedforge.Admin'),
            'products.gmc_field_age_group' => $this->trans('Age group', 'Modules.Feedforge.Admin'),
            'products.gmc_field_material' => $this->trans('Material', 'Modules.Feedforge.Admin'),
            'products.gmc_field_shipping_weight' => $this->trans('Shipping weight', 'Modules.Feedforge.Admin'),
            'products.no_status_info' => $this->trans('No status information', 'Modules.Feedforge.Admin'),
            'products.no_errors_title' => $this->trans('No errors', 'Modules.Feedforge.Admin'),
            'products.no_errors_description' => $this->trans('Product has no issues', 'Modules.Feedforge.Admin'),
            'products.no_history' => $this->trans('No sync history', 'Modules.Feedforge.Admin'),
            'products.last_sync' => $this->trans('Last sync:', 'Modules.Feedforge.Admin'),
            'products.last_error' => $this->trans('Last error:', 'Modules.Feedforge.Admin'),
            'products.history_help' => $this->trans('Detailed sync history available in sync logs on Queue page.', 'Modules.Feedforge.Admin'),

            // Queue
            'queue.legend_failed' => $this->trans('Failed: %count%', 'Modules.Feedforge.Admin'),
            'queue.legend_pending' => $this->trans('Pending: %count%', 'Modules.Feedforge.Admin'),
            'queue.legend_processing' => $this->trans('Processing: %count%', 'Modules.Feedforge.Admin'),
            'queue.empty_title' => $this->trans('Queue is empty', 'Modules.Feedforge.Admin'),
            'queue.empty_description' => $this->trans('No items to sync', 'Modules.Feedforge.Admin'),
            'queue.empty_short' => $this->trans('Queue empty', 'Modules.Feedforge.Admin'),
            'queue.retry' => $this->trans('Retry', 'Modules.Feedforge.Admin'),
            'queue.retry_success' => $this->trans('Item restored to queue', 'Modules.Feedforge.Admin'),
            'queue.retry_all_success' => $this->trans('Failed items restored to queue', 'Modules.Feedforge.Admin'),
            'queue.clear_failed_confirm' => $this->trans('Are you sure you want to clear all failed items from queue?', 'Modules.Feedforge.Admin'),
            'queue.clear_success' => $this->trans('Items removed', 'Modules.Feedforge.Admin'),
            'queue.product_fallback' => $this->trans('Product #%id%', 'Modules.Feedforge.Admin'),

            // Reports
            'reports.data_refreshed' => $this->trans('Data refreshed', 'Modules.Feedforge.Admin'),
            'reports.load_error' => $this->trans('Failed to load reports: ', 'Modules.Feedforge.Admin'),
            'reports.refresh_error' => $this->trans('Refresh error: ', 'Modules.Feedforge.Admin'),
            'reports.chart_impressions' => $this->trans('Impressions', 'Modules.Feedforge.Admin'),
            'reports.chart_clicks' => $this->trans('Clicks', 'Modules.Feedforge.Admin'),

            // Rules
            'rules.add_condition' => $this->trans('+ Add condition', 'Modules.Feedforge.Admin'),
            'rules.exclusion_info' => $this->trans('Exclusion rules do not require action configuration. Products meeting the conditions will be automatically excluded.', 'Modules.Feedforge.Admin'),
            'rules.priority' => $this->trans('Priority', 'Modules.Feedforge.Admin'),
            'rules.rule_deleted' => $this->trans('Rule deleted', 'Modules.Feedforge.Admin'),
            'rules.rule_saved' => $this->trans('Rule saved', 'Modules.Feedforge.Admin'),
            'rules.autocomplete_no_results' => $this->trans('No results', 'Modules.Feedforge.Admin'),
            'rules.search_error' => $this->trans('Search error', 'Modules.Feedforge.Admin'),
            'rules.field_price' => $this->trans('Price', 'Modules.Feedforge.Admin'),
            'rules.field_quantity' => $this->trans('Stock', 'Modules.Feedforge.Admin'),
            'rules.field_weight' => $this->trans('Weight', 'Modules.Feedforge.Admin'),
            'rules.field_category_id' => $this->trans('Category ID', 'Modules.Feedforge.Admin'),
            'rules.field_product_id' => $this->trans('Product ID', 'Modules.Feedforge.Admin'),
            'rules.field_brand' => $this->trans('Brand', 'Modules.Feedforge.Admin'),
            'rules.field_condition' => $this->trans('Condition', 'Modules.Feedforge.Admin'),
            'rules.field_visibility' => $this->trans('Visibility', 'Modules.Feedforge.Admin'),
            'rules.field_ean' => $this->trans('EAN', 'Modules.Feedforge.Admin'),
            'rules.field_reference' => $this->trans('Reference/SKU', 'Modules.Feedforge.Admin'),
            'rules.field_country' => $this->trans('Country (feed)', 'Modules.Feedforge.Admin'),
            'rules.field_combinations' => $this->trans('Has variants', 'Modules.Feedforge.Admin'),
            'rules.operator_eq' => $this->trans('equals (=)', 'Modules.Feedforge.Admin'),
            'rules.operator_neq' => $this->trans('not equal (≠)', 'Modules.Feedforge.Admin'),
            'rules.operator_gt' => $this->trans('greater than (>)', 'Modules.Feedforge.Admin'),
            'rules.operator_gte' => $this->trans('greater or equal (≥)', 'Modules.Feedforge.Admin'),
            'rules.operator_lt' => $this->trans('less than (<)', 'Modules.Feedforge.Admin'),
            'rules.operator_lte' => $this->trans('less or equal (≤)', 'Modules.Feedforge.Admin'),
            'rules.operator_contains' => $this->trans('contains', 'Modules.Feedforge.Admin'),
            'rules.operator_not_contains' => $this->trans('not contains', 'Modules.Feedforge.Admin'),
            'rules.operator_starts_with' => $this->trans('starts with', 'Modules.Feedforge.Admin'),
            'rules.operator_ends_with' => $this->trans('ends with', 'Modules.Feedforge.Admin'),
            'rules.operator_in' => $this->trans('is in list (a,b,c)', 'Modules.Feedforge.Admin'),
            'rules.operator_not_in' => $this->trans('is not in list', 'Modules.Feedforge.Admin'),
            'rules.operator_empty' => $this->trans('is empty', 'Modules.Feedforge.Admin'),
            'rules.operator_not_empty' => $this->trans('is not empty', 'Modules.Feedforge.Admin'),
            'rules.operator_regex' => $this->trans('regex (pattern)', 'Modules.Feedforge.Admin'),
            'rules.action_min_price' => $this->trans('Minimum price', 'Modules.Feedforge.Admin'),
            'rules.action_markup_percent' => $this->trans('Markup percentage (%)', 'Modules.Feedforge.Admin'),
            'rules.action_markup_fixed' => $this->trans('Fixed markup (amount)', 'Modules.Feedforge.Admin'),
            'rules.action_round_up' => $this->trans('Round up', 'Modules.Feedforge.Admin'),
            'rules.action_round_99' => $this->trans('Round to X.99', 'Modules.Feedforge.Admin'),
            'rules.value_placeholder' => $this->trans('Value', 'Modules.Feedforge.Admin'),
            'rules.pricing_action_label' => $this->trans('Pricing action', 'Modules.Feedforge.Admin'),
            'rules.pricing_help' => $this->trans('For markup %: enter e.g. 10 = +10%. For min price: enter amount.', 'Modules.Feedforge.Admin'),
            'rules.label_number' => $this->trans('Label number', 'Modules.Feedforge.Admin'),
            'rules.label_value' => $this->trans('Label value', 'Modules.Feedforge.Admin'),
            'rules.label_help' => $this->trans('Max 100 characters. Used for segmentation in Google Ads.', 'Modules.Feedforge.Admin'),
            'rules.label_value_placeholder' => $this->trans('e.g. bestseller, promo, new', 'Modules.Feedforge.Admin'),
            'rules.source_fixed' => $this->trans('Fixed value', 'Modules.Feedforge.Admin'),
            'rules.source_product_field' => $this->trans('Product field', 'Modules.Feedforge.Admin'),
            'rules.condition_new' => $this->trans('New', 'Modules.Feedforge.Admin'),
            'rules.condition_used' => $this->trans('Used', 'Modules.Feedforge.Admin'),
            'rules.condition_refurbished' => $this->trans('Refurbished', 'Modules.Feedforge.Admin'),
            'rules.visibility_both' => $this->trans('Everywhere (catalog + search)', 'Modules.Feedforge.Admin'),
            'rules.visibility_catalog' => $this->trans('Catalog only', 'Modules.Feedforge.Admin'),
            'rules.visibility_search' => $this->trans('Search only', 'Modules.Feedforge.Admin'),
            'rules.visibility_none' => $this->trans('Nowhere (hidden)', 'Modules.Feedforge.Admin'),
            'rules.value_list_placeholder' => $this->trans('e.g. 1,2,3', 'Modules.Feedforge.Admin'),
            'rules.identifier_target_field' => $this->trans('Target field', 'Modules.Feedforge.Admin'),
            'rules.identifier_source' => $this->trans('Source', 'Modules.Feedforge.Admin'),
            'rules.identifier_help' => $this->trans('For "product field": use name e.g. reference, ean13. For "fixed": enter value.', 'Modules.Feedforge.Admin'),
            'rules.id_field_brand' => $this->trans('Brand', 'Modules.Feedforge.Admin'),
            'rules.id_field_identifier_exists' => $this->trans('Identifier exists (true/false)', 'Modules.Feedforge.Admin'),
            'rules.id_exists_help' => $this->trans('Set to "No" if the product has no GTIN/MPN. Google will not reject it for missing identifiers.', 'Modules.Feedforge.Admin'),
            'rules.no_category_maps' => $this->trans('No category mappings', 'Modules.Feedforge.Admin'),
            'rules.add_category_map_help' => $this->trans('Add mapping to assign Google categories', 'Modules.Feedforge.Admin'),
            'rules.load_categories_error' => $this->trans('Failed to load categories: ', 'Modules.Feedforge.Admin'),
            'rules.type_exclusion' => $this->trans('Exclusion', 'Modules.Feedforge.Admin'),
            'rules.type_pricing' => $this->trans('Pricing', 'Modules.Feedforge.Admin'),
            'rules.type_custom_label' => $this->trans('Custom Label', 'Modules.Feedforge.Admin'),
            'rules.type_identifier' => $this->trans('Identifier', 'Modules.Feedforge.Admin'),
            'rules.condition_singular' => $this->trans('condition', 'Modules.Feedforge.Admin'),
            'rules.conditions_plural' => $this->trans('conditions', 'Modules.Feedforge.Admin'),
            'rules.category_id_placeholder' => $this->trans('e.g. 3', 'Modules.Feedforge.Admin'),
            'rules.category_id_multi_placeholder' => $this->trans('e.g. 3, 5, 12', 'Modules.Feedforge.Admin'),
            'rules.category_id_multi_help' => $this->trans('Tip: enter several IDs separated by commas, e.g. 3, 5, 12', 'Modules.Feedforge.Admin'),
            'rules.category_map_saved_multi' => $this->trans('Saved %count% mappings', 'Modules.Feedforge.Admin'),
            'rules.import_taxonomy_confirm' => $this->trans('Download ~5000 Google product categories? Takes a few seconds.', 'Modules.Feedforge.Admin'),
            'rules.category_search_placeholder' => $this->trans('Start typing, e.g. Clothing, Electronics...', 'Modules.Feedforge.Admin'),
            'rules.category_id_required' => $this->trans('Provide category ID', 'Modules.Feedforge.Admin'),
            'rules.category_google_required' => $this->trans('Select Google category', 'Modules.Feedforge.Admin'),
            'rules.category_map_saved' => $this->trans('Mapping saved', 'Modules.Feedforge.Admin'),
            'rules.category_map_deleted' => $this->trans('Mapping deleted', 'Modules.Feedforge.Admin'),
            'rules.delete_category_confirm' => $this->trans('Are you sure you want to delete this mapping?', 'Modules.Feedforge.Admin'),
            'rules.type_exclusion_desc' => $this->trans('exclusion', 'Modules.Feedforge.Admin'),
            'rules.type_pricing_desc' => $this->trans('pricing', 'Modules.Feedforge.Admin'),
            'rules.type_custom_label_desc' => $this->trans('custom label', 'Modules.Feedforge.Admin'),
            'rules.type_identifier_desc' => $this->trans('identifier', 'Modules.Feedforge.Admin'),
            'rules.rule_name_placeholder' => $this->trans('e.g. Exclude products without images', 'Modules.Feedforge.Admin'),
            'rules.priority_help' => $this->trans('Higher = executed first', 'Modules.Feedforge.Admin'),
            'rules.conditions_section' => $this->trans('Conditions (AND — all must be met)', 'Modules.Feedforge.Admin'),
            'rules.action_section' => $this->trans('Action', 'Modules.Feedforge.Admin'),
            'rules.create_rule' => $this->trans('Create rule', 'Modules.Feedforge.Admin'),
            'rules.edit_rule' => $this->trans('Edit rule', 'Modules.Feedforge.Admin'),
            'rules.name_required' => $this->trans('Provide rule name', 'Modules.Feedforge.Admin'),
            'rules.delete_rule_confirm' => $this->trans('Are you sure you want to delete this rule?', 'Modules.Feedforge.Admin'),
            'rules.new_rule' => $this->trans('New rule', 'Modules.Feedforge.Admin'),
            'rules.rule_name_label' => $this->trans('Rule name', 'Modules.Feedforge.Admin'),
            'rules.rule_hash' => $this->trans('Rule #', 'Modules.Feedforge.Admin'),
            'rules.category_hash' => $this->trans('Category #', 'Modules.Feedforge.Admin'),
            'rules.category_ps_id_label' => $this->trans('PrestaShop category ID', 'Modules.Feedforge.Admin'),
            'rules.category_google_label' => $this->trans('Google category', 'Modules.Feedforge.Admin'),
            'rules.add_category_map' => $this->trans('Add mapping', 'Modules.Feedforge.Admin'),
            'rules.edit_category_map' => $this->trans('Edit mapping', 'Modules.Feedforge.Admin'),
            'rules.new_category_map' => $this->trans('New category mapping', 'Modules.Feedforge.Admin'),

            // Status
            'status.approved' => $this->trans('Approved', 'Modules.Feedforge.Admin'),
            'status.completed' => $this->trans('Completed', 'Modules.Feedforge.Admin'),
            'status.disapproved' => $this->trans('Disapproved', 'Modules.Feedforge.Admin'),
            'status.expiring' => $this->trans('Expiring', 'Modules.Feedforge.Admin'),
            'status.failed' => $this->trans('Failed', 'Modules.Feedforge.Admin'),
            'status.pending' => $this->trans('Pending', 'Modules.Feedforge.Admin'),
            'status.processing' => $this->trans('Processing', 'Modules.Feedforge.Admin'),
            'status.running' => $this->trans('Running', 'Modules.Feedforge.Admin'),
            'status.unknown' => $this->trans('Unknown', 'Modules.Feedforge.Admin'),
            'status.refresh_success' => $this->trans('Statuses refreshed', 'Modules.Feedforge.Admin'),

            // Support
            'support.delta_sync' => $this->trans('Delta sync', 'Modules.Feedforge.Admin'),
            'support.gmc_connection' => $this->trans('GMC Connection', 'Modules.Feedforge.Admin'),
            'support.issues' => $this->trans('Issues', 'Modules.Feedforge.Admin'),
            'support.connected' => $this->trans('Connected', 'Modules.Feedforge.Admin'),
            'support.not_connected' => $this->trans('Not connected', 'Modules.Feedforge.Admin'),
            'support.email' => $this->trans('Email', 'Modules.Feedforge.Admin'),
            'support.merchant_id' => $this->trans('Merchant ID', 'Modules.Feedforge.Admin'),
            'support.sync_interval' => $this->trans('Sync interval', 'Modules.Feedforge.Admin'),
            'support.diagnostics_load_error' => $this->trans('Failed to load diagnostics', 'Modules.Feedforge.Admin'),

        ];
    }

    /**
     * Build toolbar buttons: Translate + Manage Hooks.
     */
    private function getToolbarButtons(): array
    {
        $link = $this->getContext()->link;
        $lang = $this->getContext()->language;

        // Direct link to Symfony translation editor with module pre-selected.
        // The overview page reads type/selected/lang/locale from query params.
        $translateUrl = $this->psRouter->generate('admin_international_translation_overview', [
            'type' => 'modules',
            'selected' => 'feedforge',
            'lang' => $lang->iso_code,
            'locale' => $lang->locale,
        ]);

        return [
            'translate' => [
                'href' => $translateUrl,
                'desc' => $this->trans('Translate', 'Admin.Actions'),
            ],
            'hooks' => [
                'href' => $link->getAdminLink('AdminModulesPositions', true, [], [
                    'show_modules' => (int) \Module::getModuleIdByName('feedforge'),
                ]),
                'desc' => $this->trans('Manage hooks', 'Admin.Modules.Feature'),
            ],
        ];
    }

    /**
     * Generate a tokenized URL for a named route.
     * Uses prestashop.router which auto-appends the correct _token on both PS8 and PS9.
     */
    private function generateTokenizedUrl(string $routeName, string $legacyController, array $params = []): string
    {
        return $this->psRouter->generate($routeName, $params);
    }

    /**
     * Build navigation tabs array with URLs and CSRF tokens.
     */
    private function getNavigation(string $activeTab): array
    {
        $tabs = [
            ['id' => 'dashboard',     'label' => $this->trans('Dashboard', 'Modules.Feedforge.Admin'),      'route' => 'feedforge_dashboard',  'legacy' => 'AdminFeedForgeDashboard'],
            ['id' => 'products',      'label' => $this->trans('Products', 'Modules.Feedforge.Admin'),       'route' => 'feedforge_products',   'legacy' => 'AdminFeedForgeProducts'],
            ['id' => 'queue',         'label' => $this->trans('Queue', 'Modules.Feedforge.Admin'),          'route' => 'feedforge_queue',      'legacy' => 'AdminFeedForgeQueue'],
            ['id' => 'reports',       'label' => $this->trans('Reports', 'Modules.Feedforge.Admin'),        'route' => 'feedforge_reports',    'legacy' => 'AdminFeedForgeReports'],
            ['id' => 'rules',         'label' => $this->trans('Rules', 'Modules.Feedforge.Admin'),          'route' => 'feedforge_rules',      'legacy' => 'AdminFeedForgeRules'],
            ['id' => 'configuration', 'label' => $this->trans('Configuration', 'Modules.Feedforge.Admin'),  'route' => 'feedforge_config',     'legacy' => 'AdminFeedForgeConfig'],
            ['id' => 'support',       'label' => $this->trans('Support', 'Modules.Feedforge.Admin'),        'route' => 'feedforge_support',    'legacy' => 'AdminFeedForgeSupport'],
        ];

        foreach ($tabs as &$tab) {
            $tab['url'] = $this->generateTokenizedUrl($tab['route'], $tab['legacy']);
            $tab['active'] = ($tab['id'] === $activeTab);
            unset($tab['legacy'], $tab['route']);
        }

        return $tabs;
    }

    /**
     * Build a map of page URLs with CSRF tokens for cross-page links in templates.
     */
    private function getPageUrls(): array
    {
        $pages = [
            'dashboard' => ['route' => 'feedforge_dashboard',  'legacy' => 'AdminFeedForgeDashboard'],
            'products'  => ['route' => 'feedforge_products',   'legacy' => 'AdminFeedForgeProducts'],
            'queue'     => ['route' => 'feedforge_queue',      'legacy' => 'AdminFeedForgeQueue'],
            'reports'   => ['route' => 'feedforge_reports',    'legacy' => 'AdminFeedForgeReports'],
            'rules'     => ['route' => 'feedforge_rules',      'legacy' => 'AdminFeedForgeRules'],
            'config'    => ['route' => 'feedforge_config',     'legacy' => 'AdminFeedForgeConfig'],
            'support'   => ['route' => 'feedforge_support',    'legacy' => 'AdminFeedForgeSupport'],
        ];

        $urls = [];
        foreach ($pages as $id => $page) {
            $urls[$id] = $this->generateTokenizedUrl($page['route'], $page['legacy']);
        }

        return $urls;
    }
}
