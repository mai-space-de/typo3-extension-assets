<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Utility;

use Maispace\MaiAssets\Utility\HtmlInjectionUtility;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;

final class HtmlInjectionUtilityTest extends TestCase
{
    public function testScriptGoesBeforeTheDocumentsOwnClosingBodyTagOnly(): void
    {
        // Regression: str_ireplace() also injected into strings that contain "</body>".
        $html = '<html><body><script>var tpl = "</body>";</script><iframe srcdoc="<p></p></BODY>"></iframe></body></html>';

        $result = HtmlInjectionUtility::beforeBodyEnd($html, '<script>x()</script>');

        self::assertSame(1, substr_count($result, 'x()'));
        self::assertStringEndsWith('<script>x()</script></body></html>', $result);
    }

    public function testHeadInsertGoesBeforeTheFirstClosingHeadTag(): void
    {
        $html = '<html><head><title>t</title></head><body><script>"</head>"</script></body></html>';

        $result = HtmlInjectionUtility::beforeHeadEnd($html, '<link rel="preconnect" href="https://cdn.example">');

        self::assertSame(1, substr_count($result, 'preconnect'));
        self::assertStringContainsString('<title>t</title><link rel="preconnect"', $result);
    }

    public function testInsertedMarkupIsNotTreatedAsReplacementPattern(): void
    {
        // Regression: preg_replace() turned "$1" and "\1" inside the SVG sprite into back references.
        $sprite = '<svg><text>$1 costs \\1</text></svg>';

        $result = HtmlInjectionUtility::afterBodyStart('<html><body class="home"><p>a</p></body></html>', $sprite);

        self::assertSame('<html><body class="home">' . $sprite . '<p>a</p></body></html>', $result);
    }

    public function testMissingTagsLeaveTheDocumentUnchanged(): void
    {
        self::assertSame('<p>fragment</p>', HtmlInjectionUtility::beforeBodyEnd('<p>fragment</p>', 'x'));
        self::assertSame('<p>fragment</p>', HtmlInjectionUtility::beforeHeadEnd('<p>fragment</p>', 'x'));
        self::assertSame('<p>fragment</p>', HtmlInjectionUtility::afterBodyStart('<p>fragment</p>', 'x'));
    }

    public function testNonceAttribute(): void
    {
        self::assertSame('', HtmlInjectionUtility::nonceAttribute(null));

        $nonce = new ConsumableNonce();
        self::assertSame(' nonce="' . $nonce->value . '"', HtmlInjectionUtility::nonceAttribute($nonce));
        self::assertCount(1, $nonce);
    }
}
