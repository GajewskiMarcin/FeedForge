<?php

declare(strict_types=1);

namespace FeedForge\Tests\Service;

use FeedForge\Repository\TaxonomyRepository;
use FeedForge\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

class TaxonomyServiceTest extends TestCase
{
    private TaxonomyService $service;
    private TaxonomyRepository $taxonomyRepo;

    protected function setUp(): void
    {
        $this->taxonomyRepo = $this->createMock(TaxonomyRepository::class);
        $this->service = new TaxonomyService($this->taxonomyRepo);
    }

    // -------------------------------------------------------------------------
    // search
    // -------------------------------------------------------------------------

    public function testSearchReturnsResults(): void
    {
        $this->taxonomyRepo->method('search')->willReturn([
            ['taxonomy_id' => 1, 'value' => 'Electronics'],
        ]);

        $result = $this->service->search('electronics', 'en', 20);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['taxonomy_id']);
    }

    public function testSearchReturnsEmptyForShortQuery(): void
    {
        // Query < 2 chars should return empty without calling repo
        $this->taxonomyRepo->expects($this->never())->method('search');

        $result = $this->service->search('a', 'en');

        $this->assertSame([], $result);
    }

    public function testSearchReturnsEmptyForBlankQuery(): void
    {
        $this->taxonomyRepo->expects($this->never())->method('search');

        $result = $this->service->search('  ', 'en');

        $this->assertSame([], $result);
    }

    public function testSearchPassesPreparedTermsToRepository(): void
    {
        $this->taxonomyRepo->expects($this->once())
            ->method('search')
            ->with('+clothing* +shoes*', 'pl', 10)
            ->willReturn([]);

        $this->service->search('clothing shoes', 'pl', 10);
    }

    public function testSearchSkipsSingleCharWords(): void
    {
        $this->taxonomyRepo->expects($this->once())
            ->method('search')
            ->with('+electronics*', 'en', 20)
            ->willReturn([]);

        $this->service->search('a electronics b', 'en', 20);
    }

    // -------------------------------------------------------------------------
    // findById
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsEntry(): void
    {
        $this->taxonomyRepo->method('findById')->willReturn([
            'taxonomy_id' => 123,
            'value' => 'Electronics > Computers',
            'lang' => 'en',
        ]);

        $result = $this->service->findById(123, 'en');

        $this->assertNotNull($result);
        $this->assertSame(123, $result['taxonomy_id']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->taxonomyRepo->method('findById')->willReturn(null);

        $result = $this->service->findById(999, 'en');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // importFromGoogle
    // -------------------------------------------------------------------------

    public function testImportFromGoogleThrowsForUnsupportedLang(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported taxonomy language: xx');

        $this->service->importFromGoogle('xx');
    }

    // -------------------------------------------------------------------------
    // isLoaded
    // -------------------------------------------------------------------------

    public function testIsLoadedReturnsTrueWhenDataExists(): void
    {
        $this->taxonomyRepo->method('search')->willReturn([
            ['taxonomy_id' => 1, 'value' => 'Electronics'],
        ]);

        $this->assertTrue($this->service->isLoaded('en'));
    }

    public function testIsLoadedReturnsFalseWhenEmpty(): void
    {
        $this->taxonomyRepo->method('search')->willReturn([]);

        $this->assertFalse($this->service->isLoaded('en'));
    }
}
