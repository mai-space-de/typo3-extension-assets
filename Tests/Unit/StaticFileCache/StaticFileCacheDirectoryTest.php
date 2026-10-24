<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StaticFileCacheDirectory path-building logic.
 *
 * The filesystem-dependent methods (getAbsoluteBaseDirectory, getPageDirectory,
 * ensureDirectoryExists) rely on TYPO3\CMS\Core\Core\Environment, which is not
 * available in lightweight unit tests. Those methods are covered via functional
 * tests. This test suite covers the pure path-building and URI-validation logic
 * exposed through buildRelativePagePath() and isValidUri().
 *
 * ExtensionConfiguration is final and cannot be mocked; its constructor calls
 * Typo3ExtensionConfiguration::get(), which requires a running TYPO3 instance.
 * We bypass the constructor via ReflectionClass and inject a known value for
 * the staticFileCacheDir property using ReflectionProperty.
 */
final class StaticFileCacheDirectoryTest extends TestCase
{
    private StaticFileCacheDirectory $subject;

    protected function setUp(): void
    {
        $this->subject = $this->makeSubject('');
    }

    // -------------------------------------------------------------------------
    // isValidUri()
    // -------------------------------------------------------------------------

    public function testIsValidUriReturnsTrueForFullHttpsUrl(): void
    {
        self::assertTrue($this->subject->isValidUri('https://www.bgm-pulheim.org/de/'));
    }

    public function testIsValidUriReturnsTrueForHttpUrl(): void
    {
        self::assertTrue($this->subject->isValidUri('http://example.com/'));
    }

    public function testIsValidUriReturnsTrueForUrlWithPort(): void
    {
        self::assertTrue($this->subject->isValidUri('https://example.com:8443/path/'));
    }

    public function testIsValidUriReturnsTrueForUrlWithQueryString(): void
    {
        // Query strings are allowed — they are simply ignored in path building.
        self::assertTrue($this->subject->isValidUri('https://example.com/page/?lang=de'));
    }

    public function testIsValidUriReturnsFalseForRelativePath(): void
    {
        self::assertFalse($this->subject->isValidUri('/de/news/'));
    }

    public function testIsValidUriReturnsFalseForEmptyString(): void
    {
        self::assertFalse($this->subject->isValidUri(''));
    }

    public function testIsValidUriReturnsFalseForSchemeOnly(): void
    {
        self::assertFalse($this->subject->isValidUri('https://'));
    }

    public function testIsValidUriReturnsFalseForNoPath(): void
    {
        // parse_url('https://example.com') returns no 'path' key → invalid.
        self::assertFalse($this->subject->isValidUri('https://example.com'));
    }

    public function testIsValidUriReturnsFalseForPlainString(): void
    {
        self::assertFalse($this->subject->isValidUri('not-a-url'));
    }

    // -------------------------------------------------------------------------
    // buildRelativePagePath() — host segment
    // -------------------------------------------------------------------------

    public function testBuildRelativePagePathUsesHttpsDefaultPort(): void
    {
        $result = $this->subject->buildRelativePagePath('https://www.bgm-pulheim.org/de/');
        self::assertStringStartsWith('https_www.bgm-pulheim.org_443/', $result);
    }

    public function testBuildRelativePagePathUsesHttpDefaultPort(): void
    {
        $result = $this->subject->buildRelativePagePath('http://example.com/page/');
        self::assertStringStartsWith('http_example.com_80/', $result);
    }

    public function testBuildRelativePagePathUsesExplicitPort(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com:8443/path/');
        self::assertStringStartsWith('https_example.com_8443/', $result);
    }

    public function testBuildRelativePagePathNormalisesHostToLowercase(): void
    {
        $result = $this->subject->buildRelativePagePath('https://WWW.Example.COM/page/');
        self::assertStringStartsWith('https_www.example.com_443/', $result);
    }

    public function testBuildRelativePagePathNormalisesSchemeToLowercase(): void
    {
        $result = $this->subject->buildRelativePagePath('HTTPS://example.com/page/');
        self::assertStringStartsWith('https_example.com_443/', $result);
    }

    // -------------------------------------------------------------------------
    // buildRelativePagePath() — path segment
    // -------------------------------------------------------------------------

    public function testBuildRelativePagePathIncludesUrlPathAfterHostSegment(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com/de/news/');
        self::assertSame('https_example.com_443/de/news/', $result);
    }

    public function testBuildRelativePagePathStripsLeadingAndTrailingSlashesFromPath(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com/de/news/');
        // URL path "de/news" is embedded without extra slashes.
        self::assertSame('https_example.com_443/de/news/', $result);
    }

