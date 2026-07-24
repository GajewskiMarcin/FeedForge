<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\FeedConfigRepository;
use FeedForge\Repository\GmcProductRepository;
use FeedForge\Repository\ProductStatusRepository;

/**
 * Reads product statuses from Google Merchant API and persists them locally.
 *
 * v2.0.1: Rewritten to use MerchantApiHttpClient (raw REST/JSON) instead of the official
 * PHP SDK. Statuses are embedded in the read-only Product resource (returned as JSON);
 * account-level data is split between two endpoints (issues + homepage).
 */
class StatusService
{
    public function __construct(
        private readonly MerchantApiHttpClient $http,
        private readonly GoogleApiClient $apiClient,
        private readonly ProductService $productService,
        private readonly ProductStatusRepository $productStatusRepository,
        private readonly GmcProductRepository $gmcProductRepository,
        private readonly FeedConfigRepository $feedConfigRepository,
    ) {
    }

    /**
     * Walk every processed product in this Merchant Center account, parse its embedded status,
     * and update local records.
     *
     * @return array{updated: int, errors: int, issues: int}
     */
    public function syncStatuses(int $shopId): array
    {
        $updated = 0;
        $errors = 0;
        $totalIssues = 0;

        try {
            foreach ($this->productService->iterateAllProducts($shopId) as $product) {
                try {
                    $issueCount = $this->processProduct($shopId, $product);
                    $totalIssues += $issueCount;
                    $updated++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }
        } catch (MerchantApiException $e) {
            $errors++;
        }

        return [
            'updated' => $updated,
            'errors' => $errors,
            'issues' => $totalIssues,
        ];
    }

    /**
     * Fetch status for a single product by its full Product resource name.
     *
     * @return array{status: string, issues: array<int, array<string, mixed>>}
     */
    public function getProductStatus(int $shopId, string $productName): array
    {
        $product = $this->productService->getProduct($shopId, $productName);

        if ($product === null) {
            return ['status' => 'not_found', 'issues' => []];
        }

        $status = $product['productStatus'] ?? null;

        return [
            'status' => $status !== null ? $this->determineOverallStatus($status) : 'pending',
            'issues' => $status !== null ? $this->extractIssues($status) : [],
        ];
    }

    /**
     * Get account-level information: claimed status + open account issues.
     *
     * @return array{websiteClaimed: bool, homepageUri: string, issues: array<int, array<string, mixed>>}
     */
    public function getAccountStatus(int $shopId): array
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);

        $result = [
            'websiteClaimed' => false,
            'homepageUri' => '',
            'issues' => [],
        ];

        try {
            $homepage = $this->http->get(
                $shopId,
                sprintf('/accounts/v1/accounts/%s/homepage', $merchantId)
            );
            $result['websiteClaimed'] = (bool) ($homepage['claimed'] ?? false);
            $result['homepageUri'] = (string) ($homepage['uri'] ?? '');
        } catch (MerchantApiException $e) {
            $result['homepageError'] = $e->getMessage();
        }

