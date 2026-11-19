<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\DataProcessing;

use Maispace\MaiAssets\Service\CriticalDetectionService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final readonly class CriticalAssetDataProcessor implements DataProcessorInterface
{
    public function __construct(
        private CriticalDetectionService $criticalDetectionService,
    ) {}

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData,
    ): array {
        // Observer data is stored for the page being rendered. A referenced element
        // (shortcut, sliding content) is stored on another page (pid).
        try {
            $routing = $cObj->getRequest()->getAttribute('routing');
        } catch (\Throwable) {
            $routing = null;
        }
        $pageUid = is_object($routing) && method_exists($routing, 'getPageId')
            ? (int) $routing->getPageId()
            : (int) ($processedData['data']['pid'] ?? 0);
        $elementUid = (int) ($processedData['data']['uid'] ?? 0);

        $isCritical = $this->criticalDetectionService->isCritical($pageUid, $elementUid);

        $processedData['isCritical'] = $isCritical;
        $processedData['loadingStrategy'] = $isCritical ? 'eager' : 'lazy';
        $processedData['fetchPriority'] = $isCritical ? 'high' : 'low';
        $processedData['decodingStrategy'] = $isCritical ? 'sync' : 'async';
        $processedData['cssStrategy'] = $isCritical ? 'inline' : 'deferred';

        return $processedData;
    }
}
