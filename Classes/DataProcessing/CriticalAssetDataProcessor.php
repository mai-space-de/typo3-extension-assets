<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\DataProcessing;

use Maispace\MaiAssets\Service\CriticalDetectionService;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

final class CriticalAssetDataProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly CriticalDetectionService $criticalDetectionService,
    ) {}

    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $pageUid = (int)($processedData['data']['pid'] ?? 0);
        $elementUid = (int)($processedData['data']['uid'] ?? 0);

        $isCritical = $this->criticalDetectionService->isCritical($pageUid, $elementUid);

        $processedData['isCritical'] = $isCritical;
        $processedData['loadingStrategy'] = $isCritical ? 'eager' : 'lazy';
        $processedData['fetchPriority'] = $isCritical ? 'high' : 'low';
        $processedData['decodingStrategy'] = $isCritical ? 'sync' : 'async';
        $processedData['cssStrategy'] = $isCritical ? 'inline' : 'deferred';

        return $processedData;
    }
}
