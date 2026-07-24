<?php

declare(strict_types=1);

namespace FeedForge\Service;

use Doctrine\DBAL\Connection;
use FeedForge\Repository\AttributeMapRepository;
use FeedForge\Repository\CategoryMapRepository;
use FeedForge\Repository\FeedConfigRepository;

/**
 * Maps PrestaShop product data to Google Merchant Center product format.
 * Reads from PS database, applies mappings, rules, and transformations.
 */
class DataMapper
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CategoryMapRepository $categoryMapRepository,
        private readonly AttributeMapRepository $attributeMapRepository,
        private readonly FeedConfigRepository $feedConfigRepository,
        private readonly RuleEngine $ruleEngine,
    ) {
    }

    /**
     * Map a PS product to GMC format for a specific feed config.
     *
     * @return array|null GMC product data or null if excluded by rules
     */
    public function mapProduct(int $productId, int $attributeId, int $shopId, int $feedConfigId): ?array
    {
        $feedConfig = $this->feedConfigRepository->findById($feedConfigId);
        if (!$feedConfig) {
            return null;
        }

        $langId = $this->getLanguageId($feedConfig['language_code']);
        if (!$langId) {
            return null;
        }

        // Load PS product data
        $product = $this->loadProductData($productId, $langId, $shopId);
        if (!$product) {
            return null;
        }

        // Load combination data if applicable
        $combination = null;
        if ($attributeId > 0) {
            $combination = $this->loadCombinationData($attributeId, $langId);
        }

        // Build context for rule evaluation
        $context = $this->buildRuleContext($product, $combination, $feedConfig);

        // Check exclusion rules
        if ($this->ruleEngine->isExcluded($shopId, $context)) {
            return null;
        }

        // Build GMC product data
        $mapped = $this->buildGmcData($product, $combination, $feedConfig, $langId, $shopId);

        // Apply pricing rules
        $mapped = $this->ruleEngine->applyPricingRules($shopId, $mapped, $context);

        // Apply custom label rules
        $mapped = $this->ruleEngine->applyCustomLabelRules($shopId, $mapped, $context);

        // Apply identifier rules
        $mapped = $this->ruleEngine->applyIdentifierRules($shopId, $mapped, $context);

        // Apply attribute mappings (custom fields like color, size, etc.)
        $mapped = $this->applyAttributeMappings($mapped, $product, $combination, $shopId, $langId);

        return $mapped;
    }

    /**
     * Get active product IDs for a shop, optionally filtered by feed config criteria.
     *
     * @return array[] Each: ['id_product' => int, 'id_product_attribute' => int]
     */
    public function getActiveProductIds(int $shopId, ?int $feedConfigId = null): array
    {
        $prefix = _DB_PREFIX_;
        $params = ['shopId' => $shopId];
        $extraJoins = '';
        $extraWheres = '';

        // Load feed config filters if provided
        if ($feedConfigId !== null) {
            $feedConfig = $this->feedConfigRepository->findById($feedConfigId);
            $filters = [];
            if ($feedConfig && !empty($feedConfig['filters'])) {
                $filters = is_string($feedConfig['filters'])
                    ? (json_decode($feedConfig['filters'], true) ?? [])
                    : $feedConfig['filters'];
            }
            if (!empty($filters)) {
                [$extraJoins, $extraWheres, $filterParams] = $this->buildFilterSql($filters, $prefix);
                $params = array_merge($params, $filterParams);
            }
        }

        // Simple products (no combinations)
        $sql = "
            SELECT p.id_product, 0 AS id_product_attribute
            FROM {$prefix}product_shop ps
            JOIN {$prefix}product p ON p.id_product = ps.id_product
            {$extraJoins}
            WHERE ps.id_shop = :shopId
              AND ps.active = 1
              AND p.visibility IN ('both', 'search', 'catalog')
              {$extraWheres}
              AND NOT EXISTS (
                  SELECT 1 FROM {$prefix}product_attribute pa2
                  JOIN {$prefix}product_attribute_shop pas2 ON pa2.id_product_attribute = pas2.id_product_attribute AND pas2.id_shop = :shopId
                  WHERE pa2.id_product = p.id_product
              )

            UNION ALL

            SELECT pa.id_product, pa.id_product_attribute
            FROM {$prefix}product_attribute pa
            JOIN {$prefix}product_attribute_shop pas ON pa.id_product_attribute = pas.id_product_attribute
                AND pas.id_shop = :shopId
            JOIN {$prefix}product_shop ps ON pa.id_product = ps.id_product
                AND ps.id_shop = :shopId
                AND ps.active = 1
            JOIN {$prefix}product p ON p.id_product = pa.id_product
            {$extraJoins}
            WHERE p.visibility IN ('both', 'search', 'catalog')
              {$extraWheres}
        ";

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Build SQL fragments from feed-level filter config.
     *
     * @return array{string, string, array} [joins, wheres, params]
     */
    private function buildFilterSql(array $filters, string $prefix): array
    {
        $joins = [];
        $wheres = [];
        $params = [];

        if (!empty($filters['categories'])) {
            $catIds = implode(',', array_map('intval', $filters['categories']));
            if (($filters['categories_mode'] ?? 'include') === 'exclude') {
                $wheres[] = "p.id_product NOT IN (SELECT cp.id_product FROM {$prefix}category_product cp WHERE cp.id_category IN ({$catIds}))";
            } else {
                $joins[] = "JOIN {$prefix}category_product cp ON p.id_product = cp.id_product";
                $wheres[] = "cp.id_category IN ({$catIds})";
            }
        }

        if (!empty($filters['manufacturers'])) {
            $mfrIds = implode(',', array_map('intval', $filters['manufacturers']));
            if (($filters['manufacturers_mode'] ?? 'include') === 'exclude') {
                $wheres[] = "p.id_manufacturer NOT IN ({$mfrIds})";
            } else {
                $wheres[] = "p.id_manufacturer IN ({$mfrIds})";
            }
        }

        if (!empty($filters['skip_out_of_stock'])) {
            $joins[] = "JOIN {$prefix}stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0";
            $wheres[] = 'sa.quantity > 0';
        } elseif (!empty($filters['min_stock']) && (int) $filters['min_stock'] > 0) {
            $joins[] = "JOIN {$prefix}stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0";
            $wheres[] = 'sa.quantity >= :minStock';
            $params['minStock'] = (int) $filters['min_stock'];
        }

        if (!empty($filters['require_ean'])) {
            $wheres[] = "p.ean13 IS NOT NULL AND p.ean13 != ''";
        }

        if (!empty($filters['require_image'])) {
            $wheres[] = "EXISTS (SELECT 1 FROM {$prefix}image i WHERE i.id_product = p.id_product)";
        }

        if (!empty($filters['excluded_products'])) {
            $excIds = implode(',', array_map('intval', $filters['excluded_products']));
            $wheres[] = "p.id_product NOT IN ({$excIds})";
        }

        if (!empty($filters['suppliers'])) {
            $supIds = implode(',', array_map('intval', $filters['suppliers']));
            if (($filters['suppliers_mode'] ?? 'include') === 'exclude') {
                $wheres[] = "p.id_product NOT IN (SELECT psu.id_product FROM {$prefix}product_supplier psu WHERE psu.id_supplier IN ({$supIds}))";
            } else {
                $joins[] = "JOIN {$prefix}product_supplier psu ON p.id_product = psu.id_product";
                $wheres[] = "psu.id_supplier IN ({$supIds})";
            }
        }

        $joinSql = implode("\n", $joins);
        $whereSql = !empty($wheres) ? 'AND ' . implode(' AND ', $wheres) : '';

        return [$joinSql, $whereSql, $params];
    }

    /**
     * Compute a content hash for change detection.
     * SHA-1 of the serialized mapped data.
     */
    public function computeHash(array $mappedData): string
    {
        // Remove volatile fields that change without actual product changes
        $hashData = $mappedData;
        unset($hashData['link']); // URL may change due to rewrite rules

        ksort($hashData);

        return sha1(json_encode($hashData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build the GMC product data array from PS product.
     */
    private function buildGmcData(array $product, ?array $combination, array $feedConfig, int $langId, int $shopId): array
    {
        $productId = (int) $product['id_product'];
        $attributeId = $combination ? (int) $combination['id_product_attribute'] : 0;

        // Offer ID: configured prefix + "{productId}" or "{productId}-{attributeId}"
        // The prefix is per-feed and lets us match products that already exist in GMC under
        // a different naming scheme (e.g. legacy "PL123" instead of "123").
        $bareOfferId = $attributeId > 0 ? "{$productId}-{$attributeId}" : (string) $productId;
        $offerIdPrefix = (string) ($feedConfig['offer_id_prefix'] ?? '');
        $offerId = $offerIdPrefix . $bareOfferId;

        // Title
        $title = $this->buildTitle($product, $combination, $feedConfig, $langId);

        // Description
        $description = $this->buildDescription($product, $feedConfig);

        // Price
        $price = $this->calculatePrice($product, $combination, $feedConfig, $shopId);

        // Images
        $images = $this->getProductImages($productId, $attributeId, $langId, $shopId);

        // Product link
        $link = $this->getProductLink($productId, $attributeId, $langId, $shopId);

        // Availability
        $availability = $this->getAvailability($product, $combination);

        // Category mapping
        $googleCategory = $this->getGoogleCategory($product, $shopId);

        // Identifiers
        $identifiers = $this->getIdentifiers($product, $combination);

        // Build result
        // Note: in Merchant API the field is `feedLabel` (not `targetCountry`). We keep both
        // keys here so existing call sites and tests continue to work — ProductService accepts
        // either when building the ProductInput.
        $data = [
            'offerId' => $offerId,
            'title' => mb_substr($title, 0, 150), // GMC limit
            'description' => mb_substr(strip_tags($description), 0, 5000), // GMC limit
            'link' => $link,
            'imageLink' => $images['main'] ?? '',
            'additionalImageLinks' => $images['additional'] ?? [],
            'contentLanguage' => strtolower($feedConfig['language_code']),
            'feedLabel' => strtoupper($feedConfig['country_code']),
            'targetCountry' => strtoupper($feedConfig['country_code']),
            'channel' => 'online',
            'availability' => $availability,
            'condition' => $this->mapCondition($product),
            'price' => [
                'value' => number_format($price['price'], 2, '.', ''),
                'currency' => strtoupper($feedConfig['currency_code']),
            ],
        ];

        // Sale price
        if ($price['salePrice'] !== null && $price['salePrice'] < $price['price']) {
            $data['salePrice'] = [
                'value' => number_format($price['salePrice'], 2, '.', ''),
                'currency' => strtoupper($feedConfig['currency_code']),
            ];
        }

        // Identifiers
        if (!empty($identifiers['gtin'])) {
            $data['gtin'] = $identifiers['gtin'];
        }
        if (!empty($identifiers['mpn'])) {
            $data['mpn'] = $identifiers['mpn'];
        }
        if (!empty($identifiers['brand'])) {
            $data['brand'] = $identifiers['brand'];
        }
        $data['identifierExists'] = !empty($identifiers['gtin']) || !empty($identifiers['mpn']);

        // Google category
        if ($googleCategory) {
            $data['googleProductCategory'] = $googleCategory;
        }

        // Product type (PS category path)
        $categoryPath = $this->getCategoryPath($product, $langId);
        if ($categoryPath) {
            $data['productType'] = $categoryPath;
        }

        // Item group ID for variants
        if ($attributeId > 0) {
            $data['itemGroupId'] = (string) $productId;
        }

        // Weight
        $weight = (float) ($product['weight'] ?? 0);
        if ($combination) {
            $weight += (float) ($combination['weight'] ?? 0);
        }
        if ($weight > 0) {
            $data['shippingWeight'] = [
                'value' => (string) round($weight, 3),
                'unit' => 'kg',
            ];
        }

        return $data;
    }

    /**
     * Build product title from template.
     */
    private function buildTitle(array $product, ?array $combination, array $feedConfig, int $langId): string
    {
        $template = $feedConfig['title_template'] ?? '{name}';

        $title = str_replace(
            ['{name}', '{brand}', '{reference}', '{ean}'],
            [
                $product['name'] ?? '',
                $product['manufacturer_name'] ?? '',
                $product['reference'] ?? '',
                $product['ean13'] ?? '',
            ],
            $template
        );

        // Append combination attributes to title
        if ($combination && !empty($combination['attributes'])) {
            $attrParts = [];
            foreach ($combination['attributes'] as $attr) {
                $attrParts[] = $attr['value'];
            }
            if ($attrParts) {
                $title .= ' - ' . implode(', ', $attrParts);
            }
        }

        return trim($title);
    }

    /**
     * Build product description.
     */
    private function buildDescription(array $product, array $feedConfig): string
    {
        $source = $feedConfig['description_source'] ?? 'description_short';

        return match ($source) {
            'description' => $product['description'] ?? '',
            'both' => trim(($product['description_short'] ?? '') . "\n\n" . ($product['description'] ?? '')),
            default => $product['description_short'] ?? '',
        };
    }

    /**
     * Calculate product price (with or without tax based on feed config).
     *
     * @return array{price: float, salePrice: float|null}
     */
    private function calculatePrice(array $product, ?array $combination, array $feedConfig, int $shopId): array
    {
        $productId = (int) $product['id_product'];
        $attributeId = $combination ? (int) $combination['id_product_attribute'] : 0;
        $includeTax = (bool) ($feedConfig['include_tax'] ?? true);

        // Use PS native price calculation for accuracy
        $price = (float) \Product::getPriceStatic(
            $productId,
            $includeTax,
            $attributeId ?: null,
            6,     // decimals
            null,  // divisor
            false, // only reduction
            true,  // use reduction
            1,     // quantity
            false, // force associated tax
            null,  // customer ID
            null,  // cart ID
            null,  // address ID
            $specificPriceOutput,
            true,  // with ecotax
            true,  // use group reduction
            null,  // context
            true,  // use customer price
            null   // product attribute combination ID
        );

        // Regular price (without specific price reductions)
        $regularPrice = (float) \Product::getPriceStatic(
            $productId,
            $includeTax,
            $attributeId ?: null,
            6,
            null,
            false,
            false, // no reduction
            1
        );

        $salePrice = null;
        if ($price < $regularPrice) {
            $salePrice = $price;
            $price = $regularPrice;
        }

        return [
            'price' => $price,
            'salePrice' => $salePrice,
        ];
    }

    /**
     * Get product images (main + additional).
     *
     * @return array{main: string|null, additional: string[]}
     */
    private function getProductImages(int $productId, int $attributeId, int $langId, int $shopId): array
    {
        $prefix = _DB_PREFIX_;
        $result = ['main' => null, 'additional' => []];

        // Get product link_rewrite for proper image URL generation
        $linkRewrite = (string) $this->connection->fetchOne(
            "SELECT link_rewrite FROM {$prefix}product_lang WHERE id_product = :productId AND id_lang = :langId AND id_shop = :shopId LIMIT 1",
            ['productId' => $productId, 'langId' => $langId, 'shopId' => $shopId]
        );
        if (empty($linkRewrite)) {
            $linkRewrite = (string) $this->connection->fetchOne(
                "SELECT link_rewrite FROM {$prefix}product_lang WHERE id_product = :productId AND id_lang = :langId LIMIT 1",
                ['productId' => $productId, 'langId' => $langId]
            );
        }

        // Get images, prioritizing combination-specific images
        $sql = "
            SELECT i.id_image, il.legend,
                   IF(pai.id_product_attribute IS NOT NULL, 1, 0) AS is_combination_image
            FROM {$prefix}image i
            JOIN {$prefix}image_shop ish ON i.id_image = ish.id_image AND ish.id_shop = :shopId
            LEFT JOIN {$prefix}image_lang il ON i.id_image = il.id_image AND il.id_lang = :langId
            LEFT JOIN {$prefix}product_attribute_image pai ON i.id_image = pai.id_image
                AND pai.id_product_attribute = :attributeId
            WHERE i.id_product = :productId
            ORDER BY is_combination_image DESC, i.position ASC
            LIMIT 11
        ";

        $images = $this->connection->fetchAllAssociative($sql, [
            'productId' => $productId,
            'attributeId' => $attributeId,
            'shopId' => $shopId,
            'langId' => $langId,
        ]);

        $baseLink = \Context::getContext()->link;

        foreach ($images as $index => $img) {
            $imageUrl = $baseLink->getImageLink(
                $linkRewrite ?: 'product',
                (string) $img['id_image'],
                \ImageType::getFormattedName('large')
            );

            // Ensure HTTPS
            if (strpos($imageUrl, 'http://') === 0) {
                $imageUrl = 'https://' . substr($imageUrl, 7);
            }

            if ($index === 0) {
                $result['main'] = $imageUrl;
            } else {
                $result['additional'][] = $imageUrl;
            }
        }

        return $result;
    }

    /**
     * Get product link (canonical URL).
     */
    private function getProductLink(int $productId, int $attributeId, int $langId, int $shopId): string
    {
        $link = \Context::getContext()->link;

        $url = $link->getProductLink(
            $productId,
            null,
            null,
            null,
            $langId,
            $shopId,
            $attributeId ?: 0
        );

        // Ensure HTTPS
        if (strpos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }

    /**
     * Determine product availability.
     */
    private function getAvailability(array $product, ?array $combination): string
    {
        $quantity = (int) ($product['quantity'] ?? 0);

        if ($combination) {
            $quantity = (int) ($combination['quantity'] ?? $quantity);
        }

        if ($quantity > 0) {
            return 'in_stock';
        }

        // Check if out-of-stock ordering is allowed
        $outOfStockBehavior = (int) ($product['out_of_stock'] ?? 0);
        if ($outOfStockBehavior === 1 || ($outOfStockBehavior === 2 && \Configuration::get('PS_ORDER_OUT_OF_STOCK'))) {
            return 'backorder';
        }

        return 'out_of_stock';
    }

    /**
     * Map PS product condition to GMC condition.
     */
    private function mapCondition(array $product): string
    {
        return match ($product['condition'] ?? 'new') {
            'refurbished' => 'refurbished',
            'used' => 'used',
            default => 'new',
        };
    }

    /**
     * Get product identifiers (GTIN, MPN, brand).
     *
     * @return array{gtin: string, mpn: string, brand: string}
     */
    private function getIdentifiers(array $product, ?array $combination): array
    {
        $gtinSource = \Configuration::get('FEEDFORGE_GTIN_SOURCE') ?: 'ean';
        $mpnSource = \Configuration::get('FEEDFORGE_MPN_SOURCE') ?: 'reference';

        // GTIN: use combination value if exists, fallback to product
        $gtin = '';
        if ($gtinSource === 'ean') {
            $gtin = $combination['ean13'] ?? $product['ean13'] ?? '';
        } elseif ($gtinSource === 'upc') {
            $gtin = $combination['upc'] ?? $product['upc'] ?? '';
        } elseif ($gtinSource === 'isbn') {
            $gtin = $combination['isbn'] ?? $product['isbn'] ?? '';
        }

        // MPN: use combination value if exists, fallback to product
        $mpn = '';
        if ($mpnSource === 'reference') {
            $mpn = $combination['reference'] ?? $product['reference'] ?? '';
        } elseif ($mpnSource === 'supplier_reference') {
            $mpn = $combination['supplier_reference'] ?? $product['supplier_reference'] ?? '';
        }

        // Brand from manufacturer
        $brand = $product['manufacturer_name'] ?? '';

        return [
            'gtin' => trim($gtin),
            'mpn' => trim($mpn),
            'brand' => trim($brand),
        ];
    }

    /**
     * Get mapped Google category for product.
     */
    private function getGoogleCategory(array $product, int $shopId): ?string
    {
        $categoryId = (int) ($product['id_category_default'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        // Walk up the category tree looking for a mapping
        $maxDepth = 10;
        $currentCategoryId = $categoryId;

        for ($i = 0; $i < $maxDepth; $i++) {
            $mapping = $this->categoryMapRepository->findByCategory($currentCategoryId, $shopId);
            if ($mapping && !empty($mapping['google_taxonomy_path'])) {
                return $mapping['google_taxonomy_path'];
            }

            // Get parent category
            $prefix = _DB_PREFIX_;
            $parentId = (int) $this->connection->fetchOne(
                "SELECT id_parent FROM {$prefix}category WHERE id_category = :id",
                ['id' => $currentCategoryId]
            );

            if ($parentId <= 1) {
                break; // Reached root
            }

            $currentCategoryId = $parentId;
        }

        return null;
    }

    /**
     * Get PS category path for productType field.
     */
    private function getCategoryPath(array $product, int $langId): ?string
    {
        $categoryId = (int) ($product['id_category_default'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        $prefix = _DB_PREFIX_;
        $parts = [];
        $currentCategoryId = $categoryId;
        $maxDepth = 10;

        for ($i = 0; $i < $maxDepth; $i++) {
            $row = $this->connection->fetchAssociative(
                "SELECT c.id_parent, cl.name
                 FROM {$prefix}category c
                 JOIN {$prefix}category_lang cl ON c.id_category = cl.id_category AND cl.id_lang = :langId
                 WHERE c.id_category = :id",
                ['id' => $currentCategoryId, 'langId' => $langId]
            );

            if (!$row || (int) $row['id_parent'] <= 1) {
                if ($row && !empty($row['name'])) {
                    $parts[] = $row['name'];
                }
                break;
            }

            $parts[] = $row['name'];
            $currentCategoryId = (int) $row['id_parent'];
        }

        if (empty($parts)) {
            return null;
        }

        // Reverse to get root > child > leaf order
        return implode(' > ', array_reverse($parts));
    }

    /**
     * Apply attribute mappings (color, size, gender, material, etc.).
     */
    private function applyAttributeMappings(array $mapped, array $product, ?array $combination, int $shopId, int $langId): array
    {
        $mappings = $this->attributeMapRepository->findByShop($shopId);

        foreach ($mappings as $attrMap) {
            if (!(int) ($attrMap['active'] ?? 1)) {
                continue;
            }

            $gmcField = $attrMap['gmc_field'] ?? '';
            $sourceType = $attrMap['ps_source_type'] ?? '';
            $sourceId = (int) ($attrMap['ps_source_id'] ?? 0);

            $value = $this->resolveAttributeValue($sourceType, $sourceId, $product, $combination, $langId);
            if ($value === null || $value === '') {
                continue;
            }

            // Map GMC fields
            switch ($gmcField) {
                case 'color':
                    $mapped['color'] = $value;
                    break;
                case 'size':
                    $mapped['sizes'] = [$value];
                    break;
                case 'gender':
                    $mapped['gender'] = $this->normalizeGender($value);
                    break;
                case 'age_group':
                    $mapped['ageGroup'] = $this->normalizeAgeGroup($value);
                    break;
                case 'material':
                    $mapped['material'] = $value;
                    break;
                case 'pattern':
                    $mapped['pattern'] = $value;
                    break;
            }
        }

        return $mapped;
    }

    /**
     * Resolve an attribute/feature value from PS data.
     */
    private function resolveAttributeValue(string $sourceType, int $sourceId, array $product, ?array $combination, int $langId): ?string
    {
        $prefix = _DB_PREFIX_;

        switch ($sourceType) {
            case 'attribute_group':
                // Get from combination attributes
                if ($combination && !empty($combination['attributes'])) {
                    foreach ($combination['attributes'] as $attr) {
                        if ((int) $attr['id_attribute_group'] === $sourceId) {
                            return $attr['value'];
                        }
                    }
                }
                return null;

            case 'feature':
                $result = $this->connection->fetchOne(
                    "SELECT fvl.value
                     FROM {$prefix}feature_product fp
                     JOIN {$prefix}feature_value_lang fvl ON fp.id_feature_value = fvl.id_feature_value
                         AND fvl.id_lang = :langId
                     WHERE fp.id_product = :productId AND fp.id_feature = :featureId
                     LIMIT 1",
                    [
                        'productId' => (int) $product['id_product'],
                        'featureId' => $sourceId,
                        'langId' => $langId,
                    ]
                );
                return $result ?: null;

            case 'field':
                // Direct product field
                $fieldName = $this->getFieldNameById($sourceId);
                return $product[$fieldName] ?? null;

            default:
                return null;
        }
    }

    /**
     * Map source ID to product field name for 'field' type.
     */
    private function getFieldNameById(int $id): string
    {
        // Convention: field IDs map to common product fields
        $map = [
            1 => 'reference',
            2 => 'ean13',
            3 => 'upc',
            4 => 'isbn',
            5 => 'weight',
            6 => 'width',
            7 => 'height',
            8 => 'depth',
        ];

        return $map[$id] ?? 'reference';
    }

    /**
     * Normalize gender value to GMC accepted values.
     */
    private function normalizeGender(string $value): string
    {
        $lower = strtolower(trim($value));

        $maleAliases = ['male', 'men', 'man', 'mężczyzna', 'mężczyźni', 'męski', 'męska'];
        $femaleAliases = ['female', 'women', 'woman', 'kobieta', 'kobiety', 'damski', 'damska'];
        $unisexAliases = ['unisex', 'both', 'uniseks'];

        if (in_array($lower, $maleAliases, true)) {
            return 'male';
        }
        if (in_array($lower, $femaleAliases, true)) {
            return 'female';
        }
        if (in_array($lower, $unisexAliases, true)) {
            return 'unisex';
        }

        return $value;
    }

    /**
     * Normalize age group value to GMC accepted values.
     */
    private function normalizeAgeGroup(string $value): string
    {
        $lower = strtolower(trim($value));

        $adultAliases = ['adult', 'adults', 'dorośli', 'dorosły'];
        $kidsAliases = ['kids', 'children', 'dzieci', 'dziecięcy'];
        $infantAliases = ['infant', 'baby', 'niemowlę', 'niemowlęcy'];
        $toddlerAliases = ['toddler', 'maluch'];
        $newbornAliases = ['newborn', 'noworodek'];

        if (in_array($lower, $adultAliases, true)) {
            return 'adult';
        }
        if (in_array($lower, $kidsAliases, true)) {
            return 'kids';
        }
        if (in_array($lower, $infantAliases, true)) {
            return 'infant';
        }
        if (in_array($lower, $toddlerAliases, true)) {
            return 'toddler';
        }
        if (in_array($lower, $newbornAliases, true)) {
            return 'newborn';
        }

        return $value;
    }

    /**
     * Load product data from PS database.
     */
    private function loadProductData(int $productId, int $langId, int $shopId): ?array
    {
        $prefix = _DB_PREFIX_;

        $sql = "
            SELECT
                p.id_product,
                p.id_category_default,
                p.reference,
                p.ean13,
                p.upc,
                p.isbn,
                p.supplier_reference,
                p.weight,
                p.width,
                p.height,
                p.depth,
                p.condition,
                p.out_of_stock,
                p.visibility,
                ps.price,
                ps.active,
                pl.name,
                pl.description,
                pl.description_short,
                pl.link_rewrite,
                m.name AS manufacturer_name,
                sa.quantity
            FROM {$prefix}product p
            JOIN {$prefix}product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = :shopId
            JOIN {$prefix}product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = :langId AND pl.id_shop = :shopId
            LEFT JOIN {$prefix}manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN {$prefix}stock_available sa ON p.id_product = sa.id_product
                AND sa.id_product_attribute = 0 AND sa.id_shop = :shopId
            WHERE p.id_product = :productId
            LIMIT 1
        ";

        $result = $this->connection->fetchAssociative($sql, [
            'productId' => $productId,
            'langId' => $langId,
            'shopId' => $shopId,
        ]);

        return $result ?: null;
    }

    /**
     * Load combination data with its attribute values.
     */
    private function loadCombinationData(int $attributeId, int $langId): ?array
    {
        $prefix = _DB_PREFIX_;

        // Load combination base data
        $combination = $this->connection->fetchAssociative(
            "SELECT
                pa.id_product_attribute,
                pa.id_product,
                pa.reference,
                pa.ean13,
                pa.upc,
                pa.isbn,
                pa.supplier_reference,
                pa.weight,
                pa.price AS impact_price,
                sa.quantity
             FROM {$prefix}product_attribute pa
             LEFT JOIN {$prefix}stock_available sa ON pa.id_product_attribute = sa.id_product_attribute
                 AND sa.id_product = pa.id_product
             WHERE pa.id_product_attribute = :attributeId
             LIMIT 1",
            ['attributeId' => $attributeId]
        );

        if (!$combination) {
            return null;
        }

        // Load attribute values for this combination
        $attributes = $this->connection->fetchAllAssociative(
            "SELECT
                ag.id_attribute_group,
                agl.name AS group_name,
                al.name AS value
             FROM {$prefix}product_attribute_combination pac
             JOIN {$prefix}attribute a ON pac.id_attribute = a.id_attribute
             JOIN {$prefix}attribute_group ag ON a.id_attribute_group = ag.id_attribute_group
             JOIN {$prefix}attribute_group_lang agl ON ag.id_attribute_group = agl.id_attribute_group
                 AND agl.id_lang = :langId
             JOIN {$prefix}attribute_lang al ON a.id_attribute = al.id_attribute
                 AND al.id_lang = :langId
             WHERE pac.id_product_attribute = :attributeId",
            ['attributeId' => $attributeId, 'langId' => $langId]
        );

        $combination['attributes'] = $attributes;

        return $combination;
    }

    /**
     * Build context array for rule evaluation.
     */
    private function buildRuleContext(array $product, ?array $combination, array $feedConfig): array
    {
        return [
            'id_product' => (int) $product['id_product'],
            'id_category_default' => (int) ($product['id_category_default'] ?? 0),
            'price' => (float) ($product['price'] ?? 0),
            'quantity' => (int) ($product['quantity'] ?? 0),
            'condition' => $product['condition'] ?? 'new',
            'brand' => $product['manufacturer_name'] ?? '',
            'ean13' => $combination['ean13'] ?? $product['ean13'] ?? '',
            'reference' => $combination['reference'] ?? $product['reference'] ?? '',
            'weight' => (float) ($product['weight'] ?? 0),
            'visibility' => $product['visibility'] ?? 'both',
            'country_code' => $feedConfig['country_code'] ?? '',
            'has_combination' => $combination !== null,
        ];
    }

    /**
     * Get language ID from ISO code.
     */
    private function getLanguageId(string $isoCode): ?int
    {
        $id = (int) \Language::getIdByIso($isoCode);

        return $id > 0 ? $id : null;
    }
}
