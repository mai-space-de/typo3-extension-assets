<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Middleware;

use Maispace\MaispaceAssets\Service\CriticalAssetService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;

/**
 * PSR-15 middleware that injects per-page critical CSS and JS into <head>.
 *
 * After the TYPO3 frontend renders the page HTML, this middleware looks up
 * critical CSS and JS for the current page UID (previously extracted and
 * cached by the `maispace:assets:critical:extract` CLI command) and splices
 * them in immediately before the closing </head> tag.
 *
 * Viewport handling
 * =================
 * Both mobile and desktop critical CSS are always injected into every response.
 * Each <style> block is scoped to its viewport via a CSS `media` attribute:
 *
 *   <style media="screen and (max-width: 767px)">…mobile rules…</style>
 *   <style media="screen and (min-width: 768px)">…desktop rules…</style>
 *
 * The browser silently ignores whichever block does not match the current
 * viewport, so no server-side User-Agent sniffing is needed and the response
 * is safe for CDN caching without Vary: User-Agent.
 *
 * Critical JS
 * ===========
 * Synchronous inline scripts required for initial render are injected as a
 * single <script> block (using the mobile extraction as the canonical source,
 * falling back to desktop). This is intentionally conservative — only tiny,
 * render-critical initialisation snippets should qualify.
 *
 * Cold cache
 * ==========
 * When no critical CSS has been extracted yet for a page, the middleware is a
 * complete no-op and returns the response unchanged.
 *
 * CSP nonces
 * ==========
 * When TYPO3's built-in Content Security Policy is enabled, the nonce from
 * the request attribute is automatically added to every injected <style> and
 * <script> tag — no configuration required.
 *
 * Stack position
 * ==============
 * Registered after `typo3/cms-frontend/page-resolver` so the request already
 * carries the resolved PageArguments (page UID) when process() is called.
 *
 * @see CriticalAssetService
 * @see \Maispace\MaispaceAssets\Command\CriticalCssExtractCommand
 */
final class CriticalCssInlineMiddleware implements MiddlewareInterface
{
    /**
     * Upper bound (inclusive) of the mobile media query, in CSS pixels.
     * Screens ≤ this width receive the mobile critical CSS.
     */
    private const MOBILE_MAX_PX = 767;

    /**
     * Lower bound (inclusive) of the desktop media query, in CSS pixels.
     * Screens ≥ this width receive the desktop critical CSS.
     */
    private const DESKTOP_MIN_PX = 768;

    public function __construct(
        private readonly CriticalAssetService $criticalAssetService,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        // Only process HTML responses.
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        // Resolve page UID from the routing result set by page-resolver.
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return $response;
        }

        $pageUid = $routing->getPageId();
        if ($pageUid <= 0) {
            return $response;
        }

        $injection = $this->buildInjection($pageUid, $request);
        if ($injection === '') {
            return $response;
        }

        // Locate </head> and splice in the critical blocks immediately before it.
        $body = (string)$response->getBody();
        $headClosePos = stripos($body, '</head>');
        if ($headClosePos === false) {
            return $response;
        }

        $modified = substr($body, 0, $headClosePos) . $injection . substr($body, $headClosePos);

        return $response->withBody($this->streamFactory->createStream($modified));
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build the complete injection string: viewport-scoped <style> blocks and
     * an optional critical <script> block.
     */
    private function buildInjection(int $pageUid, ServerRequestInterface $request): string
    {
        $mobileCss = $this->criticalAssetService->getCriticalCss($pageUid, 'mobile');
        $desktopCss = $this->criticalAssetService->getCriticalCss($pageUid, 'desktop');
        $mobileJs = $this->criticalAssetService->getCriticalJs($pageUid, 'mobile');
        $desktopJs = $this->criticalAssetService->getCriticalJs($pageUid, 'desktop');

        if ($mobileCss === null && $desktopCss === null && $mobileJs === null && $desktopJs === null) {
            return '';
        }

        $nonceAttr = $this->buildNonceAttr($request);
        $output = '';

        // Critical CSS — mobile viewport.
        if ($mobileCss !== null) {
            $output .= sprintf(
                '<style media="screen and (max-width: %dpx)"%s>%s</style>',
                self::MOBILE_MAX_PX,
                $nonceAttr,
                $mobileCss,
            );
        }

        // Critical CSS — desktop viewport.
        if ($desktopCss !== null) {
            $output .= sprintf(
                '<style media="screen and (min-width: %dpx)"%s>%s</style>',
                self::DESKTOP_MIN_PX,
                $nonceAttr,
                $desktopCss,
            );
        }

        // Critical JS — prefer mobile extraction; fall back to desktop.
        $criticalJs = $mobileJs ?? $desktopJs;
        if ($criticalJs !== null) {
            $output .= sprintf('<script%s>%s</script>', $nonceAttr, $criticalJs);
        }

        return $output;
    }

    /**
     * Build a `nonce="…"` attribute string when CSP is active, otherwise "".
     */
    private function buildNonceAttr(ServerRequestInterface $request): string
    {
        $nonce = $request->getAttribute('nonce');

        if (!$nonce instanceof ConsumableNonce) {
            return '';
        }

        $value = (string)$nonce;

        return $value !== '' ? ' nonce="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE) . '"' : '';
    }
}
