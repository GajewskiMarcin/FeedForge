<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Entity\FeedConfig;
use FeedForge\Repository\FeedConfigRepository;

/**
 * Manages Google Merchant API "API data sources" — required containers for product uploads.
 *
 * Each Feed Forge feed config maps 1:1 to a Merchant API DataSource. The DataSource
 * resource name (format: "accounts/{merchant}/dataSources/{id}") is persisted on the
 * feed_config row in data_source_id (the numeric ID portion) and data_source_name
 * (the full resource name).
 *
 * v2.0.1: Rewritten to use MerchantApiHttpClient (raw REST/JSON) instead of the official
 * PHP SDK. Eliminates the protobuf parser entirely, which was hitting the "Fail to push
 * limit" bug in pure-PHP and a descriptor-format conflict with ext-protobuf.
 */
class DataSourceService
{
    /** Merchant API base path for the DataSources sub-API. */
    private const BASE_PATH = '/datasources/v1';

    public function __construct(
        private readonly MerchantApiHttpClient $http,
        private readonly GoogleApiClient $apiClient,
        private readonly FeedConfigRepository $feedConfigRepository,
    ) {
    }

    /**
     * Ensure that the given feed config has a Merchant API DataSource provisioned.
     * If one already exists (data_source_name is set), this is a no-op. Otherwise
     * creates a new primary product DataSource matching the feed's country/language
     * and stores its name.
     *
     * @return string The full DataSource resource name ("accounts/{merchant}/dataSources/{id}").
     */
    public function ensureForFeed(int $shopId, FeedConfig $feed): string
    {
        if ($feed->data_source_name !== null && $feed->data_source_name !== '') {
            return $feed->data_source_name;
        }

        $dataSourceName = $this->createForFeed($shopId, $feed);
        $idPart = $this->extractIdFromName($dataSourceName);

        $this->feedConfigRepository->setDataSource(
            $feed->id_feedforge_feed_config,
            $idPart,
            $dataSourceName
        );

        return $dataSourceName;
    }

