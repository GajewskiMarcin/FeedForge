<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\RuleRepository;

/**
 * Evaluates rules (exclusions, pricing, identifiers, custom labels) against product data.
 * Rules are stored as JSON conditions/actions in the database.
 */
class RuleEngine
{
    public function __construct(
        private readonly RuleRepository $ruleRepository,
    ) {
    }

    /**
     * Check if a product is excluded by any exclusion rule.
     */
    public function isExcluded(int $shopId, array $context): bool
    {
        $rules = $this->ruleRepository->findByShop($shopId, 'exclusion');

        foreach ($rules as $rule) {
            if (!(int) ($rule['active'] ?? 1)) {
                continue;
            }

            $conditions = $this->decodeJson($rule['conditions'] ?? '[]');
            if ($this->evaluateConditions($conditions, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply pricing rules to mapped product data.
     */
    public function applyPricingRules(int $shopId, array $mapped, array $context): array
    {
        $rules = $this->ruleRepository->findByShop($shopId, 'pricing');

        foreach ($rules as $rule) {
            if (!(int) ($rule['active'] ?? 1)) {
                continue;
            }

            $conditions = $this->decodeJson($rule['conditions'] ?? '[]');
            if (!$this->evaluateConditions($conditions, $context)) {
                continue;
            }

            $actions = $this->decodeJson($rule['actions'] ?? '{}');
            $mapped = $this->executePricingActions($mapped, $actions);
        }

        return $mapped;
    }

    /**
     * Apply custom label rules to mapped product data.
     */
    public function applyCustomLabelRules(int $shopId, array $mapped, array $context): array
    {
        $rules = $this->ruleRepository->findByShop($shopId, 'custom_label');

        foreach ($rules as $rule) {
            if (!(int) ($rule['active'] ?? 1)) {
                continue;
            }

            $conditions = $this->decodeJson($rule['conditions'] ?? '[]');
            if (!$this->evaluateConditions($conditions, $context)) {
                continue;
            }

            $actions = $this->decodeJson($rule['actions'] ?? '{}');
            $mapped = $this->executeCustomLabelActions($mapped, $actions);
        }

        return $mapped;
    }

    /**
     * Apply identifier rules (fallback GTIN/MPN logic).
     */
    public function applyIdentifierRules(int $shopId, array $mapped, array $context): array
    {
        $rules = $this->ruleRepository->findByShop($shopId, 'identifier');

        foreach ($rules as $rule) {
            if (!(int) ($rule['active'] ?? 1)) {
                continue;
            }

            $conditions = $this->decodeJson($rule['conditions'] ?? '[]');
            if (!$this->evaluateConditions($conditions, $context)) {
                continue;
            }

            $actions = $this->decodeJson($rule['actions'] ?? '{}');
            $mapped = $this->executeIdentifierActions($mapped, $actions, $context);
        }

        return $mapped;
    }

    /**
     * Evaluate a set of conditions against a context.
     * All conditions must match (AND logic).
     *
     * Condition format: [{"field": "price", "operator": "gt", "value": 100}, ...]
     */
    private function evaluateConditions(array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return true; // No conditions = always match
        }

        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single condition.
     */
    private function evaluateCondition(array $condition, array $context): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'eq';
        $expected = $condition['value'] ?? '';

        $actual = $context[$field] ?? null;

        if ($actual === null) {
            return $operator === 'empty' || $operator === 'not_exists';
        }

        return match ($operator) {
            'eq' => (string) $actual === (string) $expected,
            'neq', 'ne' => (string) $actual !== (string) $expected,
            'gt' => (float) $actual > (float) $expected,
            'gte', 'ge' => (float) $actual >= (float) $expected,
            'lt' => (float) $actual < (float) $expected,
            'lte', 'le' => (float) $actual <= (float) $expected,
            'contains' => str_contains(strtolower((string) $actual), strtolower((string) $expected)),
            'not_contains' => !str_contains(strtolower((string) $actual), strtolower((string) $expected)),
            'starts_with' => str_starts_with(strtolower((string) $actual), strtolower((string) $expected)),
            'ends_with' => str_ends_with(strtolower((string) $actual), strtolower((string) $expected)),
            'in' => in_array((string) $actual, array_map('trim', explode(',', (string) $expected)), true),
            'not_in' => !in_array((string) $actual, array_map('trim', explode(',', (string) $expected)), true),
            'empty' => empty($actual),
            'not_empty' => !empty($actual),
            'regex' => (bool) @preg_match('/' . $expected . '/i', (string) $actual),
            default => false,
        };
    }

    /**
     * Execute pricing actions on mapped data.
     *
     * Actions: {"action": "min_price"|"round"|"markup"|"fixed", "value": ...}
     */
    private function executePricingActions(array $mapped, array $actions): array
    {
        $action = $actions['action'] ?? '';
        $value = (float) ($actions['value'] ?? 0);

        if (!isset($mapped['price']['value'])) {
            return $mapped;
        }

        $price = (float) $mapped['price']['value'];

        switch ($action) {
            case 'min_price':
                if ($price < $value) {
                    $mapped['price']['value'] = number_format($value, 2, '.', '');
                }
                break;

            case 'markup_percent':
                $mapped['price']['value'] = number_format($price * (1 + $value / 100), 2, '.', '');
                break;

            case 'markup_fixed':
                $mapped['price']['value'] = number_format($price + $value, 2, '.', '');
                break;

            case 'round_up':
                $mapped['price']['value'] = number_format(ceil($price * 100) / 100, 2, '.', '');
                break;

            case 'round_99':
                $mapped['price']['value'] = number_format(floor($price) + 0.99, 2, '.', '');
                break;
        }

        return $mapped;
    }

    /**
     * Execute custom label actions.
     *
     * Actions: {"label_index": 0-4, "value": "bestseller"}
     */
    private function executeCustomLabelActions(array $mapped, array $actions): array
    {
        $labelIndex = (int) ($actions['label_index'] ?? 0);
        $value = (string) ($actions['value'] ?? '');

        if ($labelIndex < 0 || $labelIndex > 4 || $value === '') {
            return $mapped;
        }

        $mapped["customLabel{$labelIndex}"] = mb_substr($value, 0, 100); // GMC limit

        return $mapped;
    }

    /**
     * Execute identifier actions (fallback GTIN/MPN).
     *
     * Actions: {"field": "gtin"|"mpn"|"brand", "source": "field_name"|"fixed", "value": "..."}
     */
    private function executeIdentifierActions(array $mapped, array $actions, array $context): array
    {
        $field = $actions['field'] ?? '';
        $source = $actions['source'] ?? 'fixed';
        $value = $actions['value'] ?? '';

        if ($source === 'context') {
            $value = (string) ($context[$value] ?? '');
        }

        if (empty($value)) {
            return $mapped;
        }

        switch ($field) {
            case 'gtin':
                if (empty($mapped['gtin'])) {
                    $mapped['gtin'] = $value;
                }
                break;
            case 'mpn':
                if (empty($mapped['mpn'])) {
                    $mapped['mpn'] = $value;
                }
                break;
            case 'brand':
                if (empty($mapped['brand'])) {
                    $mapped['brand'] = $value;
                }
                break;
            case 'identifier_exists':
                $mapped['identifierExists'] = (bool) $value;
                break;
        }

        return $mapped;
    }

    /**
     * Decode JSON safely, returning empty array on failure.
     */
    private function decodeJson(string $json): array
    {
        if (empty($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
