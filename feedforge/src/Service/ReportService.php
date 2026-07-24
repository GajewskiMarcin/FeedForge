<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\ReportCacheRepository;

/**
 * Fetches performance data (impressions, clicks) from Merchant API Reports and caches it locally.
 *
 * v2.0.1: Rewritten on top of MerchantApiHttpClient (raw REST/JSON).
 *
 * Endpoint: POST /reports/v1/{parent}/reports:search
 * Query language: MQL (Merchant Center Query Language). We query product_performance_view.
 */
class ReportService
{
    /** Don't refetch if data is less than 6 hours old. */
    private const CACHE_TTL_HOURS = 6;

    /** Maximum days fetched in a single backfill (Google's MQL limit is 90 days per request). */
    private const MAX_REPORT_DAYS = 90;

    public function __construct(
        private readonly MerchantApiHttpClient $http,
        private readonly GoogleApiClient $apiClient,
        private readonly ReportCacheRepository $reportCacheRepository,
    ) {
    }

    /**
     * @return array{impressions: int, clicks: int, ctr: float}
     */
    public function getSummary(int $shopId, string $startDate, string $endDate, ?string $countryCode = null): array
    {
        $this->ensureCacheIsFresh($shopId, $startDate, $endDate);

        $data = $this->reportCacheRepository->getAggregated($shopId, $startDate, $endDate, $countryCode);

        $impressions = (int) ($data['total_impressions'] ?? 0);
        $clicks = (int) ($data['total_clicks'] ?? 0);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
        ];
    }

    /**
     * @return array<int, array{date: string, impressions: int, clicks: int, ctr: float}>
     */
    public function getTimeSeries(int $shopId, string $startDate, string $endDate): array
    {
        $this->ensureCacheIsFresh($shopId, $startDate, $endDate);

        $rows = $this->reportCacheRepository->getTimeSeries($shopId, $startDate, $endDate);

        return array_map(function (array $row): array {
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];

            return [
                'date' => $row['report_date'],
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopProducts(int $shopId, string $startDate, string $endDate, int $limit = 10): array
    {
        $this->ensureCacheIsFresh($shopId, $startDate, $endDate);

        return $this->reportCacheRepository->getByProduct($shopId, $startDate, $endDate, $limit);
    }

    /**
     * @return array<int, array{countryCode: string, impressions: int, clicks: int, ctr: float}>
     */
    public function getByCountry(int $shopId, string $startDate, string $endDate): array
    {
        $this->ensureCacheIsFresh($shopId, $startDate, $endDate);

        $rows = $this->reportCacheRepository->getByCountry($shopId, $startDate, $endDate);

        return array_map(function (array $row): array {
            $impressions = (int) $row['impressions'];
            $clicks = (int) $row['clicks'];

            return [
                'countryCode' => $row['country_code'],
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Force-refresh report data from Google.
     *
     * @return int Number of cache rows written
     */
    public function fetchFromApi(int $shopId, string $startDate, string $endDate): int
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);

        $query = sprintf(
            "SELECT date, customer_country_code, offer_id, clicks, impressions, click_through_rate "
            . "FROM product_performance_view "
            . "WHERE date BETWEEN '%s' AND '%s'",
            $startDate,
            $endDate
        );

        $count = 0;
        $aggregated = []; // [date][country] => ['impressions' => N, 'clicks' => N]
        $pageToken = null;

        try {
            do {
                $body = ['query' => $query];
                if ($pageToken !== null && $pageToken !== '') {
                    $body['pageToken'] = $pageToken;
                }

                $response = $this->http->post(
                    $shopId,
                    sprintf('/reports/v1/accounts/%s/reports:search', $merchantId),
                    $body
                );

                foreach ($response['results'] ?? [] as $row) {
                    $view = $row['productPerformanceView'] ?? null;
                    if ($view === null) {
                        continue;
                    }

                    $date = $this->extractDate($view['date'] ?? null);
                    if ($date === '') {
                        continue;
                    }

                    $country = (string) ($view['customerCountryCode'] ?? '');
                    if ($country === '') {
                        $country = 'ALL';
                    }

                    if (!isset($aggregated[$date][$country])) {
                        $aggregated[$date][$country] = ['impressions' => 0, 'clicks' => 0];
                    }

                    $aggregated[$date][$country]['impressions'] += (int) ($view['impressions'] ?? 0);
                    $aggregated[$date][$country]['clicks'] += (int) ($view['clicks'] ?? 0);
                }

                $pageToken = (string) ($response['nextPageToken'] ?? '');
            } while ($pageToken !== '');

            foreach ($aggregated as $date => $byCountry) {
                foreach ($byCountry as $country => $totals) {
                    $imp = $totals['impressions'];
                    $clk = $totals['clicks'];

                    $this->reportCacheRepository->upsertDaily([
                        'id_shop' => $shopId,
                        'report_date' => $date,
                        'country_code' => $country,
                        'impressions' => $imp,
                        'clicks' => $clk,
                        'ctr' => $imp > 0 ? round($clk / $imp, 4) : 0.0,
                    ]);

                    $count++;
                }
            }
        } catch (MerchantApiException $e) {
            // Reports may not be available for new accounts (no traffic yet) or sub-accounts of MCAs.
            $code = $e->getHttpStatus();
            if ($code !== 0 && $code !== 403 && $code !== 404) {
                throw $e;
            }
        }

        return $count;
    }

    public function cleanup(int $retentionDays = 365): int
    {
        return $this->reportCacheRepository->cleanup($retentionDays);
    }

    private function ensureCacheIsFresh(int $shopId, string $startDate, string $endDate): void
    {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        if ($endDate < $thirtyDaysAgo) {
            return;
        }

        $recentData = $this->reportCacheRepository->getAggregated($shopId, $endDate, $endDate);
        if (!empty($recentData) && (int) ($recentData['total_impressions'] ?? 0) > 0) {
            return;
        }

        $fetchStart = max($startDate, date('Y-m-d', strtotime('-' . self::MAX_REPORT_DAYS . ' days')));

        try {
            $this->fetchFromApi($shopId, $fetchStart, $endDate);
        } catch (\Throwable $e) {
            // Cache miss is not critical — display whatever we have.
        }
    }

    /**
     * Convert a Google\Type\Date JSON object {year, month, day} to ISO date string.
     * Returns empty string if components are missing.
     *
     * @param array<string, mixed>|null $date
     */
    private function extractDate(?array $date): string
    {
        if ($date === null) {
            return '';
        }

        $year = (int) ($date['year'] ?? 0);
        $month = (int) ($date['month'] ?? 0);
        $day = (int) ($date['day'] ?? 0);

        if ($year === 0 || $month === 0 || $day === 0) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
