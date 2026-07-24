<?php

declare(strict_types=1);

namespace FeedForge\Controller\Admin;

use FeedForge\Repository\AttributeMapRepository;
use FeedForge\Repository\CategoryMapRepository;
use FeedForge\Repository\FeedConfigRepository;
use FeedForge\Repository\GmcProductRepository;
use FeedForge\Repository\ProductStatusRepository;
use FeedForge\Repository\RuleRepository;
use FeedForge\Repository\SyncLogRepository;
use FeedForge\Repository\SyncQueueRepository;
use FeedForge\Service\DataMapper;
use FeedForge\Service\DataSourceService;
use FeedForge\Service\GoogleApiClient;
use FeedForge\Service\ReportService;
use FeedForge\Service\StatusService;
use FeedForge\Service\ProductFilterCounter;
use FeedForge\Service\PromotionService;
use FeedForge\Service\ShippingService;
use FeedForge\Service\SyncEngine;
use FeedForge\Service\TaxonomyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * REST API controller for AJAX calls from the admin UI.
 * All endpoints return JSON responses.
 */
class ApiController extends BaseController
{

    public function __construct(
        private readonly GoogleApiClient $googleApiClient,
        private readonly GmcProductRepository $gmcProductRepository,
        private readonly ProductStatusRepository $productStatusRepository,
        private readonly SyncQueueRepository $syncQueueRepository,
        private readonly SyncLogRepository $syncLogRepository,
        private readonly SyncEngine $syncEngine,
        private readonly ReportService $reportService,
        private readonly RuleRepository $ruleRepository,
        private readonly CategoryMapRepository $categoryMapRepository,
        private readonly TaxonomyService $taxonomyService,
        private readonly FeedConfigRepository $feedConfigRepository,
        private readonly AttributeMapRepository $attributeMapRepository,
        private readonly DataMapper $dataMapper,
        private readonly StatusService $statusService,
        private readonly ProductFilterCounter $productFilterCounter,
        private readonly ShippingService $shippingService,
        private readonly PromotionService $promotionService,
        private readonly DataSourceService $dataSourceService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    public function dashboardStatsAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        $connected = $this->googleApiClient->isConnected($shopId);
        $accountInfo = $connected ? $this->googleApiClient->getAccountInfo($shopId) : null;

        $statusCounts = $this->gmcProductRepository->countByStatus($shopId);
        $stats = ['total' => 0, 'approved' => 0, 'pending' => 0, 'disapproved' => 0, 'unknown' => 0];
        foreach ($statusCounts as $row) {
            $status = $row['gmc_status'] ?? 'unknown';
            $count = (int) ($row['total'] ?? 0);
            $stats[$status] = ($stats[$status] ?? 0) + $count;
            $stats['total'] += $count;
        }

        $criticalIssues = $this->productStatusRepository->getTopIssues($shopId, 5);
        $lastSync = $this->syncLogRepository->getLatest($shopId);
        $queueStats = $this->syncQueueRepository->getStats($shopId);
        $issueCounts = $this->productStatusRepository->countBySeverity($shopId);

        return $this->json([
            'success' => true,
            'data' => [
                'connection' => [
                    'connected' => $connected,
                    'email' => $accountInfo['email'] ?? '',
                    'merchantId' => $accountInfo['merchantId'] ?? '',
                    'merchantName' => $accountInfo['merchantName'] ?? '',
                ],
                'stats' => $stats,
                'lastSync' => $lastSync,
                'criticalIssues' => $criticalIssues,
                'queueHealth' => [
                    'pending' => $queueStats['pending'] ?? 0,
                    'processing' => $queueStats['processing'] ?? 0,
                    'failed' => $queueStats['failed'] ?? 0,
                ],
                'issueCounts' => $issueCounts,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    public function productsListAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(10, (int) $request->query->get('per_page', 50)));

        $filters = [];
        if ($request->query->get('status')) {
            $filters['gmc_status'] = $request->query->get('status');
        }
        if ($request->query->get('feed')) {
            $filters['id_feed_config'] = $request->query->get('feed');
        }
        if ($request->query->get('search')) {
            $filters['search'] = $request->query->get('search');
        }

        $products = $this->gmcProductRepository->findByShopPaginated($shopId, $page, $perPage, $filters);
        $total = $this->gmcProductRepository->countByShop($shopId, $filters);

        $enriched = $this->enrichProductsWithPsData($products);

        return $this->json([
            'success' => true,
            'data' => [
                'products' => $enriched,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function productDetailAction(int $productId): JsonResponse
    {
        $shopId = $this->getShopId();

        $products = $this->gmcProductRepository->findByShopPaginated($shopId, 1, 100, [
            'search' => (string) $productId,
        ]);

        if (empty($products)) {
            return $this->json([
                'success' => true,
                'data' => ['product' => null, 'gmcData' => null, 'issues' => []],
            ]);
        }

        $record = $products[0];
        $feedforgeProductId = (int) $record['id_feedforge_product'];

        $issues = $this->productStatusRepository->findByFeedforgeProduct($feedforgeProductId);

        $feedConfigId = (int) ($record['id_feed_config'] ?? 0);
        $attributeId = (int) ($record['id_product_attribute'] ?? 0);
        $gmcData = null;
        try {
            $gmcData = $this->dataMapper->mapProduct($productId, $attributeId, $shopId, $feedConfigId);
        } catch (\Exception $e) {
            // Not critical
        }

        $enriched = $this->enrichProductsWithPsData([$record]);

        return $this->json([
            'success' => true,
            'data' => [
                'product' => $enriched[0] ?? $record,
                'gmcData' => $gmcData,
                'issues' => $issues,
            ],
        ]);
    }

    public function productsSyncAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $productIds = $body['productIds'] ?? [];
        $syncType = $body['type'] ?? 'manual';

        try {
            if ($syncType === 'full') {
                $result = $this->syncEngine->fullSync($shopId);
            } elseif ($syncType === 'delta') {
                // Auto-upgrade to full sync if queue is empty and no sync has been done yet
                $queueStats = $this->syncQueueRepository->getStats($shopId);
                $pendingCount = (int) ($queueStats['pending'] ?? 0);
                if ($pendingCount === 0) {
                    $result = $this->syncEngine->fullSync($shopId);
                } else {
                    $result = $this->syncEngine->deltaSync($shopId);
                }
            } elseif (!empty($productIds)) {
                $result = $this->syncEngine->manualSync($shopId, array_map('intval', $productIds));
            } else {
                return $this->json(['success' => false, 'message' => $this->trans('No products to synchronize', 'Modules.Feedforge.Admin')], 400);
            }

            return $this->json([
                'success' => true,
                'message' => $this->trans('Synchronization completed', 'Modules.Feedforge.Admin'),
                'data' => $result['stats'] ?? [],
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function productsRemoveAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $productIds = array_map('intval', $body['productIds'] ?? []);

        foreach ($productIds as $productId) {
            $this->syncQueueRepository->enqueue($productId, 0, $shopId, 'delete', 1);
        }

        $count = count($productIds);

        return $this->json([
            'success' => true,
            'message' => $this->trans('%count% products added to deletion queue', 'Modules.Feedforge.Admin', ['%count%' => $count]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Queue
    // -------------------------------------------------------------------------

    public function queueListAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(10, (int) $request->query->get('per_page', 50)));

        $filters = [];
        if ($request->query->get('status')) {
            $filters['status'] = $request->query->get('status');
        }
        if ($request->query->get('action')) {
            $filters['action'] = $request->query->get('action');
        }

        $items = $this->syncQueueRepository->listPaginated($shopId, $page, $perPage, $filters);
        $stats = $this->syncQueueRepository->getStats($shopId);
        $totalItems = $this->syncQueueRepository->countFiltered($shopId, $filters);

        return $this->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'stats' => $stats,
                'page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ]);
    }

    public function queueRetryAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];

        if (!empty($body['id'])) {
            $success = $this->syncQueueRepository->retrySingle((int) $body['id'], $shopId);

            return $this->json([
                'success' => $success,
                'message' => $success ? $this->trans('Item restored to queue', 'Modules.Feedforge.Admin') : $this->trans('Item not found', 'Modules.Feedforge.Admin'),
            ]);
        }

        $retried = $this->syncQueueRepository->retryFailed($shopId);

        return $this->json([
            'success' => true,
            'message' => $this->trans('%count% items restored to queue', 'Modules.Feedforge.Admin', ['%count%' => $retried]),
        ]);
    }

    public function queueClearAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $type = $body['type'] ?? 'failed';

        $cleared = match ($type) {
            'completed' => $this->syncQueueRepository->clearCompleted($shopId),
            'failed' => $this->syncQueueRepository->clearFailed($shopId),
            default => 0,
        };

        return $this->json([
            'success' => true,
            'message' => $this->trans('%count% items removed', 'Modules.Feedforge.Admin', ['%count%' => $cleared]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Reports
    // -------------------------------------------------------------------------

    public function reportsAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $period = $request->query->get('period', '30d');
        $country = $request->query->get('country', '') ?: null;

        $days = match ($period) {
            '7d' => 7, '14d' => 14, '90d' => 90, default => 30,
        };

        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        // Previous period for trend comparison
        $prevEndDate = date('Y-m-d', strtotime("-{$days} days - 1 day"));
        $prevStartDate = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));

        try {
            $summary = $this->reportService->getSummary($shopId, $startDate, $endDate, $country);
            $prevSummary = $this->reportService->getSummary($shopId, $prevStartDate, $prevEndDate, $country);
            $timeSeries = $this->reportService->getTimeSeries($shopId, $startDate, $endDate);
            $byProduct = $this->reportService->getTopProducts($shopId, $startDate, $endDate, 10);
            $byCountry = $this->reportService->getByCountry($shopId, $startDate, $endDate);
        } catch (\Exception $e) {
            $summary = ['impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'conversions' => 0];
            $prevSummary = ['impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'conversions' => 0];
            $timeSeries = [];
            $byProduct = [];
            $byCountry = [];
        }

        // Calculate trends (percentage change)
        $trends = [
            'impressions' => $this->calculateTrend($prevSummary['impressions'] ?? 0, $summary['impressions'] ?? 0),
            'clicks' => $this->calculateTrend($prevSummary['clicks'] ?? 0, $summary['clicks'] ?? 0),
            'ctr' => $this->calculateTrend($prevSummary['ctr'] ?? 0, $summary['ctr'] ?? 0),
        ];

        return $this->json([
            'success' => true,
            'data' => compact('summary', 'trends', 'timeSeries', 'byProduct', 'byCountry'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Rules
    // -------------------------------------------------------------------------

    public function rulesListAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $type = $request->query->get('type');

        $rules = $this->ruleRepository->findByShop($shopId, $type);

        $rules = array_map(function ($rule) {
            $rule['conditions'] = json_decode($rule['conditions'] ?? '[]', true) ?: [];
            $rule['actions'] = json_decode($rule['actions'] ?? '{}', true) ?: [];
            return $rule;
        }, $rules);

        return $this->json(['success' => true, 'data' => ['rules' => $rules]]);
    }

    public function rulesSaveAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];

        $data = [
            'id_shop' => $shopId,
            'type' => $body['type'] ?? 'exclusion',
            'name' => $body['name'] ?? '',
            'conditions' => json_encode($body['conditions'] ?? []),
            'actions' => json_encode($body['actions'] ?? []),
            'priority' => (int) ($body['priority'] ?? 0),
            'active' => (int) ($body['active'] ?? 1),
        ];

        if (!empty($body['id'])) {
            $data['id_feedforge_rule'] = (int) $body['id'];
        }

        $id = $this->ruleRepository->save($data);

        return $this->json(['success' => true, 'message' => $this->trans('Rule saved', 'Modules.Feedforge.Admin'), 'data' => ['id' => $id]]);
    }

    public function rulesDeleteAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $id = (int) ($body['id'] ?? 0);
        if ($id > 0) {
            $this->ruleRepository->delete($id, $shopId);
        }

        return $this->json(['success' => true, 'message' => $this->trans('Rule deleted', 'Modules.Feedforge.Admin')]);
    }

    // -------------------------------------------------------------------------
    // Categories / Taxonomy
    // -------------------------------------------------------------------------

    public function categoriesListAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $categories = $this->categoryMapRepository->findByShop($shopId);

        return $this->json([
            'success' => true,
            'data' => ['categories' => $this->enrichCategoriesWithPsData($categories)],
        ]);
    }

    public function categoriesSaveAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];

        $data = [
            'id_category' => (int) ($body['id_category'] ?? 0),
            'id_shop' => $shopId,
            'google_taxonomy_id' => (int) ($body['google_taxonomy_id'] ?? 0) ?: null,
            'google_taxonomy_path' => $body['google_taxonomy_path'] ?? '',
            'active' => (int) ($body['active'] ?? 1),
        ];

        if (!empty($body['id'])) {
            $data['id_feedforge_category_map'] = (int) $body['id'];
        }

        $this->categoryMapRepository->save($data);

        return $this->json(['success' => true, 'message' => $this->trans('Category mapping saved', 'Modules.Feedforge.Admin')]);
    }

    public function taxonomySearchAction(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $lang = $request->query->get('lang', 'en');

        $rows = $this->taxonomyService->search($query, $lang, 20);
        $results = array_map(static fn (array $r): array => [
            'id' => (int) ($r['taxonomy_id'] ?? 0),
            'path' => (string) ($r['value'] ?? ''),
        ], $rows);

        return $this->json([
            'success' => true,
            'data' => ['results' => $results],
        ]);
    }

    /**
     * Download and import Google's product taxonomy (~5000 categories).
     * Filled into ps_feedforge_taxonomy, used by the category-mapping autocomplete.
     * Defaults to Polish; pass ?lang=en / ?lang=de etc. to import another locale.
     */
    public function taxonomyImportAction(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $lang = (string) ($body['lang'] ?? $request->query->get('lang', 'pl'));

        try {
            $count = $this->taxonomyService->importFromGoogle($lang);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Failed to import taxonomy: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }

        return $this->json([
            'success' => true,
            'message' => $this->trans('Imported %count% Google categories', 'Modules.Feedforge.Admin', ['%count%' => $count]),
            'data' => ['count' => $count, 'lang' => $lang],
        ]);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function configGetAction(): JsonResponse
    {
        $shopId = $this->getShopId();
        $connected = $this->googleApiClient->isConnected($shopId);
        $accountInfo = $connected ? $this->googleApiClient->getAccountInfo($shopId) : null;

        return $this->json([
            'success' => true,
            'data' => [
                'connection' => [
                    'connected' => $connected,
                    'email' => $accountInfo['email'] ?? '',
                    'merchantId' => $accountInfo['merchantId'] ?? '',
                    'merchantName' => $accountInfo['merchantName'] ?? '',
                    'gcpRegistered' => (bool) ($accountInfo['gcpRegistered'] ?? false),
                ],
                'config' => [
                    'google_client_id' => \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_ID') ?: '',
                    'google_client_secret' => !empty(\Configuration::get('FEEDFORGE_GOOGLE_CLIENT_SECRET')) ? '********' : '',
                    'sync_interval' => \Configuration::get('FEEDFORGE_SYNC_INTERVAL') ?: '4',
                    'batch_size' => \Configuration::get('FEEDFORGE_BATCH_SIZE') ?: '100',
                    'max_retries' => \Configuration::get('FEEDFORGE_MAX_RETRIES') ?: '5',
                    'max_execution_time' => \Configuration::get('FEEDFORGE_MAX_EXECUTION_TIME') ?: '300',
                    'delta_sync_enabled' => (bool) \Configuration::get('FEEDFORGE_DELTA_SYNC_ENABLED'),
                    'auto_remove_deleted' => (bool) \Configuration::get('FEEDFORGE_AUTO_REMOVE_DELETED'),
                    'debug_logging' => (bool) \Configuration::get('FEEDFORGE_DEBUG_LOGGING'),
                    'cron_token' => \Configuration::get('FEEDFORGE_CRON_TOKEN') ?: '',
                ],
            ],
        ]);
    }

    public function configSaveAction(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];

        $allowedKeys = [
            'google_client_id' => 'FEEDFORGE_GOOGLE_CLIENT_ID',
            'google_client_secret' => 'FEEDFORGE_GOOGLE_CLIENT_SECRET',
            'sync_interval' => 'FEEDFORGE_SYNC_INTERVAL',
            'batch_size' => 'FEEDFORGE_BATCH_SIZE',
            'max_retries' => 'FEEDFORGE_MAX_RETRIES',
            'max_execution_time' => 'FEEDFORGE_MAX_EXECUTION_TIME',
            'delta_sync_enabled' => 'FEEDFORGE_DELTA_SYNC_ENABLED',
            'auto_remove_deleted' => 'FEEDFORGE_AUTO_REMOVE_DELETED',
            'debug_logging' => 'FEEDFORGE_DEBUG_LOGGING',
        ];

        foreach ($body as $key => $value) {
            if (!isset($allowedKeys[$key])) {
                continue;
            }
            if ($key === 'google_client_secret' && str_contains((string) $value, '***')) {
                continue;
            }
            \Configuration::updateValue($allowedKeys[$key], (string) $value);
        }

        return $this->json(['success' => true, 'message' => $this->trans('Settings saved', 'Modules.Feedforge.Admin')]);
    }

    public function feedsListAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        return $this->json([
            'success' => true,
            'data' => ['feeds' => $this->feedConfigRepository->findByShop($shopId)],
        ]);
    }

    public function feedsSaveAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];

        $data = [
            'id_shop' => $shopId,
            'country_code' => strtoupper($body['country_code'] ?? ''),
            'language_code' => strtolower($body['language_code'] ?? ''),
            'currency_code' => strtoupper($body['currency_code'] ?? ''),
            'active' => (int) ($body['active'] ?? 1),
            'title_template' => $body['title_template'] ?? '{name}',
            'description_source' => $body['description_source'] ?? 'description_short',
            'include_tax' => (int) ($body['include_tax'] ?? 1),
            'offer_id_prefix' => trim((string) ($body['offer_id_prefix'] ?? '')),
            'filters' => !empty($body['filters']) ? json_encode($body['filters']) : null,
        ];

        if (!empty($body['id'])) {
            $data['id_feedforge_feed_config'] = (int) $body['id'];
        }

        $id = $this->feedConfigRepository->save($data);

        return $this->json(['success' => true, 'message' => $this->trans('Feed saved', 'Modules.Feedforge.Admin'), 'data' => ['id' => $id]]);
    }

    public function attributeMapsListAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        return $this->json([
            'success' => true,
            'data' => ['attributeMaps' => $this->attributeMapRepository->findByShop($shopId)],
        ]);
    }

    public function attributeMapsSaveAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];

        $data = [
            'id_shop' => $shopId,
            'gmc_field' => $body['gmc_field'] ?? '',
            'ps_source_type' => $body['ps_source_type'] ?? 'feature',
            'ps_source_id' => (int) ($body['ps_source_id'] ?? 0),
            'ps_source_name' => $body['ps_source_name'] ?? '',
            'transform' => $body['transform'] ?? null,
            'active' => (int) ($body['active'] ?? 1),
        ];

        if (!empty($body['id'])) {
            $data['id_feedforge_attribute_map'] = (int) $body['id'];
        }

        $this->attributeMapRepository->save($data);

        return $this->json(['success' => true, 'message' => $this->trans('Mapping saved', 'Modules.Feedforge.Admin')]);
    }

    // -------------------------------------------------------------------------
    // Status sync
    // -------------------------------------------------------------------------

    public function statusSyncAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json(['success' => false, 'message' => $this->trans('Google Merchant Center is not connected', 'Modules.Feedforge.Admin')], 400);
        }

