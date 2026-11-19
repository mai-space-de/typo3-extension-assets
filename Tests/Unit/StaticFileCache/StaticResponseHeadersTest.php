<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Maispace\MaiAssets\StaticFileCache\StaticContentSecurityPolicy;
use Maispace\MaiAssets\StaticFileCache\StaticResponseHeaders;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\HtmlResponse;

final class StaticResponseHeadersTest extends TestCase
{
    public function testDocumentHeadersAreKeptAndPerRequestHeadersDropped(): void
    {
        $stored = StaticResponseHeaders::filterForStorage([
            'X-Frame-Options' => ['SAMEORIGIN'],
            'Referrer-Policy' => ['strict-origin'],
            'Cache-Control' => ['private, no-store'],
            'Set-Cookie' => ['fe_typo_user=abc'],
            'Content-Length' => ['123'],
            'Content-Security-Policy' => ["script-src 'nonce-abc'"],
            'Content-Type' => ['text/html'],
            'Date' => ['Tue, 01 Dec 2026 10:00:00 GMT'],
        ]);

        self::assertSame([
            'X-Frame-Options' => ['SAMEORIGIN'],
            'Referrer-Policy' => ['strict-origin'],
            'Cache-Control' => ['private, no-store'],
        ], $stored);
    }

    public function testMaxAgeIsCappedToTheRemainingLifetime(): void
    {
        $headers = StaticResponseHeaders::forDelivery(
            ['Cache-Control' => ['public, max-age=86400, s-maxage=3600']],
            1000 + 600,
            1000,
        );

        self::assertSame(['public, max-age=600, s-maxage=600'], $headers['Cache-Control']);
    }

    public function testStoredNonceIsReplacedByTheNonceOfThePolicy(): void
    {
        $response = (new HtmlResponse('<script nonce="OLDnonceOLDnonce">a()</script><style nonce="OLDnonceOLDnonce"></style>'))
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-NEW_nonce-123'");

        $result = StaticContentSecurityPolicy::substituteNonce($response, 'OLDnonceOLDnonce');

        self::assertSame('<script nonce="NEW_nonce-123">a()</script><style nonce="NEW_nonce-123"></style>', (string) $result->getBody());
    }

    public function testWithoutNonceInThePolicyTheBodyStaysUntouched(): void
    {
        $response = (new HtmlResponse('<script nonce="OLD">a()</script>'))
            ->withHeader('Content-Security-Policy', "script-src 'self' 'unsafe-inline'");

        self::assertSame($response, StaticContentSecurityPolicy::substituteNonce($response, 'OLD'));
        self::assertTrue(StaticContentSecurityPolicy::hasPolicy($response));
        self::assertFalse(StaticContentSecurityPolicy::hasPolicy(new HtmlResponse('')));
    }
}
