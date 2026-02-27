<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Cache;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Unit tests for AssetCacheManager key-building methods.
 *
 * These tests only exercise the pure key-derivation logic — no TYPO3 bootstrap
 * or database connection is required.
 */
final class AssetCacheManagerTest extends TestCase
{
    private FrontendInterface&\PHPUnit\Framework\MockObject\MockObject $frontend;
    private AssetCacheManager $subject;

    protected function setUp(): void
    {
        $this->frontend = $this->createMock(FrontendInterface::class);

        $cacheManagerMock = $this->createMock(CacheManager::class);
        $cacheManagerMock->method('getCache')
            ->with('maispace_assets')
            ->willReturn($this->frontend);

        $this->subject = new AssetCacheManager($cacheManagerMock);
    }

    // -------------------------------------------------------------------------
    // Delegation to FrontendInterface
    // -------------------------------------------------------------------------

    public function testHasDelegatesToFrontend(): void
    {
        $this->frontend->expects(self::once())
            ->method('has')
            ->with('my-key')
            ->willReturn(true);

        self::assertTrue($this->subject->has('my-key'));
    }

    public function testGetDelegatesToFrontend(): void
    {
        $this->frontend->expects(self::once())
            ->method('get')
            ->with('my-key')
            ->willReturn('cached-value');

        self::assertSame('cached-value', $this->subject->get('my-key'));
    }

    public function testSetDelegatesToFrontend(): void
    {
        $this->frontend->expects(self::once())
            ->method('set')
            ->with('my-key', 'data', ['tag1'], 300);

        $this->subject->set('my-key', 'data', ['tag1'], 300);
    }

    public function testFlushByTagDelegatesToFrontend(): void
    {
        $this->frontend->expects(self::once())
            ->method('flushByTag')
            ->with('maispace_assets_css');

        $this->subject->flushByTag('maispace_assets_css');
    }

    // -------------------------------------------------------------------------
    // CSS key
    // -------------------------------------------------------------------------

    public function testBuildCssKeyMinifiedVariantStartsWithCssPrefix(): void
    {
        $key = $this->subject->buildCssKey('my-identifier', true);
        self::assertStringStartsWith('css_', $key);
    }

    public function testBuildCssKeyRawVariantStartsWithCssPrefix(): void
    {
        $key = $this->subject->buildCssKey('my-identifier', false);
        self::assertStringStartsWith('css_', $key);
    }

    public function testBuildCssKeyMinifiedAndRawProduceDifferentKeys(): void
    {
        $min = $this->subject->buildCssKey('my-identifier', true);
        $raw = $this->subject->buildCssKey('my-identifier', false);
        self::assertNotSame($min, $raw);
    }

    public function testBuildCssKeyIsDeterministic(): void
    {
        $a = $this->subject->buildCssKey('hello', true);
        $b = $this->subject->buildCssKey('hello', true);
        self::assertSame($a, $b);
    }

    public function testBuildCssKeyDifferentIdentifiersProduceDifferentKeys(): void
    {
        $a = $this->subject->buildCssKey('a', true);
        $b = $this->subject->buildCssKey('b', true);
        self::assertNotSame($a, $b);
    }

    public function testBuildCssKeyMatchesExpectedHash(): void
    {
        $expected = 'css_' . sha1('my-identifier_min');
        self::assertSame($expected, $this->subject->buildCssKey('my-identifier', true));
    }

    // -------------------------------------------------------------------------
    // JS key
    // -------------------------------------------------------------------------

    public function testBuildJsKeyStartsWithJsPrefix(): void
    {
        $key = $this->subject->buildJsKey('my-identifier', false);
        self::assertStringStartsWith('js_', $key);
    }

    public function testBuildJsKeyMinifiedAndRawProduceDifferentKeys(): void
    {
        $min = $this->subject->buildJsKey('my-identifier', true);
        $raw = $this->subject->buildJsKey('my-identifier', false);
        self::assertNotSame($min, $raw);
    }

    public function testBuildJsKeyMatchesExpectedHash(): void
    {
        $expected = 'js_' . sha1('my-identifier_raw');
        self::assertSame($expected, $this->subject->buildJsKey('my-identifier', false));
    }

    // -------------------------------------------------------------------------
    // SCSS key
    // -------------------------------------------------------------------------

    public function testBuildScssKeyStartsWithScssPrefix(): void
    {
        $key = $this->subject->buildScssKey('my-identifier');
        self::assertStringStartsWith('scss_', $key);
    }

    public function testBuildScssKeyWithMtimeDiffersFromInline(): void
    {
        $file = $this->subject->buildScssKey('id', 1234567890);
        $inline = $this->subject->buildScssKey('id', null);
        self::assertNotSame($file, $inline);
    }

    public function testBuildScssKeyDifferentMtimesProduceDifferentKeys(): void
    {
        $a = $this->subject->buildScssKey('id', 100);
        $b = $this->subject->buildScssKey('id', 200);
        self::assertNotSame($a, $b);
    }

    public function testBuildScssKeyFileVariantMatchesExpectedHash(): void
    {
        $mtime = 1700000000;
        $expected = 'scss_' . sha1('my-id_file_' . $mtime);
        self::assertSame($expected, $this->subject->buildScssKey('my-id', $mtime));
    }

    public function testBuildScssKeyInlineVariantMatchesExpectedHash(): void
    {
        $expected = 'scss_' . sha1('my-id_inline');
        self::assertSame($expected, $this->subject->buildScssKey('my-id', null));
    }

    // -------------------------------------------------------------------------
    // SVG sprite key
    // -------------------------------------------------------------------------

    public function testBuildSpriteKeyStartsWithSvgSpritePrefix(): void
    {
        $key = $this->subject->buildSpriteKey(['icon-a']);
        self::assertStringStartsWith('svg_sprite_', $key);
    }

    public function testBuildSpriteKeySortsSymbolsBeforeHashing(): void
    {
        $a = $this->subject->buildSpriteKey(['icon-b', 'icon-a', 'icon-c']);
        $b = $this->subject->buildSpriteKey(['icon-a', 'icon-b', 'icon-c']);
        self::assertSame($a, $b);
    }

    public function testBuildSpriteKeyDifferentSymbolsProduceDifferentKeys(): void
    {
        $a = $this->subject->buildSpriteKey(['icon-a']);
        $b = $this->subject->buildSpriteKey(['icon-b']);
        self::assertNotSame($a, $b);
    }

    public function testBuildSpriteKeyMatchesExpectedHash(): void
    {
        $expected = 'svg_sprite_' . sha1('icon-a|icon-b');
        self::assertSame($expected, $this->subject->buildSpriteKey(['icon-b', 'icon-a']));
    }

    public function testBuildSpriteKeyEmptyArray(): void
    {
        $key = $this->subject->buildSpriteKey([]);
        self::assertStringStartsWith('svg_sprite_', $key);
        // Consistent for empty input
        self::assertSame($key, $this->subject->buildSpriteKey([]));
    }
}