        try {
            $result = $this->statusService->syncStatuses($shopId);

            return $this->json([
                'success' => true,
                'message' => $this->trans(
                    'Statuses synchronized: %updated% products updated, %issues% issues found',
                    ['%updated%' => $result['updated'], '%issues%' => $result['issues']],
                    'Modules.Feedforge.Admin'
                ),
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function accountStatusAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json(['success' => false, 'message' => $this->trans('Google Merchant Center is not connected', 'Modules.Feedforge.Admin')], 400);
        }

        try {
            $data = $this->statusService->getAccountStatus($shopId);

            return $this->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Shipping
    // -------------------------------------------------------------------------

    public function shippingPreviewAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        try {
            $data = $this->shippingService->buildShippingPreview($shopId);

            return $this->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function shippingCurrentAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json(['success' => false, 'message' => $this->trans('Google Merchant Center is not connected', 'Modules.Feedforge.Admin')], 400);
        }

        try {
            $data = $this->shippingService->getFromGoogle($shopId);

            return $this->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function shippingPushAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json(['success' => false, 'message' => $this->trans('Google Merchant Center is not connected', 'Modules.Feedforge.Admin')], 400);
        }

        try {
            $result = $this->shippingService->pushToGoogle($shopId);

            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Promotions
    // -------------------------------------------------------------------------

    public function promotionsListAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        try {
            $promotions = $this->promotionService->getPromotions($shopId);

            return $this->json(['success' => true, 'data' => ['promotions' => $promotions]]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function promotionsScanAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        try {
            $cartRules = $this->promotionService->scanCartRules($shopId);

            return $this->json(['success' => true, 'data' => ['cartRules' => $cartRules]]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function promotionsSaveAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];

        try {
            $id = $this->promotionService->saveMapping($shopId, $body);

            return $this->json(['success' => true, 'data' => ['id' => $id], 'message' => $this->trans('Mapping saved', 'Modules.Feedforge.Admin')]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function promotionsSyncAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json(['success' => false, 'message' => $this->trans('Google Merchant Center is not connected', 'Modules.Feedforge.Admin')], 400);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $promotionId = (int) ($body['id'] ?? 0);

        try {
            if ($promotionId > 0) {
                $result = $this->promotionService->syncPromotion($shopId, $promotionId);
            } else {
                $result = $this->promotionService->syncAllPromotions($shopId);
            }

            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function promotionsDeleteAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $promotionId = (int) ($body['id'] ?? 0);

        if ($promotionId <= 0) {
            return $this->json(['success' => false, 'message' => $this->trans('Missing promotion ID', 'Modules.Feedforge.Admin')], 400);
        }

        try {
            $result = $this->promotionService->deletePromotion($shopId, $promotionId);

            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // DataSources (Merchant API v2.0.0+)
    // -------------------------------------------------------------------------

    /**
     * List all Merchant API DataSources currently provisioned for this account,
     * combined with our local feed configs to show which feeds are linked.
     */
    public function dataSourcesListAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Not connected to Google Merchant Center', 'Modules.Feedforge.Admin'),
            ], 400);
        }

        try {
            $remote = $this->dataSourceService->listAll($shopId);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Failed to list data sources: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }

        $feeds = $this->feedConfigRepository->findByShop($shopId);

        // Mark which remote DataSources are already bound to a local feed.
        $boundNames = array_filter(array_map(
            static fn (array $feed): ?string => $feed['data_source_name'] ?? null,
            $feeds
        ));
        $boundSet = array_flip($boundNames);

        $dataSources = array_map(
            static fn (array $ds): array => $ds + ['isBound' => isset($boundSet[$ds['name']])],
            $remote
        );

        return $this->json([
            'success' => true,
            'data' => [
                'dataSources' => $dataSources,
                'feeds' => $feeds,
            ],
        ]);
    }

    /**
     * Create a Merchant API DataSource for every feed config that doesn't have one yet.
     */
    public function dataSourcesProvisionAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Not connected to Google Merchant Center', 'Modules.Feedforge.Admin'),
            ], 400);
        }

        try {
            $result = $this->dataSourceService->provisionMissing($shopId);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Provisioning failed: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }

        return $this->json([
            'success' => true,
            'message' => $this->trans('Provisioned %count% data source(s)', 'Modules.Feedforge.Admin', ['%count%' => $result['provisioned']]),
            'data' => $result,
        ]);
    }

    public function reportsRefreshAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json(['success' => false, 'message' => $this->trans('Google Merchant Center is not connected', 'Modules.Feedforge.Admin')], 400);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $days = min(90, max(7, (int) ($body['days'] ?? 30)));
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        try {
            $count = $this->reportService->fetchFromApi($shopId, $startDate, $endDate);

            return $this->json([
                'success' => true,
                'message' => $this->trans('Reports data refreshed: %count% records', 'Modules.Feedforge.Admin', ['%count%' => $count]),
                'data' => ['count' => $count],
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Google Account selection (Merchant Center)
    // -------------------------------------------------------------------------

    /**
     * List all Merchant Center accounts the connected user has access to,
     * so the UI can render a dropdown for the user to pick the right one.
     */
    public function googleAccountsListAction(): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Not connected to Google Merchant Center', 'Modules.Feedforge.Admin'),
            ], 400);
        }

        try {
            $accounts = $this->googleApiClient->listGoogleAccounts($shopId);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Failed to list Google accounts: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }

        return $this->json([
            'success' => true,
            'data' => ['accounts' => $accounts],
        ]);
    }

    /**
     * Register the connected GCP project as a Merchant API developer.
     * One-time step after setting the Merchant ID — unlocks listDataSources etc.
     * Accepts optional `developerEmail` override in the request body.
     */
    public function googleRegisterDeveloperAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();

        if (!$this->googleApiClient->isConnected($shopId)) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Not connected to Google Merchant Center', 'Modules.Feedforge.Admin'),
            ], 400);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $email = isset($body['developerEmail']) ? trim((string) $body['developerEmail']) : null;
        if ($email === '') {
            $email = null;
        }

