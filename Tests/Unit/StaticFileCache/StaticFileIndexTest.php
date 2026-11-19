<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Maispace\MaiAssets\StaticFileCache\StaticFileIndex;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

final class StaticFileIndexTest extends TestCase
{
    private VariableFrontend $cache;
    private StaticFileIndex $subject;

    protected function setUp(): void
    {
        $this->cache = new VariableFrontend(StaticFileIndex::CACHE_IDENTIFIER, new TransientMemoryBackend());
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with(StaticFileIndex::CACHE_IDENTIFIER)->willReturn($this->cache);
        $this->subject = new StaticFileIndex($cacheManager);
    }

    public function testRegisteredFileIsValidUntilOneOfItsPageCacheTagsIsFlushed(): void
    {
        $this->subject->register(12, 0, ['tx_news_pid_5'], 3600);
        self::assertTrue($this->subject->isValid(12, 0));
        self::assertFalse($this->subject->isValid(12, 1));

        // e.g. a news record on storage folder 5 was edited
        $this->cache->flushByTag('tx_news_pid_5');

        self::assertFalse($this->subject->isValid(12, 0));
    }

    public function testPageIdTagIsAlwaysAdded(): void
    {
        $this->subject->register(12, 1, [], 3600);

        $this->cache->flushByTag('pageId_12');

        self::assertFalse($this->subject->isValid(12, 1));
    }

    public function testInvalidTagsAreIgnored(): void
    {
        $this->subject->register(3, 0, ['valid_tag', 'in valid', ''], 3600);

        self::assertTrue($this->subject->isValid(3, 0));
    }

    public function testFindsFilesByTag(): void
    {
        $this->subject->register(12, 0, ['tx_news_pid_5'], 3600);
        $this->subject->register(12, 1, ['tx_news_pid_5'], 3600);
        $this->subject->register(13, 0, ['other'], 3600);

        self::assertEqualsCanonicalizing(
            [['page' => 12, 'language' => 0], ['page' => 12, 'language' => 1]],
            $this->subject->findByTags(['tx_news_pid_5', 'unknown']),
        );
    }

    public function testUnknownLifetimesFallBackToTheDefault(): void
    {
        foreach ([0, -5, PHP_INT_MAX] as $index => $lifetime) {
            $this->subject->register(20 + $index, 0, [], $lifetime);
            self::assertTrue($this->subject->isValid(20 + $index, 0));
        }
    }

    public function testIdentifierRoundTrip(): void
    {
        self::assertSame(['page' => 7, 'language' => 2], StaticFileIndex::parseIdentifier(StaticFileIndex::identifier(7, 2)));
        self::assertNull(StaticFileIndex::parseIdentifier('static_x_1'));
    }
}
