<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Registry;

use Maispace\MaispaceAssets\Cache\AssetCacheManager;
use Maispace\MaispaceAssets\Exception\AssetCompilationException;
use Maispace\MaispaceAssets\Registry\SpriteIconRegistry;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies that SpriteIconRegistry throws typed exceptions for invalid SVG content.
 *
 * The private extractSymbol() method is exercised via PHP Reflection so we can
 * test the parsing logic without requiring a full TYPO3 bootstrap or real files.
 */
final class SpriteIconRegistryExceptionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function callExtractSymbol(string $svgContent, string $symbolId): string
    {
        $method = new \ReflectionMethod(SpriteIconRegistry::class, 'extractSymbol');
        $method->setAccessible(true);

        $subject = new SpriteIconRegistry(
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(AssetCacheManager::class),
            $this->createMock(LoggerInterface::class),
        );

        return $method->invoke($subject, $svgContent, $symbolId);
    }

    // -------------------------------------------------------------------------
    // extractSymbol — valid SVG
    // -------------------------------------------------------------------------

    public function testExtractSymbolReturnsSymbolTagForValidSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0"/></svg>';
        $result = $this->callExtractSymbol($svg, 'icon-arrow');

        self::assertStringContainsString('<symbol', $result);
        self::assertStringContainsString('id="icon-arrow"', $result);
        self::assertStringContainsString('viewBox="0 0 24 24"', $result);
        self::assertStringContainsString('<path d="M0 0"/>', $result);
    }

    public function testExtractSymbolStripsXmlDeclaration(): void
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"><circle/></svg>';
        $result = $this->callExtractSymbol($svg, 'icon-circle');

        self::assertStringNotContainsString('<?xml', $result);
        self::assertStringContainsString('<symbol', $result);
    }

    public function testExtractSymbolStripsHtmlComments(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><!-- a comment --><path/></svg>';
        $result = $this->callExtractSymbol($svg, 'icon-path');

        self::assertStringNotContainsString('<!-- a comment -->', $result);
        self::assertStringContainsString('<path/>', $result);
    }

    public function testExtractSymbolOmitsViewBoxAttributeWhenAbsent(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
        $result = $this->callExtractSymbol($svg, 'icon-rect');

        self::assertStringNotContainsString('viewBox', $result);
    }

    // -------------------------------------------------------------------------
    // extractSymbol — malformed SVG → AssetCompilationException
    // -------------------------------------------------------------------------

    public function testExtractSymbolThrowsForEmptyString(): void
    {
        $this->expectException(AssetCompilationException::class);
        $this->expectExceptionMessageMatches('/Could not parse SVG structure/');

        $this->callExtractSymbol('', 'icon-missing');
    }

    public function testExtractSymbolThrowsForPlainText(): void
    {
        $this->expectException(AssetCompilationException::class);
        $this->expectExceptionMessageMatches('/Could not parse SVG structure/');

        $this->callExtractSymbol('not svg content at all', 'icon-bad');
    }

    public function testExtractSymbolThrowsForHtmlWithoutSvgTag(): void
    {
        $this->expectException(AssetCompilationException::class);
        $this->expectExceptionMessageMatches('/Could not parse SVG structure/');

        $this->callExtractSymbol('<html><body><p>No SVG here</p></body></html>', 'icon-html');
    }

    public function testExtractSymbolThrowsForUnclosedSvgTag(): void
    {
        $this->expectException(AssetCompilationException::class);
        $this->expectExceptionMessageMatches('/Could not parse SVG structure/');

        $this->callExtractSymbol('<svg xmlns="http://www.w3.org/2000/svg"><path/>', 'icon-unclosed');
    }

    public function testExceptionMessageContainsSymbolId(): void
    {
        try {
            $this->callExtractSymbol('no-svg-here', 'my-icon-id');
            self::fail('Expected AssetCompilationException was not thrown.');
        } catch (AssetCompilationException $e) {
            self::assertStringContainsString('my-icon-id', $e->getMessage());
        }
    }
}
