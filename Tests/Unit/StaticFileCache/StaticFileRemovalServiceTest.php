<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class StaticFileRemovalServiceTest extends TestCase
{
    /** @var StaticFileCacheDirectory&\PHPUnit\Framework\MockObject\MockObject */
    private StaticFileCacheDirectory $cacheDirectory;

    private StaticFileRemovalService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDirectory = $this->createMock(StaticFileCacheDirectory::class);

        $this->subject = new StaticFileRemovalService(
            $this->cacheDirectory,
        );
        $this->subject->setLogger(new NullLogger());
    }

    private function createSiteWithLanguages(array $languageIds): Site
    {
        $site = $this->createMock(Site::class);
        $languages = [];

        foreach ($languageIds as $langId) {
            $language = $this->createMock(SiteLanguage::class);
            $language->method('getLanguageId')->willReturn($langId);
            $languages[] = $language;
        }

        $site->method('getLanguages')->willReturn($languages);

        return $site;
    }

    // ─── purgeByPageUid — guard cases ──────────────────────────────────────

    public function testPurgeByPageUidSkipsZeroPageUid(): void
    {
        $this->cacheDirectory->expects(self::never())->method('getPageDirectoryById');
        $this->subject->purgeByPageUid(0);
    }

    public function testPurgeByPageUidSkipsNegativePageUid(): void
    {
        $this->cacheDirectory->expects(self::never())->method('getPageDirectoryById');
        $this->subject->purgeByPageUid(-1);
    }

    // ─── purgeByPageUid — happy path with pre-resolved site ────────────────

    public function testPurgeByPageUidIteratesAllLanguages(): void
    {
        $pageUid = 42;
        $site = $this->createSiteWithLanguages([0, 1, 2]);

        $this->cacheDirectory
            ->expects(self::exactly(3))
            ->method('getPageDirectoryById')
            ->willReturnCallback(fn(int $uid, int $langId): string => "/tmp/mai-test/{$uid}_{$langId}");

        $this->subject->purgeByPageUid($pageUid, $site);
    }

    // ─── purgeForLanguage — guard cases ────────────────────────────────────

    public function testPurgeForLanguageSkipsZeroPageUid(): void
    {
        $this->cacheDirectory->expects(self::never())->method('getPageDirectoryById');
        $this->subject->purgeForLanguage(0, 0);
    }

    // ─── purgeForLanguage — getPageDirectoryById exception ─────────────────

    public function testPurgeForLanguageHandlesGetPageDirectoryException(): void
    {
        $this->expectNotToPerformAssertions();
        $pageUid = 42;

        $this->cacheDirectory
            ->method('getPageDirectoryById')
            ->willThrowException(new \InvalidArgumentException('Path outside base'));

        $this->subject->purgeForLanguage($pageUid, 0);
    }

    // ─── purgeForLanguage — filesystem integration ─────────────────────────

    public function testPurgeForLanguageDeletesExistingDirectory(): void
    {
        $pageUid = 42;

        $tempDir = rtrim(sys_get_temp_dir(), '/') . '/mai-test-' . uniqid('sfr_', true) . '/';
        @mkdir($tempDir, 0777, true);
        self::assertDirectoryExists($tempDir);

        $this->cacheDirectory
            ->expects(self::once())
            ->method('getPageDirectoryById')
            ->with($pageUid, 0)
            ->willReturn($tempDir);

        $this->subject->purgeForLanguage($pageUid, 0);

        self::assertDirectoryDoesNotExist($tempDir);
    }

    public function testPurgeForLanguageSkipsNonExistentDirectory(): void
    {
        $pageUid = 42;
        $nonExistentDir = '/tmp/mai-test-nonexistent-' . uniqid();
        self::assertDirectoryDoesNotExist($nonExistentDir);

        $this->cacheDirectory
            ->method('getPageDirectoryById')
            ->willReturn($nonExistentDir);

        $this->subject->purgeForLanguage($pageUid, 0);
    }

    public function testPurgeForLanguageAcceptsUnusedSiteParameter(): void
    {
        $this->expectNotToPerformAssertions();
        $pageUid = 42;
        $site = $this->createMock(Site::class);

        $this->cacheDirectory
            ->method('getPageDirectoryById')
            ->willReturn('/tmp/mai-test/dir');

        $this->subject->purgeForLanguage($pageUid, 0, $site);
    }

    // ─── purgeByPageUid — integration (filesystem + multiple languages) ────

    public function testPurgeByPageUidRemovesDirectoriesForAllLanguages(): void
    {
        $pageUid = 42;
        $langIds = [0, 1];

        $tempDirs = [];
        foreach ($langIds as $langId) {
            $dir = rtrim(sys_get_temp_dir(), '/') . '/mai-test-' . uniqid('sfr_int_', true) . '/';
            @mkdir($dir, 0777, true);
            $tempDirs[$langId] = $dir;
        }

        $site = $this->createSiteWithLanguages($langIds);

        $invocationCount = 0;
        $this->cacheDirectory
            ->expects(self::exactly(2))
            ->method('getPageDirectoryById')
            ->willReturnCallback(function (int $uid, int $langId) use (&$invocationCount, $tempDirs, $langIds, $pageUid): string {
                self::assertSame($pageUid, $uid);
                $dir = $tempDirs[$langIds[$invocationCount]];
                $invocationCount++;
                return $dir;
            });

        $this->subject->purgeByPageUid($pageUid, $site);

        foreach ($tempDirs as $dir) {
            self::assertDirectoryDoesNotExist($dir);
        }
    }

    public function testPageIdPathUsesSameIdentifiersAsEarlyHintCacheKey(): void
    {
        $pageUid = 42;
        $languageUid = 3;

        $this->cacheDirectory
            ->expects(self::once())
            ->method('getPageDirectoryById')
            ->with($pageUid, $languageUid)
            ->willReturn('/tmp/mai-test/42_3');

        $this->subject->purgeForLanguage($pageUid, $languageUid);
    }
}
