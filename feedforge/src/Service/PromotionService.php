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
use FeedForge\Repository\PromotionRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps PrestaShop cart_rule entries to Google Merchant Center promotions.
 *
 * v2.0.1: Rewritten on top of MerchantApiHttpClient (raw REST/JSON).
 *
 * Endpoints used:
 * - POST /promotions/v1/{parent}/promotions:insert  — upsert a promotion (requires PromotionDataSource)
 * - GET  /promotions/v1/{parent}/promotions          — list promotions (with embedded status)
 */
class PromotionService
{
    public function __construct(
        private readonly MerchantApiHttpClient $http,
        private readonly GoogleApiClient $googleApiClient,
        private readonly DataSourceService $dataSourceService,
        private readonly PromotionRepository $promotionRepository,
        private readonly FeedConfigRepository $feedConfigRepository,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PS-side: list, scan cart rules, save mappings (unchanged from v1.x)
    // ─────────────────────────────────────────────────────────────────────────

    public function getPromotions(int $shopId): array
    {
        return $this->promotionRepository->findByShop($shopId);
    }

    public function scanCartRules(int $shopId, bool $includeAlreadyMapped = false): array
    {
        $prefix = _DB_PREFIX_;

        $sql = "
            SELECT cr.id_cart_rule, cr.name, cr.code, cr.description,
                   cr.reduction_percent, cr.reduction_amount, cr.reduction_currency,
                   cr.free_shipping, cr.minimum_amount, cr.minimum_amount_currency,
                   cr.date_from, cr.date_to, cr.active,
                   cr.product_restriction, cr.quantity
            FROM {$prefix}cart_rule cr
            WHERE cr.active = 1
              AND (cr.date_to >= NOW() OR cr.date_to = '0000-00-00 00:00:00')
            ORDER BY cr.date_from DESC
        ";

        $cartRules = $this->connection->fetchAllAssociative($sql);
        $result = [];

        foreach ($cartRules as $rule) {
            $cartRuleId = (int) $rule['id_cart_rule'];

            if (!$includeAlreadyMapped) {
                $existing = $this->promotionRepository->findByCartRule($cartRuleId, $shopId);
                if ($existing) {
                    continue;
                }
            }

            $valueType = $this->detectValueType($rule);
            if ($valueType === null) {
                continue;
            }

            $result[] = [
                'id_cart_rule' => $cartRuleId,
                'name' => $rule['name'],
                'code' => $rule['code'] ?? '',
                'description' => $rule['description'] ?? '',
                'reduction_percent' => (float) ($rule['reduction_percent'] ?? 0),
                'reduction_amount' => (float) ($rule['reduction_amount'] ?? 0),
                'reduction_currency' => (string) ($rule['reduction_currency'] ?? ''),
                'free_shipping' => (bool) ($rule['free_shipping'] ?? false),
                'minimum_amount' => (float) ($rule['minimum_amount'] ?? 0),
                'minimum_amount_currency' => (string) ($rule['minimum_amount_currency'] ?? ''),
                'date_from' => $rule['date_from'] ?? null,
                'date_to' => $rule['date_to'] ?? null,
                'has_product_restriction' => (int) ($rule['product_restriction'] ?? 0) > 0,
                'suggested_value_type' => $valueType,
                'already_mapped' => $includeAlreadyMapped
                    ? ($this->promotionRepository->findByCartRule($cartRuleId, $shopId) !== null)
                    : false,
            ];
        }

        return $result;
    }

    public function saveMapping(int $shopId, array $data): int
    {
        $cartRuleId = (int) ($data['id_cart_rule'] ?? 0);
        if ($cartRuleId <= 0) {
            throw new \InvalidArgumentException(
                $this->translator->trans('id_cart_rule is required', [], 'Modules.Feedforge.Admin')
            );
        }

        $feeds = $this->feedConfigRepository->findActive($shopId);
        if (empty($feeds)) {
            throw new \RuntimeException(
                $this->translator->trans('No active feeds - configure a feed in Configuration', [], 'Modules.Feedforge.Admin')
            );
        }

        $feed = $feeds[0];

        $prefix = _DB_PREFIX_;
        $cartRule = $this->connection->fetchAssociative(
            "SELECT * FROM {$prefix}cart_rule WHERE id_cart_rule = :id",
            ['id' => $cartRuleId]
        );

        if (!$cartRule) {
            throw new \RuntimeException(
                $this->translator->trans('Cart rule does not exist', [], 'Modules.Feedforge.Admin')
            );
        }

        $mapped = $this->mapCartRuleToPromotion($cartRule, $feed, $data);
        $mapped['id_shop'] = $shopId;

        $existing = $this->promotionRepository->findByCartRule($cartRuleId, $shopId);
        if ($existing) {
            $mapped['id_feedforge_promotion'] = $existing['id_feedforge_promotion'];
        }

        return $this->promotionRepository->save($mapped);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Google Merchant API integration (raw REST)
    // ─────────────────────────────────────────────────────────────────────────

    public function syncPromotion(int $shopId, int $promotionId): array
    {
        $promo = $this->promotionRepository->findById($promotionId, $shopId);
        if (!$promo) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Promotion not found', [], 'Modules.Feedforge.Admin'),
            ];
        }

        try {
            $merchantId = $this->googleApiClient->getMerchantId($shopId);
            $dataSourceName = $this->dataSourceService->ensurePromotionDataSourceForShop(
                $shopId,
                $promo['target_country'],
                $promo['content_language']
            );

            $body = $this->buildPromotionBody($promo);

            $response = $this->http->post(
                $shopId,
                sprintf('/promotions/v1/accounts/%s/promotions:insert', $merchantId),
                $body,
                ['dataSource' => $dataSourceName]
            );

            $googleId = (string) ($response['promotionId'] ?? $this->extractIdFromName((string) ($response['name'] ?? '')));
            $this->promotionRepository->updateStatus($promotionId, 'pending', $googleId);

            return [
                'success' => true,
                'message' => $this->translator->trans('Promotion sent to Google', [], 'Modules.Feedforge.Admin'),
                'googleId' => $googleId,
            ];
        } catch (MerchantApiException $e) {
            $errorMsg = $e->getMessage();
            $this->promotionRepository->updateStatus($promotionId, 'rejected', null, $errorMsg);

            return [
                'success' => false,
                'message' => $this->translator->trans('Google API error: %error%', ['%error%' => $errorMsg], 'Modules.Feedforge.Admin'),
            ];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->promotionRepository->updateStatus($promotionId, 'rejected', null, $errorMsg);

            return [
                'success' => false,
                'message' => $this->translator->trans('Google API error: %error%', ['%error%' => $errorMsg], 'Modules.Feedforge.Admin'),
            ];
        }
    }

