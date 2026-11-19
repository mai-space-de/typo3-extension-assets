<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Frontend\Middleware\ContentSecurityPolicyHeaders;

/**
 * Gives a static response the Content-Security-Policy of the site.
 *
 * The static file answers before TYPO3's CSP middleware runs. TYPO3 itself
 * delivers cached pages by replacing the nonce stored with the page by the nonce
 * of the current request; this does the same: the CSP header is built for the
 * request and the stored nonce in the body is replaced by the one of the header.
 *
 * Needs ContentSecurityPolicyHeaders::applyToResponse() (TYPO3 14). Without it the
 * response is returned unchanged.
 */
class StaticContentSecurityPolicy
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function apply(ServerRequestInterface $request, ResponseInterface $response, string $storedNonce): ResponseInterface
    {
        // applyToResponse() exists since TYPO3 14.
        if ((new Typo3Version())->getMajorVersion() < 14 || !$this->container->has(ContentSecurityPolicyHeaders::class)) {
            return $response;
        }

        $withPolicy = $this->container->get(ContentSecurityPolicyHeaders::class)->applyToResponse($request, $response);

        return self::substituteNonce($withPolicy, $storedNonce);
    }

    /**
     * Replaces the nonce stored with the HTML by the nonce of the response's policy.
     */
    public static function substituteNonce(ResponseInterface $response, string $storedNonce): ResponseInterface
    {
        $policy = $response->getHeaderLine('Content-Security-Policy') . ' '
            . $response->getHeaderLine('Content-Security-Policy-Report-Only');
        if ($storedNonce === '' || preg_match("/'nonce-([A-Za-z0-9_\\-+\\/=]+)'/", $policy, $matches) !== 1) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, $storedNonce)) {
            return $response;
        }

        return $response->withBody((new StreamFactory())->createStream(str_replace($storedNonce, $matches[1], $body)));
    }

    public static function hasPolicy(ResponseInterface $response): bool
    {
        return $response->hasHeader('Content-Security-Policy') || $response->hasHeader('Content-Security-Policy-Report-Only');
    }
}
