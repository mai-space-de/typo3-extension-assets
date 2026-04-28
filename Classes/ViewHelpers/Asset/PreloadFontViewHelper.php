<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Service\FontPreloadService;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class PreloadFontViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;
    protected $escapeOutput = false;

    public function __construct(
        private readonly FontPreloadService $fontPreloadService,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument(
            "path",
            "string",
            "EXT: path to woff2 font file",
            true,
        );
        $this->registerArgument(
            "isCritical",
            "bool",
            "Whether the font is critical (above-fold)",
            true,
        );
    }

    public function render(): string
    {
        $path = (string) $this->arguments["path"];
        $isCritical = (bool) $this->arguments["isCritical"];

        if ($isCritical) {
            $this->fontPreloadService->registerCriticalFont($path);

            $resolvedPath = $this->resolveFilePath($path);
            if ($resolvedPath !== '') {
                $ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
                $mimeType = 'font/' . $ext;
                $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);
                $this->earlyHintCollector->add(new EarlyHintCandidate(
                    href: $publicPath,
                    rel: 'preload',
                    as: 'font',
                    type: $mimeType,
                    crossorigin: 'anonymous',
                ));
            }
        }

        return "";
    }
}
