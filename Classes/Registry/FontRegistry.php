<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\Registry;

use Maispace\MaispaceAssets\Exception\AssetFileNotFoundException;
use Maispace\MaispaceAssets\Exception\InvalidAssetConfigurationException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Discovers font registrations and emits `<link rel="preload">` headers for each.
 *
 * Auto-discovery
 * ==============
 * On first use, the registry iterates all loaded TYPO3 extensions and looks for
 * `Configuration/Fonts.php`. Any extension can register fonts by dropping this file —
 * no `ext_localconf.php` registration required.
 *
 * File format (`EXT:my_ext/Configuration/Fonts.php`):
 *
 *   <?php
 *   return [
 *       'my-font-regular' => [
 *           'src'     => 'EXT:my_ext/Resources/Public/Fonts/Regular.woff2',
 *           'type'    => 'font/woff2',   // optional — auto-detected from extension
 *           'preload' => true,           // optional — default true
 *           'sites'   => ['brand-a'],    // optional — omit for all sites
 *       ],
 *   ];
 *
 * Multi-site scoping
 * ==================
 * An optional `sites` key restricts a font to specific site identifiers.
 * Fonts without a `sites` key are included on all sites.
 *
 * Preload behaviour
 * =================
 * `emitPreloadHeaders()` adds a `<link rel="preload" as="font" crossorigin>` tag to
 * `<head>` via `PageRenderer::addHeaderData()` for each font that:
 * - is within the current site scope, AND
 * - has `preload` not set to false.
 *
 * The `crossorigin` attribute is mandatory for font preloads (browser spec requirement).
 *
 * Font files are served directly from their stable public extension URLs. No temp file
 * generation occurs — browser HTTP caching handles repeat visits efficiently.
 *
 * @see \Maispace\MaispaceAssets\EventListener\FontPreloadEventListener
 */
final class FontRegistry implements SingletonInterface
{
    private const MIME_MAP = [
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
    ];

    /**
     * @var array<string, array{
     *     src: string,
     *     publicUrl: string,
     *     type: string,
     *     preload: bool,
     *     sites?: array<string>
     * }>
     */
    private array $fonts = [];

    private bool $discovered = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PageRenderer $pageRenderer,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Emit `<link rel="preload" as="font">` header tags for all applicable fonts.
     *
     * Only fonts matching the current site (or with no site restriction) and with
     * `preload` not set to false are emitted. Idempotent — repeated calls are no-ops
     * because PageRenderer deduplicates identical `addHeaderData` calls by content.
     *
     * Pass `$globalPreloadEnabled = false` to suppress all output (TypoScript kill-switch).
     */
    public function emitPreloadHeaders(?string $siteIdentifier = null, bool $globalPreloadEnabled = true): void
    {
        if (!$globalPreloadEnabled) {
            return;
        }

        $this->discover();

        foreach ($this->fonts as $fonts) {
            if (!$this->isApplicable($fonts, $siteIdentifier)) {
                continue;
            }

            if ($fonts['preload'] === false) {
                continue;
            }

            $tag = sprintf(
                '<link rel="preload" href="%s" as="font" type="%s" crossorigin>',
                htmlspecialchars($fonts['publicUrl'], ENT_QUOTES),
                htmlspecialchars($fonts['type'], ENT_QUOTES),
            );

            $this->pageRenderer->addHeaderData($tag);
        }
    }

