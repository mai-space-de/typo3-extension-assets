<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Collector;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

final class FontPreloadCollector extends AbstractAssetCollector implements SingletonInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function build(): string
    {
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
