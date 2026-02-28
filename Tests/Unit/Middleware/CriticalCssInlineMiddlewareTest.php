<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Tests\Unit\Middleware;

use Maispace\MaispaceAssets\Middleware\CriticalCssInlineMiddleware;
use Maispace\MaispaceAssets\Service\CriticalAssetService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Routing\PageArguments;

final class CriticalCssInlineMiddlewareTest extends TestCase
{
    public function testProcessWrapsInLayerIfConfigured(): void
    {
        $criticalAssetService = $this->createMock(CriticalAssetService::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $context = $this->createMock(Context::class);

        $criticalAssetService->method('getCriticalCss')
            ->willReturnCallback(fn ($pageUid, $viewport) => $viewport === 'mobile' ? 'body { color: red; }' : null);

        $middleware = new CriticalCssInlineMiddleware($criticalAssetService, $streamFactory, $context);

        $request = $this->createMock(ServerRequestInterface::class);
        $routing = $this->createMock(PageArguments::class);
        $routing->method('getPageId')->willReturn(123);

        $typoScript = new class {
            public function getSetupArray(): array
            {
                return [
                    'plugin.' => [
                        'tx_maispace_assets.' => [
                            'criticalCss.' => [
                                'layer' => 'test-layer',
                            ],
                        ],
                    ],
                ];
            }
        };

        $request->method('getAttribute')->willReturnMap([
            ['routing', $routing],
            ['frontend.typoscript', $typoScript],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaderLine')->with('Content-Type')->willReturn('text/html');
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('<html><head></head><body></body></html>');
        $response->method('getBody')->willReturn($body);
        $response->method('withBody')->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function ($content) use ($stream) {
            $this->assertStringContainsString('@layer test-layer {body { color: red; }}', $content);

            return $stream;
        });

        $middleware->process($request, $handler);
    }

    public function testProcessDoesNotWrapIfLayerNotConfigured(): void
    {
        $criticalAssetService = $this->createMock(CriticalAssetService::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $context = $this->createMock(Context::class);

        $criticalAssetService->method('getCriticalCss')
            ->willReturnCallback(fn ($pageUid, $viewport) => $viewport === 'mobile' ? 'body { color: red; }' : null);

        $middleware = new CriticalCssInlineMiddleware($criticalAssetService, $streamFactory, $context);

        $request = $this->createMock(ServerRequestInterface::class);
        $routing = $this->createMock(PageArguments::class);
        $routing->method('getPageId')->willReturn(123);

        $typoScript = new class {
            public function getSetupArray(): array
            {
                return [
                    'plugin.' => [
                        'tx_maispace_assets.' => [
                            'criticalCss.' => [
                                // 'layer' is missing or empty
                            ],
                        ],
                    ],
                ];
            }
        };

        $request->method('getAttribute')->willReturnMap([
            ['routing', $routing],
            ['frontend.typoscript', $typoScript],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaderLine')->with('Content-Type')->willReturn('text/html');
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('<html><head></head><body></body></html>');
        $response->method('getBody')->willReturn($body);
        $response->method('withBody')->willReturnSelf();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function ($content) use ($stream) {
            $this->assertStringNotContainsString('@layer', $content);
            $this->assertStringContainsString('body { color: red; }', $content);

            return $stream;
        });

        $middleware->process($request, $handler);
    }
}
