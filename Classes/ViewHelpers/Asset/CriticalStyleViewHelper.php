<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class CriticalStyleViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly AssetCollector $assetCollector,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument(
            "identifier",
            "string",
            "Unique identifier for the stylesheet",
            true,
        );
        $this->registerArgument(
            "source",
            "string",
            "EXT: path to CSS or SCSS file",
            false,
            "",
        );
        $this->registerArgument(
            "isCritical",
            "bool",
            "Whether the asset is above-fold critical",
            true,
        );
        $this->registerArgument("media", "string", "Media query", false, "all");
    }

    public function render(): string
    {
        $source = (string) $this->arguments["source"];
        $isCritical = (bool) $this->arguments["isCritical"];
        $media = (string) $this->arguments["media"];

        if ($source === "") {
            return "";
        }

        $resolvedPath = $this->requireFile($source);
        $content = (string) file_get_contents($resolvedPath);
        $ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));

        // Compile SCSS if needed
        if (
            $ext === "scss" &&
            $this->extensionConfiguration->isEnableScssProcessing()
        ) {
            $content = $this->scssProcessor->process($content, $resolvedPath);
        }

        if ($isCritical) {
            // Minify and inline critical CSS
            if ($this->extensionConfiguration->isEnableMinification()) {
                $content = $this->minificationProcessor->process(
                    $content,
                    $resolvedPath,
                );
            }
            return "<style>" . $content . "</style>";
        }

        // Deferred load for non-critical — register via AssetCollector for deduplication
        $identifier = (string) $this->arguments["identifier"];
        $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);
        $this->assetCollector->addStyleSheet($identifier, $publicPath, [
            "media" => $media,
        ]);

        return "";
    }
}