    /**
     * Return all font keys applicable to the given site.
     * Pass null to return all registered fonts regardless of site scope.
     *
     * @return string[]
     */
    public function getRegisteredFontKeys(?string $siteIdentifier = null): array
    {
        $this->discover();
        $keys = [];
        foreach ($this->fonts as $key => $font) {
            if ($this->isApplicable($font, $siteIdentifier)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    // -------------------------------------------------------------------------
    // Auto-discovery
    // -------------------------------------------------------------------------

    /**
     * Scan all loaded extensions for `Configuration/Fonts.php` and populate
     * the font registry. Idempotent — subsequent calls are no-ops.
     */
    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        foreach (ExtensionManagementUtility::getLoadedExtensionListArray() as $extKey) {
            if (!is_string($extKey)) {
                continue;
            }
            $file = ExtensionManagementUtility::extPath($extKey) . 'Configuration/Fonts.php';

            if (!is_file($file)) {
                continue;
            }

            $fonts = require $file;

            if (!is_array($fonts)) {
                $message = 'maispace_assets: Fonts.php in extension "' . $extKey . '" did not return an array.';
                $this->logger->warning($message);

                throw new InvalidAssetConfigurationException($message);
            }

            foreach ($fonts as $key => $config) {
                if (!is_string($key) || $key === '') {
                    $message = 'maispace_assets: Invalid (non-string or empty) font key in "' . $extKey . '/Configuration/Fonts.php".';
                    $this->logger->warning($message);

                    throw new InvalidAssetConfigurationException($message);
                }

                if (!is_array($config)) {
                    $message = 'maispace_assets: Font entry for key "' . $key . '" in "' . $extKey . '" must be an array, got ' . gettype($config) . '.';
                    $this->logger->warning($message);

                    throw new InvalidAssetConfigurationException($message);
                }

                if (!isset($config['src']) || !is_string($config['src'])) {
                    $message = 'maispace_assets: Font "' . $key . '" in "' . $extKey . '" is missing the required "src" key (must be a non-empty string path).';
                    $this->logger->warning($message);

                    throw new InvalidAssetConfigurationException($message);
                }

                $absolutePath = GeneralUtility::getFileAbsFileName($config['src']);
                if ($absolutePath === '' || !is_file($absolutePath)) {
                    $message = 'maispace_assets: Font file not found for "' . $key . '" in "' . $extKey . '": "' . $config['src'] . '". Verify the EXT: path is correct.';
                    $this->logger->warning($message);

                    throw new AssetFileNotFoundException($message);
                }

                $publicPath = Environment::getPublicPath();
                if (str_starts_with($absolutePath, $publicPath)) {
                    $relativePath = ltrim(substr($absolutePath, strlen($publicPath)), '/\\');
                    $sitePath = Environment::isCli() ? '/' : (string)GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
                    $publicUrl = $sitePath . $relativePath;
                } else {
                    $publicUrl = PathUtility::getAbsoluteWebPath($absolutePath);
                }

                $type = is_string($config['type'] ?? null) ? (string)$config['type'] : $this->detectMimeType($absolutePath);

                if ($type === '') {
                    $message = 'maispace_assets: Cannot determine MIME type for font "' . $key . '" in "' . $extKey . '": "' . $config['src'] . '". '
                        . 'Add an explicit "type" key to the entry (e.g. \'type\' => \'font/woff2\').';
                    $this->logger->warning($message);

                    throw new InvalidAssetConfigurationException($message);
                }

                // Later registrations win — site packages can override vendor fonts.
                $entry = [
                    'src'       => $config['src'],
                    'publicUrl' => $publicUrl,
                    'type'      => $type,
                    'preload'   => (bool)($config['preload'] ?? true),
                ];

                if (isset($config['sites']) && is_array($config['sites'])) {
                    $sites = [];
                    foreach ($config['sites'] as $site) {
                        if (is_string($site) && $site !== '') {
                            $sites[] = $site;
                        }
                    }
                    if ($sites !== []) {
                        $entry['sites'] = $sites;
                    }
                }

                $this->fonts[$key] = $entry;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a font entry applies to the given site.
     *
     * A font is applicable when:
     * - It has no `sites` key (global), OR
     * - `$siteIdentifier` is not null AND appears in the `sites` array.
     */
    /**
     * @param array<string, mixed> $font
     */
    /**
     * @param array{
     *     src: string,
     *     publicUrl: string,
     *     type: string,
     *     preload: bool,
     *     sites?: array<string>
     * } $font
     */
    private function isApplicable(array $font, ?string $siteIdentifier): bool
    {
        if (!isset($font['sites'])) {
            return true;
        }

        return $siteIdentifier !== null && in_array($siteIdentifier, $font['sites'], true);
    }

    /**
     * Detect MIME type from the file extension.
     * Returns an empty string when the extension is not recognised.
     */
    private function detectMimeType(string $absolutePath): string
    {
        $info = pathinfo($absolutePath);
        $ext = strtolower((string)($info['extension'] ?? ''));

        return self::MIME_MAP[$ext] ?? '';
    }
}
