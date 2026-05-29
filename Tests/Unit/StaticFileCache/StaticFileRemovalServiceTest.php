<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        $router = $this->createMock(RouterInterface::class);

        foreach ($languageIds as $langId) {
            $language = $this->createMock(SiteLanguage::class);
            $language->method('getLanguageId')->willReturn($langId);

            $uri = $this->createMock(UriInterface::class);
            $uri->method('__toString')->willReturn("/lang-{$langId}/page-title");
            $router->method('generateUri')->willReturn($uri);

            $languages[] = $language;
        }

        $site->method('getLanguages')->willReturn($languages);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        return $site;
    }

    // ─── purgeByPageUid — guard cases ──────────────────────────────────────

    public function testPurgeByPageUidSkipsZeroPageUid(): void
    {
        $this->cacheDirectory->expects(self::never())->method('getPageDirectory');
        $this->subject->purgeByPageUid(0);
    }

    public function testPurgeByPageUidSkipsNegativePageUid(): void
    {
        $this->cacheDirectory->expects(self::never())->method('getPageDirectory');
        $this->subject->purgeByPageUid(-1);
    }

    // ─── purgeByPageUid — happy path with pre-resolved site ────────────────

    public function testPurgeByPageUidIteratesAllLanguages(): void
    {
        $pageUid = 42;
        $site = $this->createSiteWithLanguages([0, 1, 2]);

        $this->cacheDirectory
            ->expects(self::exactly(3))
            ->method('getPageDirectory')
            ->willReturnCallback(fn(string $url): string => match (true) {
                str_starts_with($url, 'https://example.com/lang-0/') => '/tmp/mai-test/lang-0',
                str_starts_with($url, 'https://example.com/lang-1/') => '/tmp/mai-test/lang-1',
                str_starts_with($url, 'https://example.com/lang-2/') => '/tmp/mai-test/lang-2',
                default => '/tmp/mai-test/unknown',
            });

        $this->cacheDirectory->method('isValidUri')->willReturn(true);

        $this->subject->purgeByPageUid($pageUid, $site);
    }

    // ─── purgeForLanguage — guard cases ────────────────────────────────────

    public function testPurgeForLanguageSkipsZeroPageUid(): void
    {
        $this->cacheDirectory->expects(self::never())->method('getPageDirectory');
        $this->subject->purgeForLanguage(0, 0);
    }

    // ─── purgeForLanguage — skip when language not configured ──────────────

    public function testPurgeForLanguageSkipsWhenLanguageNotFound(): void
    {
        $site = $this->createMock(Site::class);
        $defaultLanguage = $this->createMock(SiteLanguage::class);
        $defaultLanguage->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$defaultLanguage]);

        $this->cacheDirectory->expects(self::never())->method('getPageDirectory');

        $this->subject->purgeForLanguage(42, 1, $site);
    }

    // ─── purgeForLanguage — router exception ───────────────────────────────

    public function testPurgeForLanguageHandlesRouterException(): void
    {
        $site = $this->createMock(Site::class);
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$language]);

        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('generateUri')
            ->willThrowException(new \RuntimeException('Routing failed'));
        $site->method('getRouter')->willReturn($router);

        $this->cacheDirectory->expects(self::never())->method('getPageDirectory');

        $this->subject->purgeForLanguage(42, 0, $site);
    }

    // ─── purgeForLanguage — invalid URI skip ───────────────────────────────

    public function testPurgeForLanguageSkipsInvalidUri(): void
    {
        $site = $this->createMock(Site::class);
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$language]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->willReturn($uri);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        $this->cacheDirectory
            ->expects(self::once())
            ->method('isValidUri')
            ->willReturn(false);

        $this->cacheDirectory->expects(self::never())->method('getPageDirectory');

        $this->subject->purgeForLanguage(42, 0, $site);
    }

    // ─── purgeForLanguage — getPageDirectory exception ─────────────────────

    public function testPurgeForLanguageHandlesGetPageDirectoryException(): void
    {
        $this->expectNotToPerformAssertions();
        $pageUid = 42;

        $site = $this->createMock(Site::class);
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$language]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/page-title');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->willReturn($uri);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        $this->cacheDirectory->method('isValidUri')->willReturn(true);

        $this->cacheDirectory
            ->method('getPageDirectory')
            ->willThrowException(new \InvalidArgumentException('Path outside base'));

        $this->subject->purgeForLanguage($pageUid, 0, $site);
    }

    // ─── purgeForLanguage — filesystem integration ─────────────────────────

    public function testPurgeForLanguageDeletesExistingDirectory(): void
    {
        $pageUid = 42;

        $tempDir = rtrim(sys_get_temp_dir(), '/') . '/mai-test-' . uniqid('sfr_', true) . '/';
        @mkdir($tempDir, 0777, true);
        self::assertDirectoryExists($tempDir);

        $site = $this->createMock(Site::class);
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$language]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/page-title');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->with($pageUid, self::anything())->willReturn($uri);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        $this->cacheDirectory->method('isValidUri')->willReturn(true);

        $this->cacheDirectory
            ->expects(self::once())
            ->method('getPageDirectory')
            ->with('https://example.com/page-title')
            ->willReturn($tempDir);

        $this->subject->purgeForLanguage($pageUid, 0, $site);

        self::assertDirectoryDoesNotExist($tempDir);
    }

    public function testPurgeForLanguageSkipsNonExistentDirectory(): void
    {
        $pageUid = 42;
        $nonExistentDir = '/tmp/mai-test-nonexistent-' . uniqid();
        self::assertDirectoryDoesNotExist($nonExistentDir);

        $site = $this->createMock(Site::class);
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$language]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/page-title');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->with($pageUid, self::anything())->willReturn($uri);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        $this->cacheDirectory->method('isValidUri')->willReturn(true);
        $this->cacheDirectory
            ->method('getPageDirectory')
            ->willReturn($nonExistentDir);

        $this->subject->purgeForLanguage($pageUid, 0, $site);
    }

    // ─── purgeForLanguage — pre-resolved site passthrough ──────────────────

    public function testPurgeForLanguageAcceptsPreResolvedSite(): void
    {
        $this->expectNotToPerformAssertions();
        $pageUid = 42;

        $site = $this->createMock(Site::class);
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(0);
        $site->method('getLanguages')->willReturn([$language]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('/page-title');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->willReturn($uri);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        $this->cacheDirectory->method('isValidUri')->willReturn(true);
        $this->cacheDirectory
            ->method('getPageDirectory')
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

        $site = $this->createMock(Site::class);
        $languages = [];
        $router = $this->createMock(RouterInterface::class);

        foreach ($langIds as $langId) {
            $language = $this->createMock(SiteLanguage::class);
            $language->method('getLanguageId')->willReturn($langId);

            $uri = $this->createMock(UriInterface::class);
            $uri->method('__toString')->willReturn("/lang-{$langId}/title");
            $router
                ->method('generateUri')
                ->with($pageUid, self::anything())
                ->willReturn($uri);

            $languages[] = $language;
        }

        $site->method('getLanguages')->willReturn($languages);
        $site->method('getRouter')->willReturn($router);

        $baseUri = $this->createMock(UriInterface::class);
        $baseUri->method('__toString')->willReturn('https://example.com/');
        $site->method('getBase')->willReturn($baseUri);

        $this->cacheDirectory->method('isValidUri')->willReturn(true);

        $invocationCount = 0;
        $this->cacheDirectory
            ->expects(self::exactly(2))
            ->method('getPageDirectory')
            ->willReturnCallback(function () use (&$invocationCount, $tempDirs, $langIds): string {
                $dir = $tempDirs[$langIds[$invocationCount]];
                $invocationCount++;
                return $dir;
            });

        $this->subject->purgeByPageUid($pageUid, $site);

        foreach ($tempDirs as $dir) {
            self::assertDirectoryDoesNotExist($dir);
        }
    }
}
