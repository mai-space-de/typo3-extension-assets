<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Decides whether a request may read from or write to the shared static file cache.
 *
 * The static cache is one file per page and language that is served to every
 * visitor. Anything that makes a response visitor-specific or attacker-influenced
 * must therefore never be stored or replayed:
 *
 * - logged-in frontend or backend users (personalised / access-restricted content);
 * - preview and workspace requests (unpublished content);
 * - requests whose host or scheme differs from the configured site language
 *   base (absolute URLs, canonical and hreflang tags are built from the request,
 *   so a forged Host header would otherwise be persisted for all visitors).
 */
class StaticCacheRequestGuard
{
    public function isShareable(ServerRequestInterface $request): bool
    {
        if ($this->hasAuthenticatedUser($request)) {
            return false;
        }

        if ($this->isPreviewRequest($request)) {
            return false;
        }

        return $this->matchesConfiguredBase($request);
    }

    private function hasAuthenticatedUser(ServerRequestInterface $request): bool
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (is_object($frontendUser) && property_exists($frontendUser, 'user')
            && is_array($frontendUser->user) && ($frontendUser->user['uid'] ?? 0) > 0) {
            return true;
        }

        $backendUser = $request->getAttribute('backend.user');
        if (is_object($backendUser) && property_exists($backendUser, 'user')
            && is_array($backendUser->user) && ($backendUser->user['uid'] ?? 0) > 0) {
            return true;
        }

        $cookies = $request->getCookieParams();

        return isset($cookies['be_typo_user']) && $cookies['be_typo_user'] !== '';
    }

    private function isPreviewRequest(ServerRequestInterface $request): bool
    {
        $preview = $request->getAttribute('frontend.preview');
        if (is_object($preview) && method_exists($preview, 'isPreview') && $preview->isPreview()) {
            return true;
        }

        $context = GeneralUtility::makeInstance(Context::class);

        return $context->getPropertyFromAspect('workspace', 'isOffline', false)
            || (int) $context->getPropertyFromAspect('workspace', 'id', 0) !== 0
            || $context->getPropertyFromAspect('visibility', 'includeHiddenContent', false)
            || $context->getPropertyFromAspect('visibility', 'includeHiddenPages', false);
    }

    private function matchesConfiguredBase(ServerRequestInterface $request): bool
    {
        $language = $request->getAttribute('language');
        if (!$language instanceof SiteLanguage) {
            return false;
        }

        $base = $language->getBase();
        $uri = $request->getUri();

        $baseHost = strtolower($base->getHost());
        if ($baseHost === '') {
            // Relative base ("/"): fall back to the site's entry point.
            $site = $request->getAttribute('site');
            $baseHost = is_object($site) && method_exists($site, 'getBase')
                ? strtolower($site->getBase()->getHost())
                : '';
        }

        if ($baseHost === '' || strtolower($uri->getHost()) !== $baseHost) {
            return false;
        }

        $baseScheme = $base->getScheme();

        return $baseScheme === '' || strtolower($uri->getScheme()) === strtolower($baseScheme);
    }
}
