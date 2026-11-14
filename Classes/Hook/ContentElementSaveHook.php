<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Hook;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final readonly class ContentElementSaveHook
{
    public function __construct(
        private InvalidationService $invalidationService,
        private ?ImageMeasurementRepository $imageMeasurementRepository = null,
    ) {}

    /**
     * Fields that change how wide an image is rendered. Measurements of the
     * element are dropped then and learned again from real page views.
     */
    private const array LAYOUT_FIELDS = [
        'CType', 'colPos', 'layout', 'frame_class', 'pi_flexform', 'imageorient', 'imagecols',
        'imageborder', 'imagewidth', 'imageheight', 'image', 'assets', 'media', 'tx_container_parent',
    ];

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== 'tt_content') {
            return;
        }

        $uid = $id;
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            $uid = $dataHandler->substNEWwithIDs[$id] ?? 0;
        }
        $uid = (int) $uid;
        if ($uid <= 0) {
            return;
        }

        $changedFields = $this->resolveChangedFields($status, $fieldArray, $dataHandler, $uid, $table);
        if ($changedFields === []) {
            return;
        }

        if ($status !== 'new' && array_intersect($changedFields, self::LAYOUT_FIELDS) !== []) {
            try {
                $this->imageMeasurementRepository?->deleteForElement($uid);
            } catch (\Throwable) {
                // Measurement storage must never block saving content.
            }
        }

        $this->invalidationService->invalidateAfterContentSave($uid, $changedFields);
    }

    /**
     * @return array<string>
     */
    private function resolveChangedFields(string $status, array $fieldArray, DataHandler $dataHandler, int $uid, string $table): array
    {
        if ($status === 'new') {
            return ['pid', 'colPos', 'sorting'];
        }

        $changedFields = array_keys($fieldArray);
        if ($changedFields === []) {
            return [];
        }

        return $changedFields;
    }
}
