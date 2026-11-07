<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\StaticFileCache;

use Maispace\MaiAssets\StaticFileCache\StaticCacheRequestGuard;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class StaticCacheRequestGuardTest extends TestCase
{
    private function request(string $uri, ?SiteLanguage $language, array $cookies = []): ServerRequest
    {
        return (new ServerRequest(new Uri($uri), 'GET'))
            ->withAttribute('language', $language)
            ->withCookieParams($cookies);
    }

    private function language(string $base = 'https://www.example.org/'): SiteLanguage
    {
        return new SiteLanguage(0, 'de_DE.UTF-8', new Uri($base), []);
    }

    public function testMatchingHostIsShareable(): void
    {
        $subject = new StaticCacheRequestGuard();

        self::assertTrue($subject->isShareable($this->request('https://www.example.org/', $this->language())));
    }

    public function testForeignHostIsRejected(): void
    {
        $subject = new StaticCacheRequestGuard();

        self::assertFalse($subject->isShareable($this->request('https://evil.example/', $this->language())));
    }

    public function testForeignSchemeIsRejected(): void
    {
        $subject = new StaticCacheRequestGuard();

        self::assertFalse($subject->isShareable($this->request('http://www.example.org/', $this->language())));
    }

    public function testMissingLanguageIsRejected(): void
    {
        $subject = new StaticCacheRequestGuard();

        self::assertFalse($subject->isShareable($this->request('https://www.example.org/', null)));
    }

    public function testBackendUserCookieIsRejected(): void
    {
        $subject = new StaticCacheRequestGuard();

        self::assertFalse($subject->isShareable(
            $this->request('https://www.example.org/', $this->language(), ['be_typo_user' => 'abc']),
        ));
    }
}
