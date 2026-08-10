<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\Service\HtmlMinificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Minifies the final HTML response after PageRenderer substitutions and INT
 * content have been inlined.
 *
 * {@see HtmlMinificationListener} already shrinks cacheable page content, but
 * meta tags, CSS/JS includes and USER_INT/COA_INT output are injected afterwards
 * via PageRenderer placeholders — those still carry newlines. This middleware
 * runs a second pass on the completed ``text/html`` body.
 */
final readonly class HtmlMinificationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HtmlMinificationService $htmlMinificationService,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $tsSettings = $request->getAttribute('frontend.typoscript')
            ?->getSetupArray()['plugin.']['tx_maispace_assets.']['settings.']['htmlMinification.']
            ?? [];

        if (empty($tsSettings['enable'])) {
            return $response;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $html = $body->getContents();

        if ($html === '') {
            return $response;
        }

        $minified = $this->htmlMinificationService->minify($html, $tsSettings);

        if ($minified === $html) {
            return $response;
        }

        $response = $response->withBody($this->streamFactory->createStream($minified));

        if ($response->hasHeader('Content-Length')) {
            $response = $response->withHeader('Content-Length', (string)strlen($minified));
        }

        return $response;
    }
}
