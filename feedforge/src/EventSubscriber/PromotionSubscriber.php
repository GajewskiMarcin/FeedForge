<?php

declare(strict_types=1);

/**
 * Feed Forge - Google Merchant Center integration for PrestaShop
 *
 * @author    Feed Forge
 * @copyright Feed Forge
 * @license   MIT
 */

namespace FeedForge\EventSubscriber;

use FeedForge\Repository\PromotionRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Listens to PrestaShop cart_rule hooks and updates promotion status.
 *
 * Important: hooks do NOT call Google API directly.
 * They only update the local promotion status to 'pending' so the
 * user knows a re-sync is needed.
 */
class PromotionSubscriber
{
    public function __construct(
        private readonly PromotionRepository $promotionRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Called when a cart rule is created or updated.
     * Hooks: actionObjectCartRuleAddAfter, actionObjectCartRuleUpdateAfter
     */
    public function onCartRuleChange(array $params): void
    {
        $cartRuleId = $this->extractCartRuleId($params);
        if ($cartRuleId === null) {
            return;
        }

        $shopId = $this->getShopId();
        $existing = $this->promotionRepository->findByCartRule($cartRuleId, $shopId);

        if ($existing) {
            // Mark as pending so user knows to re-sync
            $this->promotionRepository->updateStatus(
                (int) $existing['id_feedforge_promotion'],
                'pending'
            );
        }
    }

    /**
     * Called when a cart rule is deleted.
     * Hook: actionObjectCartRuleDeleteAfter
     */
    public function onCartRuleDelete(array $params): void
    {
        $cartRuleId = $this->extractCartRuleId($params);
        if ($cartRuleId === null) {
            return;
        }

        $shopId = $this->getShopId();
        $existing = $this->promotionRepository->findByCartRule($cartRuleId, $shopId);

        if ($existing) {
            // Mark as ended - Google promotions can't be deleted, they expire naturally
            $this->promotionRepository->updateStatus(
                (int) $existing['id_feedforge_promotion'],
                'ended',
                null,
                $this->translator->trans('Cart rule deleted in PrestaShop', [], 'Modules.Feedforge.Admin')
            );
        }
    }

    /**
     * Extract cart_rule ID from hook parameters.
     */
    private function extractCartRuleId(array $params): ?int
    {
        $object = $params['object'] ?? null;
        if ($object && isset($object->id)) {
            return (int) $object->id > 0 ? (int) $object->id : null;
        }

        return null;
    }

    /**
     * Get current shop ID from context.
     */
    private function getShopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }
}
