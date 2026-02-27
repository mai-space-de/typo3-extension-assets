<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers\Traits;

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
     * @param string $dotPath  Dot-separated path, e.g. "image.lazyloading" or "css.minify"
     * @param mixed  $default  Fallback when the setting is absent
     * @return mixed
     */
    private static function getTypoScriptSetting(string $dotPath, mixed $default): mixed
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $default;
        }

        $fts = $request->getAttribute('frontend.typoscript');
        if ($fts === null) {
            return $default;
        }

        $setup = $fts->getSetupArray();
        $root  = $setup['plugin.']['tx_maispace_assets.'] ?? [];

        $parts = explode('.', $dotPath);
        $node  = $root;
        foreach ($parts as $i => $part) {
            $isLast = ($i === count($parts) - 1);
            if ($isLast) {
                return $node[$part] ?? $default;
            }
            $node = $node[$part . '.'] ?? [];
            if (!is_array($node)) {
                return $default;
            }
        }

        return $default;
    }
}
