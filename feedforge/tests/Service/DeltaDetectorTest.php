<?php

declare(strict_types=1);

namespace FeedForge\Tests\Service;

use FeedForge\Repository\FeedConfigRepository;
use FeedForge\Repository\GmcProductRepository;
use FeedForge\Service\DataMapper;
use FeedForge\Service\DeltaDetector;
use PHPUnit\Framework\TestCase;

class DeltaDetectorTest extends TestCase
{
    private DeltaDetector $detector;
    private DataMapper $dataMapper;
    private GmcProductRepository $gmcProductRepo;
    private FeedConfigRepository $feedConfigRepo;

    protected function setUp(): void
    {
        $this->dataMapper = $this->createMock(DataMapper::class);
        $this->gmcProductRepo = $this->createMock(GmcProductRepository::class);
        $this->feedConfigRepo = $this->createMock(FeedConfigRepository::class);

        $this->detector = new DeltaDetector(
            $this->dataMapper,
            $this->gmcProductRepo,
            $this->feedConfigRepo,
        );
    }

    // -------------------------------------------------------------------------
    // detect
    // -------------------------------------------------------------------------

    public function testDetectReturnsEmptyWhenNoActiveFeedConfigs(): void
    {
        $this->feedConfigRepo->method('findActive')->willReturn([]);

        $result = $this->detector->detect(1);

        $this->assertSame([], $result['insert']);
        $this->assertSame([], $result['update']);
        $this->assertSame([], $result['delete']);
    }

    // -------------------------------------------------------------------------
    // checkProduct
    // -------------------------------------------------------------------------

    public function testCheckProductReturnsInsertForNewProduct(): void
    {
        $this->dataMapper->method('mapProduct')->willReturn(['title' => 'Test']);
        $this->dataMapper->method('computeHash')->willReturn('abc123');
        $this->gmcProductRepo->method('findByProduct')->willReturn(null);

        $result = $this->detector->checkProduct(1, 0, 1, 1);

        $this->assertSame('insert', $result['action']);
        $this->assertSame('abc123', $result['hash']);
    }

    public function testCheckProductReturnsUpdateWhenHashChanged(): void
    {
        $this->dataMapper->method('mapProduct')->willReturn(['title' => 'Test Updated']);
        $this->dataMapper->method('computeHash')->willReturn('new-hash');
        $this->gmcProductRepo->method('findByProduct')->willReturn([
            'id_feedforge_product' => 1,
            'content_hash' => 'old-hash',
            'gmc_id' => 'online:pl:PL:123',
        ]);

        $result = $this->detector->checkProduct(1, 0, 1, 1);

        $this->assertSame('update', $result['action']);
        $this->assertSame('new-hash', $result['hash']);
    }

    public function testCheckProductReturnsNullWhenHashUnchanged(): void
    {
        $this->dataMapper->method('mapProduct')->willReturn(['title' => 'Same']);
        $this->dataMapper->method('computeHash')->willReturn('same-hash');
        $this->gmcProductRepo->method('findByProduct')->willReturn([
            'id_feedforge_product' => 1,
            'content_hash' => 'same-hash',
            'gmc_id' => 'online:pl:PL:123',
        ]);

        $result = $this->detector->checkProduct(1, 0, 1, 1);

        $this->assertNull($result);
    }

    public function testCheckProductReturnsDeleteWhenExcludedButExistsInGmc(): void
    {
        // mapProduct returns null = excluded by rules
        $this->dataMapper->method('mapProduct')->willReturn(null);
        $this->gmcProductRepo->method('findByProduct')->willReturn([
            'id_feedforge_product' => 1,
            'content_hash' => 'some-hash',
            'gmc_id' => 'online:pl:PL:123',
        ]);

        $result = $this->detector->checkProduct(1, 0, 1, 1);

        $this->assertSame('delete', $result['action']);
    }

    public function testCheckProductReturnsNullWhenExcludedAndNotInGmc(): void
    {
        $this->dataMapper->method('mapProduct')->willReturn(null);
        $this->gmcProductRepo->method('findByProduct')->willReturn(null);

        $result = $this->detector->checkProduct(1, 0, 1, 1);

        $this->assertNull($result);
    }

    public function testCheckProductReturnsNullWhenExcludedAndNoGmcId(): void
    {
        $this->dataMapper->method('mapProduct')->willReturn(null);
        $this->gmcProductRepo->method('findByProduct')->willReturn([
            'id_feedforge_product' => 1,
            'content_hash' => 'some-hash',
            'gmc_id' => '', // empty = never synced to GMC
        ]);

        $result = $this->detector->checkProduct(1, 0, 1, 1);

        $this->assertNull($result);
    }
}
