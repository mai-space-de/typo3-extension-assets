<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\ViewHelpers\Traits;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Provides read-only access to the extension's TypoScript settings.
 *
 * All settings live under `plugin.tx_maispace_assets.{dotPath}`.
 * A dot-path like `"image.lazyloading"` maps to the TypoScript array structure
 * `$setup['plugin.']['tx_maispace_assets.']['image.']['lazyloading']`.
 *
 * This trait is intentionally thin — it only reads; it never writes.
 */
trait TypoScriptSettingTrait
{
    /**
     * Read a TypoScript setting from plugin.tx_maispace_assets.{dotPath}.
     *
     * Returns $default when:
     *  - no PSR-7 request is present (e.g. CLI context)
     *  - the frontend TypoScript attribute is not set (e.g. backend context)
     *  - the requested path does not exist in the setup array
     *
     * @param string $dotPath Dot-separated path, e.g. "image.lazyloading" or "css.minify"
     * @param mixed  $default Fallback when the setting is absent
     */
    private static function getTypoScriptSetting(string $dotPath, mixed $default): mixed
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return $default;
        }

        /** @var \TYPO3\CMS\Core\TypoScript\FrontendTypoScript|null $fts */
        $fts = $request->getAttribute('frontend.typoscript');
        if ($fts === null) {
            return $default;
        }

        /** @var array<string, mixed> $setup */
        $setup = $fts->getSetupArray();
        $plugin = $setup['plugin.'] ?? null;
        if (!is_array($plugin)) {
            return $default;
        }
        $root = $plugin['tx_maispace_assets.'] ?? null;
        if (!is_array($root)) {
            return $default;
        }

        $parts = explode('.', $dotPath);
        $node = $root;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $lastIndex) {
                return $node[$part] ?? $default;
            }
            $next = $node[$part . '.'] ?? null;
            if (!is_array($next)) {
                return $default;
            }
            $node = $next;
        }

        return $default;
    }
}
