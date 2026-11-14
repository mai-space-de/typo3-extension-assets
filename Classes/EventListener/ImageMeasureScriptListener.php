<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Service\ImageMeasureScriptBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Injects the image measurement beacon before {@code </body>}, but only on
 * pages that actually contain measured images.
 */
#[AsEventListener(
    identifier: 'mai-assets/image-measure-script',
    before: 'mai-assets/html-minification',
)]
final readonly class ImageMeasureScriptListener
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private ImageMeasureScriptBuilder $scriptBuilder,
    ) {}

    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        if (!$this->extensionConfiguration->isEnableImageMeasurement() || !$event->isCachingEnabled()) {
            return;
        }

        $content = $event->getContent();
        if (!str_contains($content, 'data-mai-m="')) {
            return;
        }

        $pageUid = (int) ($event->getRequest()->getAttribute('routing')?->getPageId() ?? 0);
        if ($pageUid <= 0) {
            return;
        }

        $scriptPath = GeneralUtility::getFileAbsFileName('EXT:mai_assets/Resources/Public/JavaScript/ImageMeasure.js');
        if (!is_file($scriptPath)) {
            return;
        }

        $script = $this->scriptBuilder->build((string) file_get_contents($scriptPath), $pageUid);

        $event->setContent(str_ireplace('</body>', $script . '</body>', $content));
    }
}
