<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\Service\FontPreloadService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class PreloadFontViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly FontPreloadService $fontPreloadService,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('path', 'string', 'EXT: path to woff2 font file', true);
        $this->registerArgument('isCritical', 'bool', 'Whether the font is critical (above-fold)', true);
    }

    public function render(): string
    {
        $path = (string)$this->arguments['path'];
        $isCritical = (bool)$this->arguments['isCritical'];

        if ($isCritical) {
            $this->fontPreloadService->registerCriticalFont($path);
        }

        // Output is handled by FontPreloadCollector via SvgSpriteInjectionHook
        return '';
    }
}
