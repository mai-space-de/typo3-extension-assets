<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Collector;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\PathUtility;

final class FontPreloadCollector extends AbstractAssetCollector implements SingletonInterface
{
    private bool $discovered = false;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ExtensionConfigurationDiscovery $extensionConfigurationDiscovery,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
    ) {}

    public function build(): string
    {
        if (!$this->discovered) {
            $this->discovered = true;
            foreach ($this->extensionConfigurationDiscovery->discoverFonts() as $font) {
                $identifier = md5($font['src']);
                $this->register($identifier, $font['src']);
            }
        }

        $assets = $this->getAll();
        if ($assets === []) {
            return '';
        }

        $allowedFormats = $this->extensionConfiguration->getFontPreloadFormats();
        $links = '';

        foreach ($assets as $identifier => $filePath) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedFormats, true)) {
                continue;
            }

            $mimeType = $this->getMimeType($ext);
            $links .= sprintf(
                '<link rel="preload" href="%s" as="font" type="%s" crossorigin="anonymous">',
                htmlspecialchars($filePath, ENT_QUOTES),
                $mimeType
            ) . "\n";

            $publicPath = PathUtility::getAbsoluteWebPath($filePath);
            $this->earlyHintCollector->add(new EarlyHintCandidate(
                href: $publicPath,
                rel: 'preload',
                as: 'font',
                type: $mimeType,
                crossorigin: 'anonymous',
            ));
        }

        return $links;
    }

    private function getMimeType(string $extension): string
    {
        return match ($extension) {
            'woff2' => 'font/woff2',
            'woff'  => 'font/woff',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            default => 'font/' . $extension,
        };
    }
}
