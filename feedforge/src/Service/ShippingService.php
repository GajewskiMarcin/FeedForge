<?php

declare(strict_types=1);

/**
 * Feed Forge - Google Merchant Center integration for PrestaShop
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace FeedForge\Service;

use Doctrine\DBAL\Connection;
use FeedForge\Repository\FeedConfigRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps PrestaShop carriers to Google Merchant Center ShippingSettings.
 *
 * v2.0.1: Rewritten on top of MerchantApiHttpClient (raw REST/JSON).
 *
 * Endpoints:
 * - POST /accounts/v1/{parent}/shippingSettings:insert  — upsert (no separate update method)
 * - GET  /accounts/v1/{name}                            — read
 */
class ShippingService
{
    public function __construct(
        private readonly MerchantApiHttpClient $http,
        private readonly GoogleApiClient $googleApiClient,
        private readonly FeedConfigRepository $feedConfigRepository,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    public function buildShippingPreview(int $shopId): array
    {
        $feeds = $this->feedConfigRepository->findActive($shopId);
        if (empty($feeds)) {
            return ['services' => [], 'summary' => ['carriers' => 0, 'countries' => 0, 'skipped' => []]];
        }

        $currency = $feeds[0]['currency_code'] ?? 'PLN';
        $carriers = $this->loadActiveCarriers($shopId);

        $services = [];
        $skipped = [];
        $allCountries = [];

        foreach ($carriers as $carrier) {
            if ((int) ($carrier['is_module'] ?? 0) === 1) {
                $skipped[] = [
                    'name' => $carrier['name'],
                    'reason' => $this->translator->trans('Module carrier (dynamic pricing)', [], 'Modules.Feedforge.Admin'),
                ];
                continue;
            }

            $mapped = $this->mapCarrierToServices($carrier, $shopId, $currency);
            foreach ($mapped as $svc) {
                $services[] = $svc;
                $allCountries[$svc['deliveryCountry']] = true;
            }
        }

        return [
            'services' => $services,
            'summary' => [
                'carriers' => count($carriers),
                'countries' => count($allCountries),
                'skipped' => $skipped,
            ],
        ];
    }

    public function pushToGoogle(int $shopId): array
    {
        $preview = $this->buildShippingPreview($shopId);

        if (empty($preview['services'])) {
            return [
                'success' => false,
                'message' => $this->translator->trans('No services to send', [], 'Modules.Feedforge.Admin'),
                'servicesCount' => 0,
            ];
        }

        try {
            $merchantId = $this->googleApiClient->getMerchantId($shopId);
            $body = $this->buildShippingSettingsBody($merchantId, $preview['services']);

            $this->http->post(
                $shopId,
                sprintf('/accounts/v1/accounts/%s/shippingSettings:insert', $merchantId),
                $body
            );

            return [
                'success' => true,
                'message' => $this->translator->trans('Shipping settings updated in Google Merchant Center', [], 'Modules.Feedforge.Admin'),
                'servicesCount' => count($preview['services']),
            ];
        } catch (MerchantApiException $e) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Google API error: %error%', ['%error%' => $e->getMessage()], 'Modules.Feedforge.Admin'),
                'servicesCount' => 0,
            ];
        }
    }

    public function getFromGoogle(int $shopId): ?array
    {
        try {
            $merchantId = $this->googleApiClient->getMerchantId($shopId);
            $settings = $this->http->get(
                $shopId,
                sprintf('/accounts/v1/accounts/%s/shippingSettings', $merchantId)
            );

            return $this->shippingSettingsToArray($settings);
        } catch (MerchantApiException $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PS-side carrier loading (unchanged from v1.x)
    // ─────────────────────────────────────────────────────────────────────────

    private function loadActiveCarriers(int $shopId): array
    {
        $prefix = _DB_PREFIX_;
        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');

        $sql = "
            SELECT c.id_carrier, c.name, c.is_free, c.is_module,
                   c.shipping_method, c.range_behavior,
                   COALESCE(cl.delay, '') AS delay
            FROM {$prefix}carrier c
            LEFT JOIN {$prefix}carrier_lang cl
                ON cl.id_carrier = c.id_carrier AND cl.id_lang = {$langId}
            WHERE c.active = 1
              AND c.deleted = 0
            ORDER BY c.position ASC
        ";

        return $this->connection->fetchAllAssociative($sql);
    }

    private function mapCarrierToServices(array $carrier, int $shopId, string $currency): array
    {
        $carrierId = (int) $carrier['id_carrier'];
        $isFree = (int) ($carrier['is_free'] ?? 0) === 1;

        $zones = $this->getCarrierZones($carrierId);
        if (empty($zones)) {
            return [];
        }

        $zoneIds = array_column($zones, 'id_zone');
        $countries = $this->getCountriesFromZones($zoneIds);
        if (empty($countries)) {
            return [];
        }

        $rateGroups = [];
        if (!$isFree) {
            $rateGroups = $this->getCarrierRateGroups($carrierId, $zoneIds, $currency);
        }

        $services = [];
        foreach ($countries as $isoCode) {
            $services[] = [
                'name' => $carrier['name'],
                'deliveryCountry' => $isoCode,
                'currency' => $currency,
                'isFree' => $isFree,
                'deliveryTimeLabel' => $carrier['delay'] ?? '',
                'rateGroups' => $rateGroups,
            ];
        }

        return $services;
    }

    private function getCarrierZones(int $carrierId): array
    {
        $prefix = _DB_PREFIX_;

        $sql = "
            SELECT cz.id_zone, z.name AS zone_name
            FROM {$prefix}carrier_zone cz
            JOIN {$prefix}zone z ON z.id_zone = cz.id_zone AND z.active = 1
            WHERE cz.id_carrier = :carrierId
        ";

        return $this->connection->fetchAllAssociative($sql, ['carrierId' => $carrierId]);
    }

    private function getCountriesFromZones(array $zoneIds): array
    {
        if (empty($zoneIds)) {
            return [];
        }

        $prefix = _DB_PREFIX_;
        $placeholders = implode(',', array_map('intval', $zoneIds));

        $sql = "
            SELECT DISTINCT c.iso_code
            FROM {$prefix}country c
            WHERE c.id_zone IN ({$placeholders})
              AND c.active = 1
        ";

        return array_column($this->connection->fetchAllAssociative($sql), 'iso_code');
    }

    private function getCarrierRateGroups(int $carrierId, array $zoneIds, string $currency): array
    {
        $prefix = _DB_PREFIX_;
        $zonePlaceholders = implode(',', array_map('intval', $zoneIds));
        $groups = [];

        // Weight-based rates
        $weightRanges = $this->connection->fetchAllAssociative("
            SELECT rw.id_range_weight, rw.delimiter1, rw.delimiter2
            FROM {$prefix}range_weight rw
            WHERE rw.id_carrier = :carrierId
            ORDER BY rw.delimiter1 ASC
        ", ['carrierId' => $carrierId]);

        if (!empty($weightRanges)) {
            $rangeIds = array_column($weightRanges, 'id_range_weight');
            $rangePlaceholders = implode(',', array_map('intval', $rangeIds));

            $deliveryRows = $this->connection->fetchAllAssociative("
                SELECT d.id_range_weight, d.id_zone, d.price
                FROM {$prefix}delivery d
                WHERE d.id_carrier = :carrierId
                  AND d.id_range_weight IN ({$rangePlaceholders})
                  AND d.id_zone IN ({$zonePlaceholders})
            ", ['carrierId' => $carrierId]);

            $priceMap = [];
            foreach ($deliveryRows as $dr) {
                $priceMap[$dr['id_range_weight']][] = (float) $dr['price'];
            }

            $rows = [];
            foreach ($weightRanges as $range) {
                $rangeId = $range['id_range_weight'];
                $prices = $priceMap[$rangeId] ?? [];
                $maxPrice = !empty($prices) ? max($prices) : 0;

                $rows[] = [
                    'minWeight' => (float) $range['delimiter1'],
                    'maxWeight' => (float) $range['delimiter2'],
                    'price' => round($maxPrice, 2),
                    'currency' => $currency,
                ];
            }

            if (!empty($rows)) {
                $groups[] = [
                    'name' => $this->translator->trans('Weight', [], 'Modules.Feedforge.Admin'),
                    'type' => 'weight',
                    'rows' => $rows,
                ];
            }
        }

        // Price-based rates
        $priceRanges = $this->connection->fetchAllAssociative("
            SELECT rp.id_range_price, rp.delimiter1, rp.delimiter2
            FROM {$prefix}range_price rp
            WHERE rp.id_carrier = :carrierId
            ORDER BY rp.delimiter1 ASC
        ", ['carrierId' => $carrierId]);

        if (!empty($priceRanges)) {
            $rangeIds = array_column($priceRanges, 'id_range_price');
            $rangePlaceholders = implode(',', array_map('intval', $rangeIds));

            $deliveryRows = $this->connection->fetchAllAssociative("
                SELECT d.id_range_price, d.id_zone, d.price
                FROM {$prefix}delivery d
                WHERE d.id_carrier = :carrierId
                  AND d.id_range_price IN ({$rangePlaceholders})
                  AND d.id_zone IN ({$zonePlaceholders})
            ", ['carrierId' => $carrierId]);

            $priceMap = [];
            foreach ($deliveryRows as $dr) {
                $priceMap[$dr['id_range_price']][] = (float) $dr['price'];
            }

            $rows = [];
            foreach ($priceRanges as $range) {
                $rangeId = $range['id_range_price'];
                $prices = $priceMap[$rangeId] ?? [];
                $maxPrice = !empty($prices) ? max($prices) : 0;

                $rows[] = [
                    'minOrderPrice' => (float) $range['delimiter1'],
                    'maxOrderPrice' => (float) $range['delimiter2'],
                    'price' => round($maxPrice, 2),
                    'currency' => $currency,
                ];
            }

            if (!empty($rows)) {
                $groups[] = [
                    'name' => $this->translator->trans('Order price', [], 'Modules.Feedforge.Admin'),
                    'type' => 'price',
                    'rows' => $rows,
                ];
            }
        }

        return $groups;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversion: preview array → Merchant API ShippingSettings JSON
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildShippingSettingsBody(string $merchantId, array $services): array
    {
        $googleServices = [];

        foreach ($services as $svc) {
            $gService = [
                'serviceName' => (string) $svc['name'],
                'active' => true,
                'deliveryCountries' => [(string) $svc['deliveryCountry']],
                'currencyCode' => (string) $svc['currency'],
            ];

            if (!empty($svc['isFree'])) {
                $gService['rateGroups'] = [
                    [
                        'name' => $this->translator->trans('Free shipping', [], 'Modules.Feedforge.Admin'),
                        'singleValue' => [
                            'flatRate' => $this->buildPrice(0.0, (string) $svc['currency']),
                        ],
                    ],
                ];
            } elseif (!empty($svc['rateGroups'])) {
                $rateGroups = [];
                foreach ($svc['rateGroups'] as $group) {
                    $rateGroups[] = $this->buildRateGroup($group, (string) $svc['currency']);
                }
                $gService['rateGroups'] = $rateGroups;
            }

            $googleServices[] = $gService;
        }

        return [
            'name' => sprintf('accounts/%s/shippingSettings', $merchantId),
            'services' => $googleServices,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRateGroup(array $group, string $currency): array
    {
        $tableRows = [];
        $rowHeaders = [];

        foreach ($group['rows'] as $row) {
            if ($group['type'] === 'weight') {
                $rowHeaders[] = $this->buildWeight((float) $row['minWeight']);
            }

            $tableRows[] = [
                'cells' => [
                    ['flatRate' => $this->buildPrice((float) $row['price'], $currency)],
                ],
            ];
        }

        $headers = [];
        if ($group['type'] === 'weight' && !empty($rowHeaders)) {
            $headers['weights'] = $rowHeaders;
        }

        return [
            'name' => (string) $group['name'],
            'mainTable' => [
                'name' => (string) $group['name'],
                'rowHeaders' => $headers,
                'rows' => $tableRows,
            ],
        ];
    }

    /**
     * @return array{amountMicros: string, currencyCode: string}
     */
    private function buildPrice(float $value, string $currency): array
    {
        $micros = bcmul((string) $value, '1000000', 0);

        return [
            'amountMicros' => $micros,
            'currencyCode' => $currency,
        ];
    }

    /**
     * @return array{amountMicros: string, unit: string}
     */
    private function buildWeight(float $kg): array
    {
        $micros = bcmul((string) $kg, '1000000', 0);

        return [
            'amountMicros' => $micros,
            'unit' => 'KILOGRAM',
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function shippingSettingsToArray(array $settings): array
    {
        $result = ['services' => []];

        foreach ($settings['services'] ?? [] as $svc) {
            $deliveryCountries = [];
            foreach ($svc['deliveryCountries'] ?? [] as $cc) {
                $deliveryCountries[] = (string) $cc;
            }

            $entry = [
                'name' => (string) ($svc['serviceName'] ?? ''),
                'active' => (bool) ($svc['active'] ?? false),
                'deliveryCountry' => $deliveryCountries[0] ?? '',
                'currency' => (string) ($svc['currencyCode'] ?? ''),
                'deliveryTimeLabel' => '',
                'rateGroups' => [],
            ];

            foreach ($svc['rateGroups'] ?? [] as $rg) {
                $groupEntry = [
                    'name' => (string) ($rg['name'] ?? ''),
                    'singleValue' => null,
                    'rows' => [],
                ];

                $sv = $rg['singleValue'] ?? null;
                if (is_array($sv) && isset($sv['flatRate'])) {
                    $groupEntry['singleValue'] = $this->priceToArray($sv['flatRate']);
                }

                $mt = $rg['mainTable'] ?? null;
                if (is_array($mt)) {
                    foreach ($mt['rows'] ?? [] as $row) {
                        foreach ($row['cells'] ?? [] as $cell) {
                            if (isset($cell['flatRate'])) {
                                $groupEntry['rows'][] = $this->priceToArray($cell['flatRate']);
                            }
                        }
                    }
                }

                $entry['rateGroups'][] = $groupEntry;
            }

            $result['services'][] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $price
     */
    private function priceToArray(array $price): array
    {
        $micros = (string) ($price['amountMicros'] ?? '0');
        $value = bcdiv($micros, '1000000', 2);

        return [
            'value' => $value,
            'currency' => (string) ($price['currencyCode'] ?? ''),
        ];
    }
}
