<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use Maispace\MaiAssets\Service\HtmlMinificationService;
use PHPUnit\Framework\TestCase;

final class HtmlMinificationServiceTest extends TestCase
{
    private HtmlMinificationService $subject;

    protected function setUp(): void
    {
        $this->subject = new HtmlMinificationService();
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->subject->minify('', []));
    }

    public function testCollapseWhitespaceBetweenTags(): void
    {
        $html = "<div>   \n   <p>Hello</p>   \n   </div>";
        $result = $this->subject->minify($html, ['stripComments' => false]);
        self::assertStringNotContainsString('   ', $result);
        self::assertStringContainsString('<p>Hello</p>', $result);
    }

    public function testStripHtmlComments(): void
    {
        $html = '<div><!-- this is a comment --><p>Text</p></div>';
        $result = $this->subject->minify($html, ['stripComments' => true]);
        self::assertStringNotContainsString('<!-- this is a comment -->', $result);
        self::assertStringContainsString('<p>Text</p>', $result);
    }

    public function testPreservesTypo3InternalComments(): void
    {
        $markers = [
            '<!--INT_SCRIPT.abc-->',
            '<!--HD_-->',
            '<!--CSS_INCLUDE_-->',
            '<!--JS_LIBS-->',
            '<!--TYPO3SEARCH_begin-->',
            '<!--TYPO3SEARCH_end-->',
        ];

        foreach ($markers as $marker) {
            $html = '<div>' . $marker . '<p>Text</p></div>';
            $result = $this->subject->minify($html, ['stripComments' => true]);
            self::assertStringContainsString($marker, $result, "Marker not preserved: $marker");
        }
    }

    public function testPreservesSectionMarkers(): void
    {
        $html = '<div><!-- ###MY_SECTION### --><p>Text</p></div>';
        $result = $this->subject->minify($html, ['stripComments' => true]);
        self::assertStringContainsString('<!-- ###MY_SECTION###', $result);
    }

    public function testProtectsScriptBlocks(): void
    {
        $html = '<html><script>var x =   1 +   2;</script><p>Text</p></html>';
        $result = $this->subject->minify($html, ['stripComments' => true]);
        self::assertStringContainsString('var x =   1 +   2;', $result);
    }

    public function testProtectsStyleBlocks(): void
    {
        $html = '<html><style>body   {   color:   red; }</style><p>Text</p></html>';
        $result = $this->subject->minify($html, ['stripComments' => true]);
        self::assertStringContainsString('body   {   color:   red; }', $result);
    }

    public function testProtectsPreBlocks(): void
    {
        $html = '<div><pre>  indented   code  </pre></div>';
        $result = $this->subject->minify($html, ['stripComments' => false, 'preserveTags' => 'pre']);
        self::assertStringContainsString('  indented   code  ', $result);
    }

    public function testDoesNotStripCommentsWhenDisabled(): void
    {
        $html = '<div><!-- keep me --><p>Text</p></div>';
        $result = $this->subject->minify($html, ['stripComments' => false]);
        self::assertStringContainsString('<!-- keep me -->', $result);
    }

    public function testEmptyLinesAreDiscarded(): void
    {
        $html = "<div>\n\n\n<p>Text</p>\n\n\n</div>";
        $result = $this->subject->minify($html, ['stripComments' => false]);
        // Should not have consecutive empty lines
        self::assertStringNotContainsString("\n\n", $result);
    }

    public function testCustomPreserveTagsAreRespected(): void
    {
        $html = '<div><code>  spaced   code  </code></div>';
        $result = $this->subject->minify($html, ['stripComments' => false, 'preserveTags' => 'code']);
        self::assertStringContainsString('  spaced   code  ', $result);
    }
}
