<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Service;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Service\AssetProcessingService;
use Maispace\MaispaceAssets\Service\ScssCompilerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Page\AssetCollector;

/**
 * Unit tests for AssetProcessingService helper methods.
 *
 * The private methods are exercised via PHP Reflection so we can
 * test the pure logic without bootstrapping a full TYPO3 environment.
 */
final class AssetProcessingServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isExternalUrl
    // -------------------------------------------------------------------------

    #[DataProvider('provideExternalUrls')]
    public function testIsExternalUrlReturnsTrueForExternalUrls(string $url): void
    {
        self::assertTrue($this->callIsExternalUrl($url));
    }

    #[DataProvider('provideLocalUrls')]
    public function testIsExternalUrlReturnsFalseForLocalPaths(string $url): void
    {
        self::assertFalse($this->callIsExternalUrl($url));
    }

    public static function provideExternalUrls(): array
    {
        return [
            'http'              => ['http://cdn.example.com/style.css'],
            'https'             => ['https://fonts.googleapis.com/css2'],
            'protocol-relative' => ['//cdn.example.com/app.js'],
        ];
    }

    public static function provideLocalUrls(): array
    {
        return [
            'EXT: notation'     => ['EXT:my_ext/Resources/Public/Css/main.css'],
            'root-relative'     => ['/typo3conf/ext/my_ext/style.css'],
            'relative'          => ['Resources/Public/Css/style.css'],
            'typo3temp'         => ['typo3temp/assets/maispace_assets/css/abc123.css'],
        ];
    }

    // -------------------------------------------------------------------------
    // buildIdentifier
    // -------------------------------------------------------------------------

    public function testBuildIdentifierUsesExplicitIdentifierWhenProvided(): void
    {
        $result = $this->callBuildIdentifier('my-custom-id', null, 'body {}', 'css');
        self::assertSame('my-custom-id', $result);
    }

    public function testBuildIdentifierIgnoresEmptyExplicitIdentifier(): void
    {
        $result = $this->callBuildIdentifier('', null, 'body {}', 'css');
        // Falls back to hash-based identifier — should not be an empty string
        self::assertNotSame('', $result);
    }

    public function testBuildIdentifierWithSrcDerivesSamePrefixAndType(): void
    {
        $result = $this->callBuildIdentifier(null, 'EXT:test/Resources/Public/Css/style.css', '', 'css');
        // Without TypoScript the prefix defaults to 'maispace_'
        self::assertStringStartsWith('maispace_css_', $result);
    }

    public function testBuildIdentifierIsDeterministicForSameSrc(): void
    {
        $src = 'EXT:test/style.css';
        $a = $this->callBuildIdentifier(null, $src, '', 'css');
        $b = $this->callBuildIdentifier(null, $src, '', 'css');
        self::assertSame($a, $b);
    }

    public function testBuildIdentifierDifferentSrcsProduceDifferentIdentifiers(): void
    {
        $a = $this->callBuildIdentifier(null, 'EXT:a/style.css', '', 'css');
        $b = $this->callBuildIdentifier(null, 'EXT:b/style.css', '', 'css');
        self::assertNotSame($a, $b);
    }

    public function testBuildIdentifierUsesContentHashWhenSrcIsNull(): void
    {
        $content = 'body { color: red; }';
        $result = $this->callBuildIdentifier(null, null, $content, 'css');
        // Expect the hash portion to match md5 of the content
        self::assertStringEndsWith(md5($content), $result);
    }

    public function testBuildIdentifierDifferentTypesProduceDifferentIdentifiers(): void
    {
        $src = 'EXT:test/asset';
        $css = $this->callBuildIdentifier(null, $src, '', 'css');
        $js = $this->callBuildIdentifier(null, $src, '', 'js');
        self::assertNotSame($css, $js);
    }

    // -------------------------------------------------------------------------
    // buildIntegrityAttrs
    // -------------------------------------------------------------------------

    public function testBuildIntegrityAttrsReturnsEmptyArrayWhenIntegrityFalse(): void
    {
        $result = $this->callBuildIntegrityAttrs(['integrity' => false], 'body {}');
        self::assertSame([], $result);
    }

    public function testBuildIntegrityAttrsReturnsEmptyArrayWhenIntegrityAbsent(): void
    {
        $result = $this->callBuildIntegrityAttrs([], 'body {}');
        self::assertSame([], $result);
    }

    public function testBuildIntegrityAttrsReturnsSha384Hash(): void
    {
        $content = 'body { color: red; }';
        $result = $this->callBuildIntegrityAttrs(['integrity' => true], $content);
        $expected = 'sha384-' . base64_encode(hash('sha384', $content, true));

        self::assertArrayHasKey('integrity', $result);
        self::assertSame($expected, $result['integrity']);
    }

    public function testBuildIntegrityAttrsDefaultsToAnonymousCrossorigin(): void
    {
        $result = $this->callBuildIntegrityAttrs(['integrity' => true], 'body {}');
        self::assertSame('anonymous', $result['crossorigin']);
    }

    public function testBuildIntegrityAttrsRespectsExplicitCrossorigin(): void
    {
        $result = $this->callBuildIntegrityAttrs(
            ['integrity' => true, 'crossorigin' => 'use-credentials'],
            'body {}',
        );
        self::assertSame('use-credentials', $result['crossorigin']);
    }

    public function testBuildIntegrityAttrsDifferentContentProducesDifferentHash(): void
    {
        $a = $this->callBuildIntegrityAttrs(['integrity' => true], 'body { color: red; }');
        $b = $this->callBuildIntegrityAttrs(['integrity' => true], 'body { color: blue; }');
        self::assertNotSame($a['integrity'], $b['integrity']);
    }

    // -------------------------------------------------------------------------
    // resolveFlag
    // -------------------------------------------------------------------------

    public function testResolveFlagReturnsTrueWhenArgumentIsTrue(): void
    {
        self::assertTrue($this->callResolveFlag('minify', true, 'css'));
    }

    public function testResolveFlagReturnsFalseWhenArgumentIsFalse(): void
    {
        self::assertFalse($this->callResolveFlag('minify', false, 'css'));
    }

    public function testResolveFlagReturnsFalseWhenArgumentIsNullAndNoTypoScript(): void
    {
        // Without TYPO3_REQUEST in globals, the TypoScript lookup returns the default (false).
        $result = $this->callResolveFlag('minify', null, 'css');
        self::assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // Reflection helpers
    // -------------------------------------------------------------------------

    private function createService(): AssetProcessingService
    {
        return new AssetProcessingService(
            $this->createMock(AssetCacheManager::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(AssetCollector::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ScssCompilerService::class),
        );
    }

    private function callIsExternalUrl(string $src): bool
    {
        $method = new \ReflectionMethod(AssetProcessingService::class, 'isExternalUrl');
        $method->setAccessible(true);

        return $method->invoke($this->createService(), $src);
    }

    private function callBuildIdentifier(
        ?string $explicit,
        ?string $src,
        string $content,
        string $type,
    ): string {
        $method = new \ReflectionMethod(AssetProcessingService::class, 'buildIdentifier');
        $method->setAccessible(true);
        $request = $this->createMock(ServerRequestInterface::class);

        return $method->invoke($this->createService(), $request, $explicit, $src, $content, $type);
    }

    private function callBuildIntegrityAttrs(array $arguments, string $content): array
    {
        $method = new \ReflectionMethod(AssetProcessingService::class, 'buildIntegrityAttrs');
        $method->setAccessible(true);

        return $method->invoke($this->createService(), $arguments, $content);
    }

    private function callResolveFlag(string $setting, ?bool $argumentValue, string $section): bool
    {
        $method = new \ReflectionMethod(AssetProcessingService::class, 'resolveFlag');
        $method->setAccessible(true);
        $request = $this->createMock(ServerRequestInterface::class);

        return $method->invoke($this->createService(), $request, $setting, $argumentValue, $section);
    }
}