    public function testBuildRelativePagePathForRootUrlOmitsPathSegment(): void
    {
        // Root "/" trims to "" — the path segment is omitted to avoid double slash.
        $result = $this->subject->buildRelativePagePath('https://example.com/');
        self::assertSame('https_example.com_443/', $result);
    }

    public function testBuildRelativePagePathIgnoresQueryString(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com/de/?lang=en');
        self::assertSame('https_example.com_443/de/', $result);
    }

    public function testBuildRelativePagePathIgnoresFragment(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com/page/#section');
        self::assertSame('https_example.com_443/page/', $result);
    }

    public function testBuildRelativePagePathAlwaysEndsWithSlash(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com/de/news/');
        self::assertStringEndsWith('/', $result);
    }

    public function testBuildRelativePagePathNeverStartsWithSlash(): void
    {
        $result = $this->subject->buildRelativePagePath('https://example.com/de/news/');
        self::assertStringStartsNotWith('/', $result);
    }

    // -------------------------------------------------------------------------
    // buildRelativePagePath() — invalid input
    // -------------------------------------------------------------------------

    public function testBuildRelativePagePathThrowsForRelativePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->buildRelativePagePath('/de/news/');
    }

    public function testBuildRelativePagePathThrowsForEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->buildRelativePagePath('');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a subject with a specific staticFileCacheDir value injected without
     * bootstrapping the TYPO3 extension configuration system.
     */
    private function makeSubject(string $staticFileCacheDir): StaticFileCacheDirectory
    {
        $config = (new \ReflectionClass(ExtensionConfiguration::class))
            ->newInstanceWithoutConstructor();

        $prop = new \ReflectionProperty(ExtensionConfiguration::class, 'staticFileCacheDir');
        $prop->setValue($config, $staticFileCacheDir);

        return new StaticFileCacheDirectory($config);
    }

    // -------------------------------------------------------------------------
    // buildRelativePagePathById()
    // -------------------------------------------------------------------------

    public function testBuildRelativePagePathByIdReturnsPageIdAndLanguage(): void
    {
        $result = $this->subject->buildRelativePagePathById(42, 3);
        self::assertSame('42_3/', $result);
    }

    public function testBuildRelativePagePathByIdWithDefaultLanguage(): void
    {
        $result = $this->subject->buildRelativePagePathById(42, 0);
        self::assertSame('42_0/', $result);
    }

    public function testBuildRelativePagePathByIdWithLargePageId(): void
    {
        $result = $this->subject->buildRelativePagePathById(999999, 1);
        self::assertSame('999999_1/', $result);
    }

    public function testBuildRelativePagePathByIdAlwaysEndsWithSlash(): void
    {
        $result = $this->subject->buildRelativePagePathById(42, 0);
        self::assertStringEndsWith('/', $result);
    }

    public function testBuildRelativePagePathByIdNeverStartsWithSlash(): void
    {
        $result = $this->subject->buildRelativePagePathById(42, 0);
        self::assertStringStartsNotWith('/', $result);
    }

    public function testBuildRelativePagePathByIdThrowsForZeroPageUid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->buildRelativePagePathById(0, 0);
    }

    public function testBuildRelativePagePathByIdThrowsForNegativePageUid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->buildRelativePagePathById(-1, 0);
    }

    public function testBuildRelativePagePathByIdThrowsForNegativeLanguageUid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->buildRelativePagePathById(42, -1);
    }

    // -------------------------------------------------------------------------
    // getPageDirectoryById() — invalid input
    // -------------------------------------------------------------------------

    public function testGetPageDirectoryByIdThrowsForZeroPageUid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->getPageDirectoryById(0, 0);
    }

    public function testGetPageDirectoryByIdThrowsForNegativeLanguageUid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject->getPageDirectoryById(42, -1);
    }

    // -------------------------------------------------------------------------
    // Consistency: page-ID-based and URL-based paths are separate namespaces
    // -------------------------------------------------------------------------

    public function testPageIdBasedPathDiffersFromUrlBasedPath(): void
    {
        $idPath = $this->subject->buildRelativePagePathById(42, 0);
        $urlPath = $this->subject->buildRelativePagePath('https://example.com/42_0/');
        // ID-based path starts with the numeric page UID; URL-based path
        // starts with a scheme_host_port host segment containing underscores.
        self::assertStringStartsNotWith('https', $idPath);
        self::assertStringContainsString('https', $urlPath);
    }

    public function testDifferentLanguagesProduceDifferentPaths(): void
    {
        $dePath = $this->subject->buildRelativePagePathById(42, 0);
        $enPath = $this->subject->buildRelativePagePathById(42, 1);

        // Each language UID yields a distinct directory so cache entries are isolated.
        self::assertNotSame($dePath, $enPath);
    }
}