        try {
            $result = $this->googleApiClient->registerGcpAsDeveloper($shopId, $email);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Failed to register GCP project: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }

        return $this->json([
            'success' => true,
            'message' => $this->trans('GCP project registered as developer for %email%. Please wait ~5 minutes for propagation, then refresh the page.', 'Modules.Feedforge.Admin', ['%email%' => $result['email']]),
            'data' => $result,
        ]);
    }

    /**
     * Save the Merchant ID the user chose (from dropdown or typed in manually).
     */
    public function googleAccountSelectAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $merchantId = trim((string) ($body['merchantId'] ?? ''));

        if ($merchantId === '' || !ctype_digit($merchantId)) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Merchant ID must be a non-empty numeric value', 'Modules.Feedforge.Admin'),
            ], 400);
        }

        try {
            $this->googleApiClient->setMerchantId($shopId, $merchantId);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Failed to save Merchant ID: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }

        return $this->json([
            'success' => true,
            'message' => $this->trans('Merchant ID saved', 'Modules.Feedforge.Admin'),
            'data' => ['accountInfo' => $this->googleApiClient->getAccountInfo($shopId)],
        ]);
    }

    // -------------------------------------------------------------------------
    // PrestaShop data (for feed filters UI)
    // -------------------------------------------------------------------------

    public function psCategoriesAction(Request $request): JsonResponse
    {
        $langId = (int) $this->getContext()->language->id;
        $shopId = $this->getShopId();
        $parentId = (int) $request->query->get('parent_id', 0);
        $prefix = _DB_PREFIX_;

        $rootCatId = (int) \Configuration::get('PS_ROOT_CATEGORY');
        $queryParent = $parentId > 0 ? $parentId : $rootCatId;

        $rows = \Db::getInstance()->executeS(
            "SELECT c.id_category, cl.name, c.id_parent, c.level_depth,
                    (SELECT COUNT(*) FROM {$prefix}category cc WHERE cc.id_parent = c.id_category AND cc.active = 1) AS children_count,
                    (SELECT COUNT(*) FROM {$prefix}category_product cp WHERE cp.id_category = c.id_category) AS product_count
             FROM {$prefix}category c
             JOIN {$prefix}category_lang cl ON c.id_category = cl.id_category AND cl.id_lang = " . (int) $langId . "
             JOIN {$prefix}category_shop cs ON c.id_category = cs.id_category AND cs.id_shop = " . (int) $shopId . "
             WHERE c.id_parent = " . (int) $queryParent . " AND c.active = 1
             ORDER BY c.position ASC"
        );

        return $this->json([
            'success' => true,
            'data' => ['categories' => $rows ?: []],
        ]);
    }

    public function psManufacturersAction(): JsonResponse
    {
        $prefix = _DB_PREFIX_;

        $rows = \Db::getInstance()->executeS(
            "SELECT m.id_manufacturer, m.name
             FROM {$prefix}manufacturer m
             WHERE m.active = 1
             ORDER BY m.name ASC"
        );

        return $this->json([
            'success' => true,
            'data' => ['manufacturers' => $rows ?: []],
        ]);
    }

    public function psFeaturesAction(Request $request): JsonResponse
    {
        $langId = (int) $this->getContext()->language->id;
        $prefix = _DB_PREFIX_;

        $features = \Db::getInstance()->executeS(
            "SELECT f.id_feature, fl.name
             FROM {$prefix}feature f
             JOIN {$prefix}feature_lang fl ON f.id_feature = fl.id_feature AND fl.id_lang = " . (int) $langId . "
             ORDER BY fl.name ASC"
        );

        $values = [];
        $featureId = (int) $request->query->get('id_feature', 0);
        if ($featureId > 0) {
            $values = \Db::getInstance()->executeS(
                "SELECT fv.id_feature_value, fvl.value
                 FROM {$prefix}feature_value fv
                 JOIN {$prefix}feature_value_lang fvl ON fv.id_feature_value = fvl.id_feature_value AND fvl.id_lang = " . (int) $langId . "
                 WHERE fv.id_feature = " . (int) $featureId . "
                 ORDER BY fvl.value ASC"
            );
        }

        return $this->json([
            'success' => true,
            'data' => [
                'features' => $features ?: [],
                'values' => $values ?: [],
            ],
        ]);
    }

    public function psSuppliersAction(): JsonResponse
    {
        $prefix = _DB_PREFIX_;

        $rows = \Db::getInstance()->executeS(
            "SELECT s.id_supplier, s.name
             FROM {$prefix}supplier s
             WHERE s.active = 1
             ORDER BY s.name ASC"
        );

        return $this->json([
            'success' => true,
            'data' => ['suppliers' => $rows ?: []],
        ]);
    }

    public function psTagsAction(): JsonResponse
    {
        $langId = (int) $this->getContext()->language->id;
        $prefix = _DB_PREFIX_;

        $rows = \Db::getInstance()->executeS(
            "SELECT t.id_tag, t.name
             FROM {$prefix}tag t
             WHERE t.id_lang = " . (int) $langId . "
             ORDER BY t.name ASC"
        );

        return $this->json([
            'success' => true,
            'data' => ['tags' => $rows ?: []],
        ]);
    }

    public function psProductLookupAction(Request $request): JsonResponse
    {
        $productId = (int) $request->query->get('id', 0);
        $langId = (int) $this->getContext()->language->id;
        $shopId = $this->getShopId();
        $prefix = _DB_PREFIX_;

        if ($productId <= 0) {
            return $this->json(['success' => false, 'message' => $this->trans('Invalid product ID', 'Modules.Feedforge.Admin')]);
        }

        $row = \Db::getInstance()->getRow(
            "SELECT p.id_product, pl.name
             FROM {$prefix}product_shop ps
             JOIN {$prefix}product p ON p.id_product = ps.id_product
             JOIN {$prefix}product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = " . (int) $langId . " AND pl.id_shop = " . (int) $shopId . "
             WHERE ps.id_shop = " . (int) $shopId . " AND p.id_product = " . (int) $productId
        );

        if (!$row) {
            return $this->json(['success' => false, 'message' => $this->trans('Product #%id% does not exist', 'Modules.Feedforge.Admin', ['%id%' => $productId])]);
        }

        return $this->json(['success' => true, 'data' => $row]);
    }

    public function feedsCountAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $filters = $body['filters'] ?? [];

        try {
            $matched = $this->productFilterCounter->countFiltered($shopId, $filters);
            $total = $this->productFilterCounter->countTotal($shopId);

            return $this->json([
                'success' => true,
                'data' => [
                    'matched' => $matched,
                    'total' => $total,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function feedsDeleteAction(Request $request): JsonResponse
    {
        $shopId = $this->getShopId();
        $body = json_decode($request->getContent(), true) ?? [];
        $id = (int) ($body['id'] ?? 0);

        if ($id > 0) {
            $this->feedConfigRepository->delete($id, $shopId);
        }

        return $this->json(['success' => true, 'message' => $this->trans('Feed deleted', 'Modules.Feedforge.Admin')]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getShopId(): int
    {
        return (int) ($this->getContext()->shop->id ?? 1);
    }

    private function calculateTrend(float $previous, float $current): array
    {
        if ($previous == 0) {
            return ['value' => $current > 0 ? 100.0 : 0.0, 'direction' => $current > 0 ? 'up' : 'flat'];
        }

        $change = round(($current - $previous) / $previous * 100, 1);

        return [
            'value' => abs($change),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }

    private function enrichProductsWithPsData(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $langId = (int) $this->getContext()->language->id;
        $prefix = _DB_PREFIX_;
        $productIds = array_unique(array_column($products, 'id_product'));
        if (empty($productIds)) {
            return $products;
        }

        $placeholders = implode(',', array_map('intval', $productIds));
        $rows = \Db::getInstance()->executeS(
            "SELECT p.id_product, pl.name, p.reference, p.ean13
             FROM {$prefix}product p
             JOIN {$prefix}product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = {$langId}
             WHERE p.id_product IN ({$placeholders})"
        );

        $psData = [];
        foreach ($rows ?: [] as $row) {
            $psData[(int) $row['id_product']] = $row;
        }

        foreach ($products as &$product) {
            $pid = (int) ($product['id_product'] ?? 0);
            $ps = $psData[$pid] ?? null;
            $product['ps_name'] = $ps['name'] ?? $this->trans('Product #%id%', 'Modules.Feedforge.Admin', ['%id%' => $pid]);
            $product['ps_reference'] = $ps['reference'] ?? '';
            $product['ps_ean13'] = $ps['ean13'] ?? '';
        }

        return $products;
    }

    private function enrichCategoriesWithPsData(array $categories): array
    {
        if (empty($categories)) {
            return [];
        }

        $langId = (int) $this->getContext()->language->id;
        $prefix = _DB_PREFIX_;
        $catIds = array_unique(array_column($categories, 'id_category'));
        if (empty($catIds)) {
            return $categories;
        }

        $placeholders = implode(',', array_map('intval', $catIds));
        $rows = \Db::getInstance()->executeS(
            "SELECT c.id_category, cl.name
             FROM {$prefix}category c
             JOIN {$prefix}category_lang cl ON c.id_category = cl.id_category AND cl.id_lang = {$langId}
             WHERE c.id_category IN ({$placeholders})"
        );

        $psData = [];
        foreach ($rows ?: [] as $row) {
            $psData[(int) $row['id_category']] = $row['name'];
        }

        foreach ($categories as &$cat) {
            $cid = (int) ($cat['id_category'] ?? 0);
            $cat['ps_category_name'] = $psData[$cid] ?? $this->trans('Category #%id%', 'Modules.Feedforge.Admin', ['%id%' => $cid]);
        }

        return $categories;
    }
}