        try {
            foreach ($this->http->listAllPages(
                $shopId,
                sprintf('/accounts/v1/accounts/%s/issues', $merchantId),
                'accountIssues'
            ) as $issue) {
                $impacted = [];
                foreach ($issue['impactedDestinations'] ?? [] as $dest) {
                    $impacted[] = (string) ($dest['reportingContext'] ?? '');
                }

                $result['issues'][] = [
                    'name' => (string) ($issue['name'] ?? ''),
                    'title' => (string) ($issue['title'] ?? ''),
                    'severity' => $this->mapAccountIssueSeverity((string) ($issue['severity'] ?? '')),
                    'detail' => (string) ($issue['detail'] ?? ''),
                    'documentation' => (string) ($issue['documentationUri'] ?? ''),
                    'impactedDestinations' => $impacted,
                ];
            }
        } catch (MerchantApiException $e) {
            $result['issuesError'] = $e->getMessage();
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Process a single processed Product from listProducts/getProduct response.
     *
     * @param array<string, mixed> $product
     */
    private function processProduct(int $shopId, array $product): int
    {
        $offerId = (string) ($product['offerId'] ?? '');
        $feedLabel = (string) ($product['feedLabel'] ?? '');
        $contentLanguage = (string) ($product['contentLanguage'] ?? '');

        if ($offerId === '') {
            return 0;
        }

        $localProduct = $this->findLocalProduct($shopId, $offerId, $feedLabel, $contentLanguage);
        if ($localProduct === null) {
            return 0;
        }

        $feedforgeProductId = (int) $localProduct['id_feedforge_product'];
        $status = $product['productStatus'] ?? null;

        $overallStatus = $status !== null ? $this->determineOverallStatus($status) : 'pending';
        $this->gmcProductRepository->updateStatus($feedforgeProductId, $overallStatus);

        $issues = $status !== null ? $this->extractIssues($status) : [];
        $this->productStatusRepository->replaceForProduct($feedforgeProductId, $issues);

        return count($issues);
    }

    /**
     * Aggregate destination statuses into a single overall status string for the UI.
     *
     * @param array<string, mixed> $status
     */
    private function determineOverallStatus(array $status): string
    {
        $hasApproved = false;
        $hasDisapproved = false;
        $hasPending = false;

        foreach ($status['destinationStatuses'] ?? [] as $destStatus) {
            if (!empty($destStatus['disapprovedCountries'])) {
                $hasDisapproved = true;
            }
            if (!empty($destStatus['pendingCountries'])) {
                $hasPending = true;
            }
            if (!empty($destStatus['approvedCountries'])) {
                $hasApproved = true;
            }
        }

        if ($hasDisapproved) {
            return 'disapproved';
        }
        if ($hasPending) {
            return 'pending';
        }
        if ($hasApproved) {
            return 'approved';
        }

        return 'pending';
    }

    /**
     * @param array<string, mixed> $status
     * @return array<int, array{issue_severity: string, issue_code: string, issue_message: string, issue_detail: string, destination: string}>
     */
    private function extractIssues(array $status): array
    {
        $rows = [];

        foreach ($status['itemLevelIssues'] ?? [] as $issue) {
            $rows[] = [
                'issue_severity' => $this->mapItemLevelSeverity((string) ($issue['severity'] ?? '')),
                'issue_code' => (string) ($issue['code'] ?? ''),
                'issue_message' => (string) ($issue['description'] ?? $issue['detail'] ?? ''),
                'issue_detail' => (string) ($issue['attribute'] ?? ''),
                'destination' => (string) ($issue['reportingContext'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Map Merchant API ItemLevelIssue severity (string enum) to local DB ENUM.
     */
    private function mapItemLevelSeverity(string $severity): string
    {
        return match ($severity) {
            'DISAPPROVED' => 'critical',
            'DEMOTED' => 'warning',
            'NOT_IMPACTED' => 'suggestion',
            default => 'warning',
        };
    }

    /**
     * Map Merchant API AccountIssue severity (string enum) to UI string.
     */
    private function mapAccountIssueSeverity(string $severity): string
    {
        return match ($severity) {
            'CRITICAL' => 'critical',
            'ERROR' => 'error',
            'SUGGESTION' => 'suggestion',
            default => 'warning',
        };
    }

    /**
     * Find a local feedforge_product row matching a Google product.
     *
     * @return array<string, mixed>|null
     */
    private function findLocalProduct(int $shopId, string $offerId, string $feedLabel, string $contentLanguage): ?array
    {
        $feedRows = $this->feedConfigRepository->findActive($shopId);
        $matchedFeed = null;
        foreach ($feedRows as $row) {
            if (
                strcasecmp($row['country_code'], $feedLabel) === 0
                && strcasecmp($row['language_code'], $contentLanguage) === 0
            ) {
                $matchedFeed = $row;
                break;
            }
        }
        if ($matchedFeed === null) {
            return null;
        }

        $prefix = (string) ($matchedFeed['offer_id_prefix'] ?? '');
        $bare = $offerId;
        if ($prefix !== '' && str_starts_with($offerId, $prefix)) {
            $bare = substr($offerId, strlen($prefix));
        }

        $productId = 0;
        $attributeId = 0;
        if (str_contains($bare, '-')) {
            [$pidStr, $aidStr] = explode('-', $bare, 2);
            if (is_numeric($pidStr) && is_numeric($aidStr)) {
                $productId = (int) $pidStr;
                $attributeId = (int) $aidStr;
            }
        } elseif (is_numeric($bare)) {
            $productId = (int) $bare;
        }

        if ($productId <= 0) {
            return null;
        }

        return $this->gmcProductRepository->findByProduct(
            $productId,
            $attributeId,
            $shopId,
            (int) $matchedFeed['id_feedforge_feed_config']
        );
    }
}
