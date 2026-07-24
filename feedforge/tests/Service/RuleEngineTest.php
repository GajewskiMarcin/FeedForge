<?php

declare(strict_types=1);

namespace FeedForge\Tests\Service;

use FeedForge\Repository\RuleRepository;
use FeedForge\Service\RuleEngine;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase
{
    private RuleEngine $engine;
    private RuleRepository $ruleRepo;

    protected function setUp(): void
    {
        $this->ruleRepo = $this->createMock(RuleRepository::class);
        $this->engine = new RuleEngine($this->ruleRepo);
    }

    // -------------------------------------------------------------------------
    // isExcluded
    // -------------------------------------------------------------------------

    public function testIsExcludedReturnsFalseWhenNoRules(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([]);

        $this->assertFalse($this->engine->isExcluded(1, ['price' => 10]));
    }

    public function testIsExcludedReturnsTrueWhenConditionMatches(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => json_encode([
                    ['field' => 'price', 'operator' => 'lt', 'value' => '5'],
                ]),
                'actions' => '{}',
            ],
        ]);

        $this->assertTrue($this->engine->isExcluded(1, ['price' => 3]));
    }

    public function testIsExcludedSkipsInactiveRules(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 0,
                'conditions' => json_encode([
                    ['field' => 'price', 'operator' => 'lt', 'value' => '5'],
                ]),
                'actions' => '{}',
            ],
        ]);

        $this->assertFalse($this->engine->isExcluded(1, ['price' => 3]));
    }

    public function testIsExcludedReturnsFalseWhenConditionDoesNotMatch(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => json_encode([
                    ['field' => 'price', 'operator' => 'gt', 'value' => '100'],
                ]),
                'actions' => '{}',
            ],
        ]);

        $this->assertFalse($this->engine->isExcluded(1, ['price' => 50]));
    }

    public function testEmptyConditionsAlwaysMatch(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => '{}',
            ],
        ]);

        $this->assertTrue($this->engine->isExcluded(1, ['price' => 50]));
    }

    // -------------------------------------------------------------------------
    // Operators via isExcluded (condition evaluation)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider operatorDataProvider
     */
    public function testOperators(string $operator, mixed $actual, mixed $expected, bool $shouldMatch): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => json_encode([
                    ['field' => 'test_field', 'operator' => $operator, 'value' => (string) $expected],
                ]),
                'actions' => '{}',
            ],
        ]);

        $result = $this->engine->isExcluded(1, ['test_field' => $actual]);
        $this->assertSame($shouldMatch, $result, "Operator '{$operator}' with actual={$actual}, expected={$expected}");
    }

    public static function operatorDataProvider(): array
    {
        return [
            // eq
            ['eq', 'abc', 'abc', true],
            ['eq', 'abc', 'def', false],
            ['eq', 100, '100', true],

            // neq
            ['neq', 'abc', 'def', true],
            ['neq', 'abc', 'abc', false],

            // gt, gte, lt, lte
            ['gt', 10, '5', true],
            ['gt', 5, '5', false],
            ['gt', 3, '5', false],
            ['gte', 5, '5', true],
            ['gte', 3, '5', false],
            ['lt', 3, '5', true],
            ['lt', 5, '5', false],
            ['lte', 5, '5', true],
            ['lte', 7, '5', false],

            // contains
            ['contains', 'Hello World', 'world', true],
            ['contains', 'Hello', 'xyz', false],

            // not_contains
            ['not_contains', 'Hello', 'xyz', true],
            ['not_contains', 'Hello World', 'world', false],

            // starts_with
            ['starts_with', 'PrestaShop', 'presta', true],
            ['starts_with', 'PrestaShop', 'shop', false],

            // ends_with
            ['ends_with', 'PrestaShop', 'shop', true],
            ['ends_with', 'PrestaShop', 'presta', false],

            // in / not_in
            ['in', 'red', 'red,blue,green', true],
            ['in', 'yellow', 'red,blue,green', false],
            ['not_in', 'yellow', 'red,blue,green', true],
            ['not_in', 'red', 'red,blue,green', false],

            // empty / not_empty
            ['empty', '', '', true],
            ['empty', 'abc', '', false],
            ['not_empty', 'abc', '', true],
            ['not_empty', '', '', false],

            // regex
            ['regex', 'product-123', '^\w+-\d+$', true],
            ['regex', 'invalid!', '^\w+$', false],
        ];
    }

    public function testNullFieldMatchesEmpty(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => json_encode([
                    ['field' => 'missing_field', 'operator' => 'empty', 'value' => ''],
                ]),
                'actions' => '{}',
            ],
        ]);

        // missing_field is not in context, should match 'empty'
        $this->assertTrue($this->engine->isExcluded(1, ['other' => 'value']));
    }

    // -------------------------------------------------------------------------
    // AND logic - multiple conditions
    // -------------------------------------------------------------------------

    public function testMultipleConditionsAllMustMatch(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => json_encode([
                    ['field' => 'price', 'operator' => 'gt', 'value' => '10'],
                    ['field' => 'quantity', 'operator' => 'eq', 'value' => '0'],
                ]),
                'actions' => '{}',
            ],
        ]);

        // Both match
        $this->assertTrue($this->engine->isExcluded(1, ['price' => 50, 'quantity' => 0]));

        // Only first matches
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => json_encode([
                    ['field' => 'price', 'operator' => 'gt', 'value' => '10'],
                    ['field' => 'quantity', 'operator' => 'eq', 'value' => '0'],
                ]),
                'actions' => '{}',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Pricing rules
    // -------------------------------------------------------------------------

    public function testPricingMarkupPercent(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['action' => 'markup_percent', 'value' => 10]),
            ],
        ]);

        $mapped = ['price' => ['value' => '100.00', 'currency' => 'PLN']];
        $result = $this->engine->applyPricingRules(1, $mapped, []);

        $this->assertSame('110.00', $result['price']['value']);
    }

    public function testPricingMarkupFixed(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['action' => 'markup_fixed', 'value' => 5]),
            ],
        ]);

        $mapped = ['price' => ['value' => '100.00', 'currency' => 'PLN']];
        $result = $this->engine->applyPricingRules(1, $mapped, []);

        $this->assertSame('105.00', $result['price']['value']);
    }

    public function testPricingMinPrice(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['action' => 'min_price', 'value' => 50]),
            ],
        ]);

        // Price below min → should be raised
        $mapped = ['price' => ['value' => '10.00', 'currency' => 'PLN']];
        $result = $this->engine->applyPricingRules(1, $mapped, []);
        $this->assertSame('50.00', $result['price']['value']);

        // Re-mock for price above min → should stay
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['action' => 'min_price', 'value' => 50]),
            ],
        ]);

        $mapped2 = ['price' => ['value' => '100.00', 'currency' => 'PLN']];
        $result2 = $this->engine->applyPricingRules(1, $mapped2, []);
        $this->assertSame('100.00', $result2['price']['value']);
    }

    public function testPricingRound99(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['action' => 'round_99', 'value' => 0]),
            ],
        ]);

        $mapped = ['price' => ['value' => '45.50', 'currency' => 'PLN']];
        $result = $this->engine->applyPricingRules(1, $mapped, []);

        $this->assertSame('45.99', $result['price']['value']);
    }

    // -------------------------------------------------------------------------
    // Custom label rules
    // -------------------------------------------------------------------------

    public function testCustomLabelApplied(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['label_index' => 0, 'value' => 'bestseller']),
            ],
        ]);

        $mapped = [];
        $result = $this->engine->applyCustomLabelRules(1, $mapped, []);

        $this->assertSame('bestseller', $result['customLabel0']);
    }

    // -------------------------------------------------------------------------
    // Identifier rules
    // -------------------------------------------------------------------------

    public function testIdentifierFallbackGtin(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['field' => 'gtin', 'source' => 'fixed', 'value' => '1234567890123']),
            ],
        ]);

        $mapped = [];
        $result = $this->engine->applyIdentifierRules(1, $mapped, []);

        $this->assertSame('1234567890123', $result['gtin']);
    }

    public function testIdentifierDoesNotOverwriteExisting(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['field' => 'gtin', 'source' => 'fixed', 'value' => 'new-gtin']),
            ],
        ]);

        $mapped = ['gtin' => 'existing-gtin'];
        $result = $this->engine->applyIdentifierRules(1, $mapped, []);

        $this->assertSame('existing-gtin', $result['gtin']);
    }

    public function testIdentifierFromContext(): void
    {
        $this->ruleRepo->method('findByShop')->willReturn([
            [
                'active' => 1,
                'conditions' => '[]',
                'actions' => json_encode(['field' => 'brand', 'source' => 'context', 'value' => 'brand']),
            ],
        ]);

        $mapped = [];
        $context = ['brand' => 'Samsung'];
        $result = $this->engine->applyIdentifierRules(1, $mapped, $context);

        $this->assertSame('Samsung', $result['brand']);
    }
}