    /**
     * Create a new primary product DataSource in Google Merchant Center for the given feed config.
     */
    public function createForFeed(int $shopId, FeedConfig $feed): string
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);

        $body = [
            'displayName' => sprintf(
                'Feed Forge — %s/%s/%s',
                strtoupper($feed->country_code),
                strtolower($feed->language_code),
                strtoupper($feed->currency_code)
            ),
            'primaryProductDataSource' => [
                'feedLabel' => strtoupper($feed->country_code),
                'contentLanguage' => strtolower($feed->language_code),
                'countries' => [strtoupper($feed->country_code)],
                'destinations' => [
                    ['destination' => 'SHOPPING_ADS', 'state' => 'ENABLED'],
                    ['destination' => 'FREE_LISTINGS', 'state' => 'ENABLED'],
                ],
            ],
        ];

        try {
            $response = $this->http->post(
                $shopId,
                sprintf('%s/accounts/%s/dataSources', self::BASE_PATH, $merchantId),
                $body
            );
        } catch (MerchantApiException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to create Merchant API DataSource for feed #%d (%s/%s): %s',
                    $feed->id_feedforge_feed_config,
                    $feed->country_code,
                    $feed->language_code,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return (string) ($response['name'] ?? '');
    }

    /**
     * List all DataSources currently configured in Google Merchant Center for this shop.
     *
     * @return array<int, array{name: string, displayName: string, feedLabel: string, contentLanguage: string, isPrimary: bool}>
     */
    public function listAll(int $shopId): array
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);

        $results = [];
        try {
            foreach ($this->http->listAllPages(
                $shopId,
                sprintf('%s/accounts/%s/dataSources', self::BASE_PATH, $merchantId),
                'dataSources'
            ) as $ds) {
                $primary = $ds['primaryProductDataSource'] ?? null;
                $isPrimary = $primary !== null;

                $results[] = [
                    'name' => (string) ($ds['name'] ?? ''),
                    'displayName' => (string) ($ds['displayName'] ?? ''),
                    'feedLabel' => $isPrimary ? (string) ($primary['feedLabel'] ?? '') : '',
                    'contentLanguage' => $isPrimary ? (string) ($primary['contentLanguage'] ?? '') : '',
                    'isPrimary' => $isPrimary,
                ];
            }
        } catch (MerchantApiException $e) {
            throw new \RuntimeException(
                'Failed to list Merchant API DataSources: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $results;
    }

    /**
     * Delete a DataSource from Google Merchant Center.
     * WARNING: this also removes all products inserted into that data source.
     */
    public function delete(int $shopId, string $dataSourceName): void
    {
        try {
            // Resource names start with "accounts/" so we prefix only with the base path.
            $this->http->delete($shopId, self::BASE_PATH . '/' . $dataSourceName);
        } catch (MerchantApiException $e) {
            throw new \RuntimeException(
                sprintf('Failed to delete Merchant API DataSource %s: %s', $dataSourceName, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Provision DataSources for every feed config in this shop that doesn't have one yet.
     *
     * @return array{provisioned: int, errors: array<int, string>}
     */
    public function provisionMissing(int $shopId): array
    {
        $rows = $this->feedConfigRepository->findWithoutDataSource($shopId);

        $provisioned = 0;
        $errors = [];

        foreach ($rows as $row) {
            $feed = FeedConfig::fromArray($row);

            try {
                $this->ensureForFeed($shopId, $feed);
                $provisioned++;
            } catch (\Throwable $e) {
                $errors[] = sprintf(
                    'Feed #%d (%s/%s): %s',
                    $feed->id_feedforge_feed_config,
                    $feed->country_code,
                    $feed->language_code,
                    $e->getMessage()
                );
            }
        }

        return ['provisioned' => $provisioned, 'errors' => $errors];
    }

    /**
     * Ensure that a Promotion DataSource exists for the given country + language.
     * Promotion DataSources are separate from Product DataSources in Merchant API.
     * Looked up by listing all DataSources for the account and matching by country+language;
     * created on demand if not found.
     */
    public function ensurePromotionDataSourceForShop(int $shopId, string $targetCountry, string $contentLanguage): string
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);
        $country = strtoupper($targetCountry);
        $language = strtolower($contentLanguage);

        // Look for an existing promotion DataSource for this country+language.
        try {
            foreach ($this->http->listAllPages(
                $shopId,
                sprintf('%s/accounts/%s/dataSources', self::BASE_PATH, $merchantId),
                'dataSources'
            ) as $ds) {
                $promo = $ds['promotionDataSource'] ?? null;
                if ($promo === null) {
                    continue;
                }
                if (
                    strcasecmp((string) ($promo['targetCountry'] ?? ''), $country) === 0
                    && strcasecmp((string) ($promo['contentLanguage'] ?? ''), $language) === 0
                ) {
                    return (string) $ds['name'];
                }
            }
        } catch (MerchantApiException $e) {
            // Listing failed — fall through to create.
        }

        // Create a new Promotion DataSource.
        $body = [
            'displayName' => sprintf('Feed Forge Promotions — %s/%s', $country, $language),
            'promotionDataSource' => [
                'targetCountry' => $country,
                'contentLanguage' => $language,
            ],
        ];

        try {
            $response = $this->http->post(
                $shopId,
                sprintf('%s/accounts/%s/dataSources', self::BASE_PATH, $merchantId),
                $body
            );
        } catch (MerchantApiException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to create Merchant API Promotion DataSource for %s/%s: %s',
                    $country,
                    $language,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }

        return (string) ($response['name'] ?? '');
    }

    /**
     * Extract the numeric ID portion from a full DataSource resource name.
     * Input: "accounts/123456/dataSources/789012" → Output: "789012"
     */
    private function extractIdFromName(string $name): string
    {
        if (preg_match('#/dataSources/(\d+)$#', $name, $matches)) {
            return $matches[1];
        }

        return $name;
    }
}
