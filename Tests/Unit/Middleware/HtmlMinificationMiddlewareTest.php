<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Middleware\HtmlMinificationMiddleware;
use Maispace\MaiAssets\Service\HtmlMinificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;

final class HtmlMinificationMiddlewareTest extends TestCase
{
    private HtmlMinificationService $minifier;
    private StreamFactoryInterface&MockObject $streamFactory;
    private RequestHandlerInterface&MockObject $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->minifier = new HtmlMinificationService();
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(
            static function (string $content): StreamInterface {
                $stream = new Stream('php://temp', 'r+');
                $stream->write($content);
                $stream->rewind();

                return $stream;
            }
        );
        $this->handler = $this->createMock(RequestHandlerInterface::class);
    }

    public function testSkipsNonHtmlResponses(): void
    {
        $response = $this->createResponse('{"ok":true}', 'application/json');
        $this->handler->method('handle')->willReturn($response);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('frontend.typoscript')->willReturn(null);

        $result = $this->middleware()->process($request, $this->handler);

        self::assertSame($response, $result);
    }

    public function testSkipsWhenDisabledInTypoScript(): void
    {
        $html = "<div>\n<p>Hi</p>\n</div>";
        $response = $this->createResponse($html, 'text/html; charset=utf-8');
        $this->handler->method('handle')->willReturn($response);

        $request = $this->createRequestWithSettings(['enable' => '0']);

        $result = $this->middleware()->process($request, $this->handler);

        self::assertSame($response, $result);
    }

    public function testMinifiesFinalHtmlIncludingLateInjectedTags(): void
    {
        $html = "<!DOCTYPE html>\n<html>\n<head>\n"
            . "<meta name=\"viewport\" content=\"width=device-width\">\n"
            . "<meta property=\"og:title\" content=\"Suche\">\n"
            . "</head>\n<body>\n"
            . "<nav class=\"menu\"\ndata-testid=\"x\">Link</nav>\n"
            . "<script type=\"application/ld+json\">\n{\"@type\":\"WebPage\"}\n</script>\n"
            . "</body>\n</html>";

        $response = $this->createResponse($html, 'text/html; charset=utf-8');
        $response->method('hasHeader')->with('Content-Length')->willReturn(false);
        $response->method('withBody')->willReturnCallback(
            function (StreamInterface $body): ResponseInterface {
                $new = $this->createMock(ResponseInterface::class);
                $new->method('getBody')->willReturn($body);
                $new->method('hasHeader')->willReturn(false);
                $new->method('getHeaderLine')->willReturn('text/html; charset=utf-8');

                return $new;
            }
        );
        $this->handler->method('handle')->willReturn($response);

        $request = $this->createRequestWithSettings([
            'enable' => '1',
            'stripComments' => '1',
            'preserveTags' => 'pre,code,textarea',
        ]);

        $result = $this->middleware()->process($request, $this->handler);
        $body = $result->getBody();
        $body->rewind();
        $minified = $body->getContents();

        self::assertStringNotContainsString("content=\"width=device-width\">\n", $minified);
        self::assertStringContainsString(
            '<meta name="viewport" content="width=device-width"> <meta property="og:title" content="Suche">',
            $minified
        );
        self::assertStringContainsString('<nav class="menu" data-testid="x">', $minified);
        self::assertStringContainsString('{"@type":"WebPage"}', $minified);
    }

    private function middleware(): HtmlMinificationMiddleware
    {
        return new HtmlMinificationMiddleware($this->minifier, $this->streamFactory);
    }

    /**
     * @param array<string, string> $settings
     */
    private function createRequestWithSettings(array $settings): ServerRequestInterface
    {
        $typoScript = new class ($settings) {
            /**
             * @param array<string, string> $settings
             */
            public function __construct(private array $settings) {}

            /**
             * @return array<string, mixed>
             */
            public function getSetupArray(): array
            {
                return [
                    'plugin.' => [
                        'tx_maispace_assets.' => [
                            'settings.' => [
                                'htmlMinification.' => $this->settings,
                            ],
                        ],
                    ],
                ];
            }
        };

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('frontend.typoscript')->willReturn($typoScript);

        return $request;
    }

    private function createResponse(string $body, string $contentType): ResponseInterface&MockObject
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write($body);
        $stream->rewind();

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaderLine')->with('Content-Type')->willReturn($contentType);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
