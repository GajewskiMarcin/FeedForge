<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\GmcProductRepository;

/**
 * Manages product CRUD against Google Merchant API (productInputs + products endpoints).
 *
 * v2.0.1: Rewritten to use MerchantApiHttpClient (raw REST/JSON) instead of the official
 * PHP SDK. Eliminates the protobuf parser entirely. Wire format on the network is the same
 * (REST + JSON) so behaviour is identical from Google's perspective.
 *
 * Endpoints used:
 * - POST /products/v1/{parent}/productInputs:insert    — upsert a product input
 * - DELETE /products/v1/{name}                          — delete a product input
 * - GET /products/v1/{name}                             — read processed product
 * - GET /products/v1/{parent}/products                  — list processed products
 */
class ProductService
{
    private const BASE_PATH = '/products/v1';

    public function __construct(
        private readonly MerchantApiHttpClient $http,
        private readonly GoogleApiClient $apiClient,
        private readonly GmcProductRepository $gmcProductRepository,
    ) {
    }

    /**
     * Insert a single product into a specific Merchant API DataSource.
     * If a product with the same (feedLabel, contentLanguage, offerId) already exists in this
     * data source, Google replaces it (upsert semantics).
     *
     * @return array{name: string, status: string}
     */
    public function insert(int $shopId, array $productData, string $dataSourceName): array
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);

        $body = $this->buildProductInputBody($productData);

        $response = $this->http->post(
            $shopId,
            sprintf('%s/accounts/%s/productInputs:insert', self::BASE_PATH, $merchantId),
            $body,
            ['dataSource' => $dataSourceName]
        );

        return [
            'name' => (string) ($response['name'] ?? ''),
            'status' => 'inserted',
        ];
    }

    /**
     * Delete a productInput from a Merchant API DataSource.
     */
    public function delete(int $shopId, string $productInputName, string $dataSourceName): void
    {
        $this->http->delete(
            $shopId,
            self::BASE_PATH . '/' . $productInputName,
            ['dataSource' => $dataSourceName]
        );
    }

    /**
     * Read a single processed product from Google. The processed product carries both the
     * resolved attributes and the current product status (approval, issues).
     *
     * @return array<string, mixed>|null Returns null on 404.
     */
    public function getProduct(int $shopId, string $productName): ?array
    {
        try {
            return $this->http->get($shopId, self::BASE_PATH . '/' . $productName);
        } catch (MerchantApiException $e) {
            if ($e->isNotFound()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * List processed products with pagination.
     *
     * @return array{products: array<int, array<string, mixed>>, nextPageToken: string}
     */
    public function listProducts(int $shopId, ?string $pageToken = null, int $pageSize = 250): array
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);
        $query = ['pageSize' => $pageSize];
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $response = $this->http->get(
            $shopId,
            sprintf('%s/accounts/%s/products', self::BASE_PATH, $merchantId),
            $query
        );

        return [
            'products' => $response['products'] ?? [],
            'nextPageToken' => (string) ($response['nextPageToken'] ?? ''),
        ];
    }

    /**
     * Iterate every processed product across all pages.
     * Used by StatusService to walk the entire account when refreshing statuses.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function iterateAllProducts(int $shopId, int $pageSize = 250): iterable
    {
        $merchantId = $this->apiClient->getMerchantId($shopId);

        yield from $this->http->listAllPages(
            $shopId,
            sprintf('%s/accounts/%s/products', self::BASE_PATH, $merchantId),
            'products',
            ['pageSize' => $pageSize]
        );
    }

    /**
     * Build the full ProductInput resource name from the components stored on a feed.
     */
    public function buildProductInputName(string $merchantId, string $contentLanguage, string $feedLabel, string $offerId): string
    {
        return sprintf(
            'accounts/%s/productInputs/%s~%s~%s',
            $merchantId,
            strtolower($contentLanguage),
            strtoupper($feedLabel),
            $offerId
        );
    }

    /**
     * Build the full Product (read) resource name from the same components.
     */
    public function buildProductName(string $merchantId, string $contentLanguage, string $feedLabel, string $offerId): string
    {
        return sprintf(
            'accounts/%s/products/%s~%s~%s',
            $merchantId,
            strtolower($contentLanguage),
            strtoupper($feedLabel),
            $offerId
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion: DataMapper flat array → Merchant API ProductInput JSON
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the ProductInput request body from the flat array produced by DataMapper.
     * Field names follow the Merchant API JSON convention (camelCase).
     *
     * @return array<string, mixed>
     */
    private function buildProductInputBody(array $data): array
    {
        $attributes = $this->buildProductAttributes($data);

        return [
            'offerId' => (string) ($data['offerId'] ?? ''),
            'contentLanguage' => strtolower((string) ($data['contentLanguage'] ?? '')),
            // Merchant API uses feedLabel; we accept either input key from DataMapper.
            'feedLabel' => strtoupper((string) ($data['feedLabel'] ?? $data['targetCountry'] ?? '')),
            'productAttributes' => $attributes,
        ];
    }

    /**
     * Build the productAttributes object.
     *
     * @return array<string, mixed>
     */
    private function buildProductAttributes(array $data): array
    {
        $attrs = [];

        if (isset($data['title'])) {
            $attrs['title'] = (string) $data['title'];
        }
        if (isset($data['description'])) {
            $attrs['description'] = (string) $data['description'];
        }
        if (isset($data['link'])) {
            $attrs['link'] = (string) $data['link'];
        }
        if (isset($data['imageLink'])) {
            $attrs['imageLink'] = (string) $data['imageLink'];
        }
        if (!empty($data['additionalImageLinks'])) {
            $attrs['additionalImageLinks'] = array_values((array) $data['additionalImageLinks']);
        }

        if (isset($data['availability'])) {
            $attrs['availability'] = $this->mapAvailability((string) $data['availability']);
        }
        if (isset($data['condition'])) {
            $attrs['condition'] = $this->mapCondition((string) $data['condition']);
        }

        if (isset($data['price'])) {
            $attrs['price'] = $this->buildPrice($data['price']);
        }
        if (isset($data['salePrice'])) {
            $attrs['salePrice'] = $this->buildPrice($data['salePrice']);
        }

        // Identifiers
        if (isset($data['gtin']) && $data['gtin'] !== '') {
            $attrs['gtins'] = [(string) $data['gtin']];
        }
        if (isset($data['mpn']) && $data['mpn'] !== '') {
            $attrs['mpn'] = (string) $data['mpn'];
        }
        if (isset($data['brand']) && $data['brand'] !== '') {
            $attrs['brand'] = (string) $data['brand'];
        }
        if (array_key_exists('identifierExists', $data)) {
            $attrs['identifierExists'] = (bool) $data['identifierExists'];
        }

        // Categories
        if (isset($data['googleProductCategory']) && $data['googleProductCategory'] !== '') {
            $attrs['googleProductCategory'] = (string) $data['googleProductCategory'];
        }
        if (!empty($data['productType'])) {
            $attrs['productTypes'] = is_array($data['productType'])
                ? $data['productType']
                : [(string) $data['productType']];
        }

        // Variant clothing attributes
        if (isset($data['color']) && $data['color'] !== '') {
            $attrs['color'] = (string) $data['color'];
        }
        if (!empty($data['sizes'])) {
            $attrs['size'] = is_array($data['sizes'])
                ? (string) ($data['sizes'][0] ?? '')
                : (string) $data['sizes'];
        }
        if (isset($data['gender']) && $data['gender'] !== '') {
            $attrs['gender'] = (string) $data['gender'];
        }
        if (isset($data['ageGroup']) && $data['ageGroup'] !== '') {
            $attrs['ageGroup'] = (string) $data['ageGroup'];
        }
        if (isset($data['material']) && $data['material'] !== '') {
            $attrs['material'] = (string) $data['material'];
        }
        if (isset($data['pattern']) && $data['pattern'] !== '') {
            $attrs['pattern'] = (string) $data['pattern'];
        }

        if (isset($data['itemGroupId']) && $data['itemGroupId'] !== '') {
            $attrs['itemGroupId'] = (string) $data['itemGroupId'];
        }

        // Custom labels
        for ($i = 0; $i < 5; $i++) {
            $key = 'customLabel' . $i;
            if (isset($data[$key]) && $data[$key] !== '') {
                $attrs[$key] = (string) $data[$key];
            }
        }

        // Shipping weight
        if (isset($data['shippingWeight']['value'])) {
            $attrs['shippingWeight'] = [
                'value' => (float) $data['shippingWeight']['value'],
                'unit' => (string) ($data['shippingWeight']['unit'] ?? 'kg'),
            ];
        }

        // Promotion IDs (for Promotions API integration)
        if (!empty($data['promotionIds'])) {
            $attrs['promotionIds'] = array_values((array) $data['promotionIds']);
        }

        return $attrs;
    }

    /**
     * Build a Merchant API Price (amountMicros + currencyCode).
     * 1 unit = 1,000,000 micros. We use bcmath to avoid floating-point precision loss.
     *
     * @param array{value: string|float|int, currency: string} $price
     * @return array{amountMicros: string, currencyCode: string}
     */
    private function buildPrice(array $price): array
    {
        $value = (string) ($price['value'] ?? '0');
        $currency = (string) ($price['currency'] ?? '');

        $micros = bcmul($value, '1000000', 0);

        return [
            'amountMicros' => $micros,
            'currencyCode' => $currency,
        ];
    }

    /**
     * Map a Content API style availability string to a Merchant API enum string.
     */
    private function mapAvailability(string $availability): string
    {
        return match (strtolower($availability)) {
            'in_stock', 'in stock' => 'IN_STOCK',
            'out_of_stock', 'out of stock' => 'OUT_OF_STOCK',
            'preorder' => 'PREORDER',
            'backorder' => 'BACKORDER',
            'limited_availability', 'limited availability' => 'LIMITED_AVAILABILITY',
            default => 'AVAILABILITY_UNSPECIFIED',
        };
    }

    /**
     * Map a Content API style condition string to a Merchant API enum string.
     */
    private function mapCondition(string $condition): string
    {
        return match (strtolower($condition)) {
            'new' => 'NEW',
            'used' => 'USED',
            'refurbished' => 'REFURBISHED',
            default => 'CONDITION_UNSPECIFIED',
        };
    }
}
