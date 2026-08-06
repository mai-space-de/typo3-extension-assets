<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;

final class AddCspNonceMetaTagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonceAttribute = $request->getAttribute('nonce');
        if (!$nonceAttribute instanceof ConsumableNonce) {
            return $handler->handle($request);
        }

        $nonce = $nonceAttribute->consume();
        $this->pageRenderer->addHeaderData(
            '<meta property="csp-nonce" nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '">'
        );

        return $handler->handle($request);
    }
}
