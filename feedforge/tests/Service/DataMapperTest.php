<?php

declare(strict_types=1);

namespace FeedForge\Tests\Service;

use FeedForge\Service\DataMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure utility methods on DataMapper.
 * DB-dependent methods (mapProduct, getActiveProductIds) require integration tests.
 */
class DataMapperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // computeHash
    // -------------------------------------------------------------------------

    public function testComputeHashReturnsSha1(): void
    {
        // We need to instantiate DataMapper but its constructor has dependencies.
        // computeHash is a public method we can test via reflection or a partial mock.
        $mapper = $this->createPartialMock(DataMapper::class, []);

        $data = ['title' => 'Test Product', 'price' => ['value' => '99.99', 'currency' => 'PLN']];
        $hash = $mapper->computeHash($data);

        $this->assertSame(40, strlen($hash)); // SHA-1 hex = 40 chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
    }

    public function testComputeHashExcludesLinkField(): void
    {
        $mapper = $this->createPartialMock(DataMapper::class, []);

        $data1 = ['title' => 'Test', 'link' => 'https://shop.com/product-1'];
        $data2 = ['title' => 'Test', 'link' => 'https://shop.com/product-new-url'];

        // Different links should not affect hash
        $this->assertSame($mapper->computeHash($data1), $mapper->computeHash($data2));
    }

    public function testComputeHashDiffersForDifferentData(): void
    {
        $mapper = $this->createPartialMock(DataMapper::class, []);

        $data1 = ['title' => 'Product A', 'price' => ['value' => '100.00']];
        $data2 = ['title' => 'Product B', 'price' => ['value' => '200.00']];

        $this->assertNotSame($mapper->computeHash($data1), $mapper->computeHash($data2));
    }

    public function testComputeHashIsDeterministic(): void
    {
        $mapper = $this->createPartialMock(DataMapper::class, []);

        $data = ['title' => 'Consistent', 'price' => '50.00'];

        $this->assertSame($mapper->computeHash($data), $mapper->computeHash($data));
    }

    public function testComputeHashIsSortedByKeys(): void
    {
        $mapper = $this->createPartialMock(DataMapper::class, []);

        // Different key order, same data
        $data1 = ['b' => 2, 'a' => 1];
        $data2 = ['a' => 1, 'b' => 2];

        $this->assertSame($mapper->computeHash($data1), $mapper->computeHash($data2));
    }
}
