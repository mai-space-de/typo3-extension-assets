<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\EarlyHints\EarlyHintCacheService;
use Maispace\MaiAssets\Middleware\EarlyHintsMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * EarlyHintCacheService is `final`, so its constructor is bypassed via
 * ReflectionClass::newInstanceWithoutConstructor() and the underlying
 * FrontendInterface is injected through ReflectionProperty — the same
 * technique used by AssetCriticalityResolverTest.
 *
 * Environment is initialised with a Production context in setUp() so the
 * middleware's isDevelopment() guard does not short-circuit the early-hints
 * path during the tests that exercise it.
 */
final class EarlyHintsMiddlewareTest extends TestCase
{
    private FrontendInterface&MockObject $cacheFrontend;
    private EarlyHintCacheService $cacheService;
    private RequestHandlerInterface&MockObject $handler;
    private ResponseInterface&MockObject $response;

    protected function setUp(): void
    {
        // Build the cache-service instance without its constructor so we can
        // control what load() returns without a real TYPO3 cache manager.
        $this->cacheFrontend = $this->createMock(FrontendInterface::class);

        $this->cacheService = (new \ReflectionClass(EarlyHintCacheService::class))
            ->newInstanceWithoutConstructor();
        $cacheProp = new \ReflectionProperty(EarlyHintCacheService::class, 'cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->cacheService, $this->cacheFrontend);

        // Stub handler to return a response so process() always has something to return.
        $this->response = $this->createMock(ResponseInterface::class);
        $this->handler  = $this->createMock(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn($this->response);

        // Initialise the TYPO3 environment with a Production context.
        // This prevents isDevelopment() from returning true and short-circuiting
        // the early-hints path.  The method is documented as safe to call in tests.
        Environment::initialize(
            new ApplicationContext('Production'),
            true,
            true,
            '/project',
            '/project/public',
            '/project/var',
            '/project/config',
            '/project/public/index.php',
            'UNIX',
        );
    }

    // -------------------------------------------------------------------------
    // Handler delegation
    // -------------------------------------------------------------------------

    public function testProcessReturnsResponseFromHandlerWhenPageArgumentsIsNull(): void
    {
        $request = $this->makeGetRequest(pageArguments: null, language: null);

        $result = (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    public function testProcessReturnsResponseFromHandlerWhenRequestIsPost(): void
    {
        $request = $this->makeRequest('POST', pageArguments: $this->makePageArguments(1), language: null);

        $result = (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    public function testProcessReturnsResponseFromHandlerOnHappyPath(): void
    {
        $this->cacheFrontend->method('get')->willReturn(false);
        $request = $this->makeGetRequest(pageArguments: $this->makePageArguments(1), language: null);

        $result = (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -------------------------------------------------------------------------
    // Cache skipped when conditions are not met
    // -------------------------------------------------------------------------

    public function testProcessNeverLoadsCacheWhenPageArgumentsIsNull(): void
    {
        $this->cacheFrontend->expects(self::never())->method('get');

        $request = $this->makeGetRequest(pageArguments: null, language: null);

        (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);
    }

    public function testProcessNeverLoadsCacheWhenRequestIsPost(): void
    {
        $this->cacheFrontend->expects(self::never())->method('get');

        $request = $this->makeRequest('POST', pageArguments: $this->makePageArguments(7), language: null);

        (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);
    }

    // -------------------------------------------------------------------------
    // Cache lookup with correct key (page + language UIDs)
    // -------------------------------------------------------------------------

    public function testProcessLoadsCacheWithCorrectKeyForPageAndLanguage(): void
    {
        // load() builds the cache key as "earlyhints_{pageUid}_{languageUid}".
        // Asserting on the key rather than the method arguments avoids having to
        // mock a final class at the method level.
        $this->cacheFrontend
            ->expects(self::once())
            ->method('get')
            ->with('earlyhints_42_3')
            ->willReturn(false);

        $request = $this->makeGetRequest(
            pageArguments: $this->makePageArguments(42),
            language: $this->makeLanguage(3),
        );

        (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);
    }

    public function testProcessUsesDefaultLanguageUidZeroWhenLanguageIsNull(): void
    {
        $this->cacheFrontend
            ->expects(self::once())
            ->method('get')
            ->with('earlyhints_5_0')
            ->willReturn(false);

        $request = $this->makeGetRequest(
            pageArguments: $this->makePageArguments(5),
            language: null,
        );

        (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);
    }

    // -------------------------------------------------------------------------
    // Cache with candidates
    // -------------------------------------------------------------------------

    public function testProcessCallsHandlerEvenWhenCacheReturnsCandidates(): void
    {
        // Cache returns a serialised candidate row (as stored by EarlyHintCacheService::serializeCandidates()).
        $this->cacheFrontend->method('get')->willReturn([
            ['href' => '/assets/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
        ]);

        $this->handler->expects(self::once())->method('handle')->willReturn($this->response);

        $request = $this->makeGetRequest(
            pageArguments: $this->makePageArguments(10),
            language: $this->makeLanguage(0),
        );

        $result = (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    public function testProcessCallsHandlerEvenWhenCacheIsEmpty(): void
    {
        $this->cacheFrontend->method('get')->willReturn(false);

        $this->handler->expects(self::once())->method('handle')->willReturn($this->response);

        $request = $this->makeGetRequest(
            pageArguments: $this->makePageArguments(3),
            language: $this->makeLanguage(0),
        );

        (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);
    }

    // -------------------------------------------------------------------------
    // Hetzner: no HTTP 103 — Link headers on the final 200 response
    // -------------------------------------------------------------------------

    public function testProcessAttachesLinkHeadersOnHetznerInsteadOfHttp103(): void
    {
        Environment::initialize(
            new ApplicationContext('Production/Hetzner'),
            true,
            true,
            '/project',
            '/project/public',
            '/project/var',
            '/project/config',
            '/project/public/index.php',
            'UNIX',
        );

        $this->cacheFrontend->method('get')->willReturn([
            ['href' => '/assets/style.css', 'rel' => 'preload', 'as' => 'style', 'type' => '', 'crossorigin' => ''],
            ['href' => '/assets/app.js', 'rel' => 'modulepreload', 'as' => '', 'type' => '', 'crossorigin' => ''],
        ]);

        $responseWithLink = $this->createMock(ResponseInterface::class);
        $this->response
            ->expects(self::once())
            ->method('withHeader')
            ->with(
                'Link',
                '</assets/style.css>; rel=preload; as=style, </assets/app.js>; rel=modulepreload',
            )
            ->willReturn($responseWithLink);

        $request = $this->makeGetRequest(
            pageArguments: $this->makePageArguments(1),
            language: $this->makeLanguage(0),
        );

        $result = (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);

        self::assertSame($responseWithLink, $result);
    }

    public function testProcessDoesNotAttachLinkHeadersOnHetznerWhenCacheEmpty(): void
    {
        Environment::initialize(
            new ApplicationContext('Production/Hetzner'),
            true,
            true,
            '/project',
            '/project/public',
            '/project/var',
            '/project/config',
            '/project/public/index.php',
            'UNIX',
        );

        $this->cacheFrontend->method('get')->willReturn(false);
        $this->response->expects(self::never())->method('withHeader');

        $request = $this->makeGetRequest(
            pageArguments: $this->makePageArguments(1),
            language: $this->makeLanguage(0),
        );

        $result = (new EarlyHintsMiddleware($this->cacheService))->process($request, $this->handler);

        self::assertSame($this->response, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeGetRequest(?PageArguments $pageArguments, ?SiteLanguage $language): ServerRequestInterface
    {
        return $this->makeRequest('GET', $pageArguments, $language);
    }

    private function makeRequest(
        string $method,
        ?PageArguments $pageArguments,
        ?SiteLanguage $language,
    ): ServerRequestInterface {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getAttribute')->willReturnCallback(
            static function (string $name) use ($pageArguments, $language): mixed {
                return match ($name) {
                    'routing'  => $pageArguments,
                    'language' => $language,
                    default    => null,
                };
            }
        );
        return $request;
    }

    private function makePageArguments(int $pageId): PageArguments
    {
        $pageArguments = $this->createMock(PageArguments::class);
        $pageArguments->method('getPageId')->willReturn($pageId);
        return $pageArguments;
    }

    private function makeLanguage(int $languageId): SiteLanguage
    {
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn($languageId);
        return $language;
    }
}