    public function syncAllPromotions(int $shopId): array
    {
        $promotions = $this->promotionRepository->findActive($shopId);

        $results = ['total' => count($promotions), 'success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($promotions as $promo) {
            $result = $this->syncPromotion($shopId, (int) $promo['id_feedforge_promotion']);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $promo['id_feedforge_promotion'],
                    'title' => $promo['promotion_title'],
                    'error' => $result['message'],
                ];
            }
        }

        return $results;
    }

    public function refreshStatuses(int $shopId): array
    {
        try {
            $merchantId = $this->googleApiClient->getMerchantId($shopId);

            $googlePromos = [];
            foreach ($this->http->listAllPages(
                $shopId,
                sprintf('/promotions/v1/accounts/%s/promotions', $merchantId),
                'promotions'
            ) as $gp) {
                $promoId = (string) ($gp['promotionId'] ?? '');
                if ($promoId === '') {
                    continue;
                }
                $googlePromos[$promoId] = $this->mapDestinationState($gp['promotionStatus'] ?? null);
            }

            $promotions = $this->promotionRepository->findByShop($shopId);
            $updated = 0;
            foreach ($promotions as $promo) {
                $gId = (string) ($promo['google_promotion_id'] ?? '');
                if ($gId !== '' && isset($googlePromos[$gId])) {
                    $this->promotionRepository->updateStatus(
                        (int) $promo['id_feedforge_promotion'],
                        $googlePromos[$gId],
                        $gId
                    );
                    $updated++;
                }
            }

            return [
                'success' => true,
                'message' => $this->translator->trans('Updated statuses of %count% promotions', ['%count%' => $updated], 'Modules.Feedforge.Admin'),
                'updated' => $updated,
            ];
        } catch (MerchantApiException $e) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Google API error: %error%', ['%error%' => $e->getMessage()], 'Modules.Feedforge.Admin'),
                'updated' => 0,
            ];
        }
    }

    public function deletePromotion(int $shopId, int $promotionId): array
    {
        $promo = $this->promotionRepository->findById($promotionId, $shopId);
        if (!$promo) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Promotion not found', [], 'Modules.Feedforge.Admin'),
            ];
        }

        $this->promotionRepository->delete($promotionId, $shopId);

        return [
            'success' => true,
            'message' => $this->translator->trans('Promotion mapping deleted', [], 'Modules.Feedforge.Admin'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function detectValueType(array $cartRule): ?string
    {
        $reductionPercent = (float) ($cartRule['reduction_percent'] ?? 0);
        $reductionAmount = (float) ($cartRule['reduction_amount'] ?? 0);
        $freeShipping = (bool) ($cartRule['free_shipping'] ?? false);

        if ($reductionPercent > 0) {
            return 'PERCENT_OFF';
        }
        if ($reductionAmount > 0) {
            return 'MONEY_OFF';
        }
        if ($freeShipping) {
            return 'FREE_SHIPPING';
        }

        return null;
    }

    private function mapCartRuleToPromotion(array $cartRule, array $feedConfig, array $overrides = []): array
    {
        $valueType = $overrides['coupon_value_type'] ?? $this->detectValueType($cartRule) ?? 'PERCENT_OFF';

        $discountValue = 0.0;
        $discountCurrency = null;

        if ($valueType === 'PERCENT_OFF') {
            $discountValue = (float) ($cartRule['reduction_percent'] ?? 0);
        } elseif ($valueType === 'MONEY_OFF') {
            $discountValue = (float) ($cartRule['reduction_amount'] ?? 0);
            $discountCurrency = $cartRule['reduction_currency']
                ?? $feedConfig['currency_code']
                ?? 'PLN';
        }

        $code = trim((string) ($cartRule['code'] ?? ''));
        $offerType = $code !== '' ? 'GENERIC_CODE' : 'NO_CODE';

        $title = $overrides['promotion_title']
            ?? $cartRule['description']
            ?? $cartRule['name']
            ?? $this->translator->trans('Promotion', [], 'Modules.Feedforge.Admin');

        $dateFrom = $cartRule['date_from'] ?? null;
        $dateTo = $cartRule['date_to'] ?? null;
        if ($dateFrom === '0000-00-00 00:00:00') {
            $dateFrom = null;
        }
        if ($dateTo === '0000-00-00 00:00:00') {
            $dateTo = null;
        }

        $minimumAmount = (float) ($cartRule['minimum_amount'] ?? 0);
        $minimumCurrency = $cartRule['minimum_amount_currency'] ?? null;
        if ($minimumCurrency === '0' || empty($minimumCurrency)) {
            $minimumCurrency = $feedConfig['currency_code'] ?? 'PLN';
        }

        return [
            'id_cart_rule' => (int) $cartRule['id_cart_rule'],
            'promotion_title' => $title,
            'coupon_value_type' => $valueType,
            'discount_value' => $discountValue,
            'discount_currency' => $discountCurrency,
            'target_country' => $overrides['target_country'] ?? $feedConfig['country_code'] ?? 'PL',
            'content_language' => $overrides['content_language'] ?? $feedConfig['language_code'] ?? 'pl',
            'redemption_channel' => 'ONLINE',
            'product_applicability' => (int) ($cartRule['product_restriction'] ?? 0) > 0
                ? 'SPECIFIC_PRODUCTS'
                : 'ALL_PRODUCTS',
            'offer_type' => $offerType,
            'coupon_code' => $code !== '' ? $code : null,
            'effective_dates_start' => $dateFrom,
            'effective_dates_end' => $dateTo,
            'minimum_purchase_amount' => $minimumAmount > 0 ? $minimumAmount : null,
            'minimum_purchase_currency' => $minimumAmount > 0 ? $minimumCurrency : null,
            'gmc_status' => 'pending',
            'active' => 1,
        ];
    }

    /**
     * Build the Promotion request body for the insert endpoint.
     *
     * @return array<string, mixed>
     */
    private function buildPromotionBody(array $promo): array
    {
        $promoId = sprintf('ff_%d_%d', (int) $promo['id_feedforge_promotion'], (int) $promo['id_cart_rule']);

        return [
            'promotionId' => $promoId,
            'contentLanguage' => strtolower((string) $promo['content_language']),
            'targetCountry' => strtoupper((string) $promo['target_country']),
            'redemptionChannel' => [$this->mapRedemptionChannel((string) $promo['redemption_channel'])],
            'attributes' => $this->buildPromotionAttributes($promo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPromotionAttributes(array $promo): array
    {
        $attrs = [
            'longTitle' => (string) $promo['promotion_title'],
            'couponValueType' => $this->mapCouponValueType((string) $promo['coupon_value_type']),
            'productApplicability' => (string) $promo['product_applicability'],
            'offerType' => (string) $promo['offer_type'],
        ];

        $valueType = (string) $promo['coupon_value_type'];
        if ($valueType === 'PERCENT_OFF') {
            $attrs['percentOff'] = (int) $promo['discount_value'];
        } elseif ($valueType === 'MONEY_OFF') {
            $attrs['moneyOffAmount'] = $this->buildPrice(
                (float) $promo['discount_value'],
                (string) ($promo['discount_currency'] ?? 'PLN')
            );
        }

        if (!empty($promo['coupon_code'])) {
            $attrs['genericRedemptionCode'] = (string) $promo['coupon_code'];
        }

        if (!empty($promo['effective_dates_start']) && !empty($promo['effective_dates_end'])) {
            $startTs = strtotime((string) $promo['effective_dates_start']);
            $endTs = strtotime((string) $promo['effective_dates_end']);

            if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
                // Google\Type\Interval JSON format: {"startTime": "...", "endTime": "..."}
                // where times are RFC 3339 strings.
                $attrs['promotionEffectiveTimePeriod'] = [
                    'startTime' => gmdate('Y-m-d\TH:i:s\Z', $startTs),
                    'endTime' => gmdate('Y-m-d\TH:i:s\Z', $endTs),
                ];
            }
        }

        if (isset($promo['minimum_purchase_amount']) && (float) $promo['minimum_purchase_amount'] > 0) {
            $attrs['minimumPurchaseAmount'] = $this->buildPrice(
                (float) $promo['minimum_purchase_amount'],
                (string) ($promo['minimum_purchase_currency'] ?? 'PLN')
            );
        }

        return $attrs;
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

    private function mapCouponValueType(string $type): string
    {
        return match ($type) {
            'PERCENT_OFF' => 'PERCENT_OFF',
            'MONEY_OFF' => 'MONEY_OFF',
            'FREE_SHIPPING', 'FREE_SHIPPING_STANDARD' => 'FREE_SHIPPING_STANDARD',
            'BUY_M_GET_N' => 'BUY_M_GET_N_PERCENT_OFF',
            'FREE_GIFT' => 'FREE_GIFT',
            default => 'COUPON_VALUE_TYPE_UNSPECIFIED',
        };
    }

    private function mapRedemptionChannel(string $channel): string
    {
        return match ($channel) {
            'ONLINE' => 'ONLINE',
            'IN_STORE' => 'IN_STORE',
            default => 'REDEMPTION_CHANNEL_UNSPECIFIED',
        };
    }

    /**
     * @param array<string, mixed>|null $status
     */
    private function mapDestinationState(?array $status): string
    {
        if ($status === null) {
            return 'unknown';
        }

        $hasActive = false;
        $hasPending = false;
        $hasRejected = false;

        foreach ($status['destinationStatuses'] ?? [] as $destStatus) {
            $state = (string) ($destStatus['status'] ?? '');
            if ($state === 'LIVE') {
                $hasActive = true;
            } elseif ($state === 'IN_REVIEW' || $state === 'PENDING') {
                $hasPending = true;
            } elseif ($state === 'REJECTED' || $state === 'STOPPED' || $state === 'EXPIRED') {
                $hasRejected = true;
            }
        }

        if ($hasRejected && !$hasActive) {
            return 'rejected';
        }
        if ($hasPending) {
            return 'pending';
        }
        if ($hasActive) {
            return 'active';
        }

        return 'unknown';
    }

    private function extractIdFromName(string $name): string
    {
        if (preg_match('#/promotions/(.+)$#', $name, $m)) {
            return $m[1];
        }

        return $name;
    }
}
