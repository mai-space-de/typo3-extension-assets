<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image\Picture;

use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfile;
use Maispace\MaiAssets\Service\PictureSourceRenderer;
use Maispace\MaiAssets\ViewHelpers\Image\PictureViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a <source> element inside a <mai:picture> container.
 * Must be used as a direct child of PictureViewHelper.
 *
 * Once the parent image has a measured size profile, its widths and sizes
 * replace the configured {@code srcset}/{@code sizes} (the media query stays),
 * unless {@code measured="false"} is set.
 */
class SourceViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        private readonly PictureSourceRenderer $pictureSourceRenderer,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('media', 'string', 'Media query for this source (required)', true);
        $this->registerArgument('srcset', 'array', 'Array of widths to generate (e.g. [400, 800, 1200])', false, []);
        $this->registerArgument('sizes', 'string', 'sizes attribute', false, '');
        $this->registerArgument('formats', 'array', 'Image formats to generate, e.g. ["avif","webp"]', false, ['avif', 'webp']);
        $this->registerArgument('quality', 'int', 'Image quality', false, 85);
        $this->registerArgument('width', 'int', 'Fallback width', false, 0);
        $this->registerArgument('height', 'int', 'Fallback height', false, 0);
        $this->registerArgument('measured', 'bool', 'Use the measured widths/sizes of the parent picture once they exist', false, true);
    }

    public function render(): string
    {
        $viewHelperVariableContainer = $this->renderingContext->getViewHelperVariableContainer();

        if (!$viewHelperVariableContainer->exists(PictureViewHelper::class, 'fileReference')) {
            return '';
        }

        $fileReference = $viewHelperVariableContainer->get(PictureViewHelper::class, 'fileReference');
        $isCritical = (bool) ($viewHelperVariableContainer->exists(PictureViewHelper::class, 'isCritical')
            ? $viewHelperVariableContainer->get(PictureViewHelper::class, 'isCritical')
            : false);
        /** @var EarlyHintCandidateCollector|null $earlyHintCollector */
        $earlyHintCollector = $viewHelperVariableContainer->exists(PictureViewHelper::class, 'earlyHintCollector')
            ? $viewHelperVariableContainer->get(PictureViewHelper::class, 'earlyHintCollector')
            : null;

        $media = (string) $this->arguments['media'];
        $widths = (array) $this->arguments['srcset'];
        $sizes = (string) $this->arguments['sizes'];
        $formats = (array) $this->arguments['formats'];

        $profile = $viewHelperVariableContainer->exists(PictureViewHelper::class, 'measurementProfile')
            ? $viewHelperVariableContainer->get(PictureViewHelper::class, 'measurementProfile')
            : null;
        if ($profile instanceof ImageMeasurementProfile && (bool) $this->arguments['measured']) {
            $widths = $profile->widths;
            $sizes = $profile->sizes;
        }

        $registeredEarlyHint = false;

        return $this->pictureSourceRenderer->renderSourcesForMedia(
            $fileReference,
            $media,
            $widths,
            $sizes,
            $formats,
            $isCritical,
            $earlyHintCollector,
            $registeredEarlyHint,
        );
    }
}
