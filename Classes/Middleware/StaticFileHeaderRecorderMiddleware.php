<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Maispace\MaiAssets\StaticFileCache\StaticFileWriteRegistry;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use Maispace\MaiAssets\StaticFileCache\StaticResponseHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stores the headers of the response a static file was written from
 * (config.additionalHeaders, Cache-Control, …), so the static delivery can send
 * the same headers. The file itself is written earlier, while the page is
 * generated; the final headers only exist once the response is built.
 */
final readonly class StaticFileHeaderRecorderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StaticFileWriteRegistry $registry,
        private StaticHtmlWriterService $writer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $written = $this->registry->take();
        if ($written === null || $response->getStatusCode() !== 200) {
            return $response;
        }

        try {
            $this->writer->writeMetadata($written['page'], $written['language'], $written['variant'], [
                'headers' => StaticResponseHeaders::filterForStorage($response->getHeaders()),
                'nonce' => $written['nonce'],
                'expires' => $written['expires'],
            ]);
        } catch (\Throwable) {
            // Without metadata the file is still served (without the extra headers).
        }

        return $response;
    }
}
