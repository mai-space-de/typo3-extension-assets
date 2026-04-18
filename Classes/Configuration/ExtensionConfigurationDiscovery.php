<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Configuration;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionConfigurationDiscovery
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Discover sprite icons from all loaded extensions' Configuration/SpriteIcons.php.
     *
     * @return array<string, string>  identifier => absolute SVG path
     */
    public function discoverSpriteIcons(): array
    {
        $currentSiteIdentifier = $this->getCurrentSiteIdentifier();
        $icons = [];

        foreach (ExtensionManagementUtility::getLoadedExtensionListArray() as $extKey) {
            $configFile = ExtensionManagementUtility::extPath($extKey) . 'Configuration/SpriteIcons.php';
            if (!file_exists($configFile)) {
                continue;
            }

            $config = require $configFile;
            if (!is_array($config)) {
                continue;
            }

            if (!$this->matchesSite($config['sites'] ?? ['*'], $currentSiteIdentifier)) {
                continue;
            }

            $iconList = $config['icons'] ?? [];
            if (!is_array($iconList)) {
                continue;
            }

            foreach ($iconList as $identifier => $svgPath) {
                if (!is_string($identifier) || !is_string($svgPath)) {
                    continue;
                }
                $resolved = GeneralUtility::getFileAbsFileName($svgPath);
                if ($resolved !== '' && !isset($icons[$identifier])) {
                    $icons[$identifier] = $resolved;
                }
            }
        }

        return $icons;
    }

    /**
     * Discover fonts from all loaded extensions' Configuration/Fonts.php.
     *
     * @return array<int, array{src: string, type: string}>
     */
    public function discoverFonts(): array
    {
        $currentSiteIdentifier = $this->getCurrentSiteIdentifier();
        $fonts = [];

        foreach (ExtensionManagementUtility::getLoadedExtensionListArray() as $extKey) {
            $configFile = ExtensionManagementUtility::extPath($extKey) . 'Configuration/Fonts.php';
            if (!file_exists($configFile)) {
                continue;
            }

            $config = require $configFile;
            if (!is_array($config)) {
                continue;
            }

            if (!$this->matchesSite($config['sites'] ?? ['*'], $currentSiteIdentifier)) {
                continue;
            }

            $fontList = $config['fonts'] ?? [];
            if (!is_array($fontList)) {
                continue;
            }

            foreach ($fontList as $font) {
                if (!is_array($font) || !isset($font['src'], $font['type'])) {
                    continue;
                }
                if (!is_string($font['src']) || !is_string($font['type'])) {
                    continue;
                }
                $resolved = GeneralUtility::getFileAbsFileName($font['src']);
                if ($resolved !== '') {
                    $fonts[] = ['src' => $resolved, 'type' => $font['type']];
                }
            }
        }

        return $fonts;
    }

    /**
     * @param array<int, string> $sites
     */
    private function matchesSite(array $sites, string $currentSiteIdentifier): bool
    {
        if (in_array('*', $sites, true)) {
            return true;
        }
        return in_array($currentSiteIdentifier, $sites, true);
    }

    private function getCurrentSiteIdentifier(): string
    {
        try {
            $sites = $this->siteFinder->getAllSites();
            if ($sites !== []) {
                return array_key_first($sites);
            }
        } catch (\Exception) {
            // No site found — treat as wildcard match
        }
        return '';
    }
}
