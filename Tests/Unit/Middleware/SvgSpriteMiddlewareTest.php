<?php

declare(strict_types = 1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Cache\AssetCacheManager;
use Maispace\MaiAssets\Middleware\SvgSpriteMiddleware;
use Maispace\MaiAssets\Registry\SpriteIconRegistry;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SvgSpriteMiddleware.
 *
 * Tests focus on:
 * - parseAcceptEncoding: RFC 7231 q-value filtering and case-insensitivity
 * - Compression flag behaviour (enable / brotli / gzip)
 * - Conditional GET (If-None-Match → 304) unaffected by compression state
 * - Non-sprite paths pass through to the handler
 *
 * SpriteIconRegistry is final and has no interface, so it is constructed with
 * mocked dependencies. Auto-discovery is bypassed via Reflection so no TYPO3
 * bootstrap is required.
 */
final class SvgSpriteMiddlewareTest extends TestCase
{
    private const SPRITE_PATH = '/maispace/sprite.svg';

    // =========================================================================
    // parseAcceptEncoding — tested via Reflection (pure function)
    // =========================================================================

    /** @param array<string, true> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideAcceptEncodingCases')]
    public function testParseAcceptEncoding(string $header, array $expected): void
    {
        $parsed = $this->callParseAcceptEncoding($header);
        self::assertSame($expected, $parsed);
    }

    /** @return array<string, array{string, array<string, true>}> */
    public static function provideAcceptEncodingCases(): array
    {
        return [
            'empty header'            => ['', []],
            'br only'                 => ['br', ['br' => true]],
            'gzip only'               => ['gzip', ['gzip' => true]],
            'both'                    => ['br, gzip', ['br' => true, 'gzip' => true]],
            'br rejected with q=0'    => ['br;q=0, gzip', ['gzip' => true]],
            'gzip rejected with q=0'  => ['br, gzip;q=0', ['br' => true]],
            'q=0.5 accepted (>0)'     => ['gzip;q=0.5', ['gzip' => true]],
            'case-insensitive GZip'   => ['GZip', ['gzip' => true]],
            'case-insensitive BR'     => ['BR', ['br' => true]],
            'whitespace around comma' => ['br , gzip', ['br' => true, 'gzip' => true]],
            'Q parameter uppercase'   => ['br;Q=0', []],
            'identity token ignored'  => ['gzip, identity', ['gzip' => true, 'identity' => true]],
        ];
    }

    // =========================================================================
    // Full process() path — compression negotiation
    // =========================================================================

    public function testPlainResponseWhenNoAcceptEncoding(): void
    {
        $response = $this->runProcess(acceptEncoding: '', tsCompression: ['enable' => 1, 'brotli' => 1, 'gzip' => 1]);
        self::assertSame('', $response->getHeaderLine('Content-Encoding'));
    }

    public function testPlainResponseWhenCompressionDisabled(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'br, gzip',
            tsCompression: ['enable' => 0],
        );
        self::assertSame('', $response->getHeaderLine('Content-Encoding'));
    }

    public function testGzipSelectedWhenBrDisabledButGzipAccepted(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'br, gzip',
            tsCompression: ['enable' => 1, 'brotli' => 0, 'gzip' => 1],
        );
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testPlainResponseWhenGzipAcceptedButGzipDisabled(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'gzip',
            tsCompression: ['enable' => 1, 'brotli' => 0, 'gzip' => 0],
        );
        self::assertSame('', $response->getHeaderLine('Content-Encoding'));
    }

    public function testGzipSelectedWhenOnlyGzipAccepted(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'gzip',
            tsCompression: ['enable' => 1, 'brotli' => 1, 'gzip' => 1],
        );
        // br not in Accept-Encoding → falls through to gzip
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    /**
     * RFC 7231 §5.3.4: q=0 means "not acceptable". br;q=0 must not be selected.
     */
    public function testBrWithQZeroIsNotAccepted(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'br;q=0, gzip',
            tsCompression: ['enable' => 1, 'brotli' => 1, 'gzip' => 1],
        );
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testGzipWithQZeroIsNotAccepted(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'gzip;q=0',
            tsCompression: ['enable' => 1, 'brotli' => 0, 'gzip' => 1],
        );
        self::assertSame('', $response->getHeaderLine('Content-Encoding'));
    }

    public function testAcceptEncodingIsCaseInsensitive(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'GZip',
            tsCompression: ['enable' => 1, 'brotli' => 0, 'gzip' => 1],
        );
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testVaryHeaderIsAlwaysSet(): void
    {
        $response = $this->runProcess(acceptEncoding: '', tsCompression: ['enable' => 0]);
        self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    // =========================================================================
    // Conditional GET — 304 unaffected by compression settings
    // =========================================================================

    public function testIfNoneMatchIgnoredWhenEtagDiffers(): void
    {
        $response = $this->runProcess(
            acceptEncoding: 'gzip',
            tsCompression: ['enable' => 1, 'brotli' => 0, 'gzip' => 1],
            ifNoneMatch: '"stale-etag"',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    // =========================================================================
    // Non-sprite paths pass through
    // =========================================================================

    public function testNonSpritePathPassesThroughToHandler(): void
    {
        $registry = $this->buildRegistry();
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->expects(self::never())->method('createResponse');

        $middleware = new SvgSpriteMiddleware($registry, $responseFactory);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/some/other/page');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        $handlerResponse = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn($handlerResponse);

        $result = $middleware->process($request, $handler);
        self::assertSame($handlerResponse, $result);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a SpriteIconRegistry with mocked dependencies and auto-discovery
     * bypassed via Reflection so no TYPO3 bootstrap is required.
     * Without any registered symbols, buildSprite() returns '' and the middleware
     * falls back to the empty-SVG sentinel string.
     */
    private function buildRegistry(): SpriteIconRegistry
    {
        $registry = new SpriteIconRegistry(
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(AssetCacheManager::class),
            $this->createMock(LoggerInterface::class),
        );

        // Mark discovery as complete so ExtensionManagementUtility is never called.
        $prop = new \ReflectionProperty(SpriteIconRegistry::class, 'discovered');
        $prop->setAccessible(true);
        $prop->setValue($registry, true);

        return $registry;
    }

    /**
     * Run the middleware against a fake sprite request and return the response.
     *
     * @param array<string, int> $tsCompression Values for plugin.tx_maispace_assets.compression.*
     */
    private function runProcess(
        string $acceptEncoding,
        array $tsCompression,
        string $ifNoneMatch = '',
    ): ResponseInterface {
        $compressionDot = [];
        foreach ($tsCompression as $key => $value) {
            $compressionDot[$key] = (string)$value;
        }

        $typoScript = new class($compressionDot) {
            /** @param array<string, string> $compression */
            public function __construct(private readonly array $compression)
            {
            }

            /** @return array<string, mixed> */
            public function getSetupArray(): array
            {
                return [
                    'plugin.' => [
                        'tx_maispace_assets.' => [
                            'compression.' => $this->compression,
                        ],
                    ],
                ];
            }
        };

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn(self::SPRITE_PATH);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturnMap([
            ['frontend.typoscript', $typoScript],
            ['site', null],
        ]);
        $request->method('getHeaderLine')->willReturnMap([
            ['Accept-Encoding', $acceptEncoding],
            ['If-None-Match', $ifNoneMatch],
        ]);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturnCallback(
            static fn (int $status) => new InMemoryResponse($status),
        );

        $middleware = new SvgSpriteMiddleware($this->buildRegistry(), $responseFactory);
        $handler = $this->createMock(RequestHandlerInterface::class);

        return $middleware->process($request, $handler);
    }

    /** @return array<string, true> */
    private function callParseAcceptEncoding(string $header): array
    {
        $method = new \ReflectionMethod(SvgSpriteMiddleware::class, 'parseAcceptEncoding');
        $method->setAccessible(true);

        $middleware = new SvgSpriteMiddleware(
            $this->buildRegistry(),
            $this->createMock(ResponseFactoryInterface::class),
        );

        /** @var array<string, true> */
        return $method->invoke($middleware, $header);
    }
}

/**
 * Minimal in-memory PSR-7 response for testing.
 * Tracks headers and status without requiring a full HTTP library.
 */
final class InMemoryResponse implements ResponseInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    public string $bodyContent = '';

    public function __construct(private int $status = 200)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus($code, $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->status = $code;

        return $clone;
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->headers[strtolower($name)] ?? []);
    }

    public function withHeader($name, $value): static
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = (array)$value;

        return $clone;
    }

    public function withAddedHeader($name, $value): static
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)][] = $value;

        return $clone;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader($name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function withoutHeader($name): static
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        $owner = $this;

        return new class($owner) implements StreamInterface {
            public function __construct(private readonly InMemoryResponse $owner)
            {
            }

            public function write($string): int
            {
                $this->owner->bodyContent .= $string;

                return strlen($string);
            }

            public function __toString(): string
            {
                return $this->owner->bodyContent;
            }
            public function close(): void
            {
            }
            public function detach(): mixed
            {
                return null;
            }
            public function getSize(): ?int
            {
                return strlen($this->owner->bodyContent);
            }
            public function tell(): int
            {
                return 0;
            }
            public function eof(): bool
            {
                return true;
            }
            public function isSeekable(): bool
            {
                return false;
            }
            public function seek($offset, $whence = SEEK_SET): void
            {
            }
            public function rewind(): void
            {
            }
            public function isWritable(): bool
            {
                return true;
            }
            public function isReadable(): bool
            {
                return true;
            }
            public function read($length): string
            {
                return $this->owner->bodyContent;
            }
            public function getContents(): string
            {
                return $this->owner->bodyContent;
            }
            public function getMetadata($key = null): mixed
            {
                return null;
            }
        };
    }

    public function withBody(StreamInterface $body): static
    {
        return $this;
    }
    public function getProtocolVersion(): string
    {
        return '1.1';
    }
    public function withProtocolVersion($version): static
    {
        return $this;
    }
    public function getReasonPhrase(): string
    {
        return '';
    }
}
