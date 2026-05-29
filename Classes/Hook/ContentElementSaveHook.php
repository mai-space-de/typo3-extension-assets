<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Hook;

use Maispace\MaiAssets\Cache\InvalidationService;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class ContentElementSaveHook
{
    public function __construct(
        private readonly InvalidationService $invalidationService,
    ) {}

    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tt_content') {
            return;
        }

        $uid = $id;
        if (is_string($id) && str_starts_with($id, 'NEW')) {
            $uid = $dataHandler->substNEWwithIDs[$id] ?? 0;
        }
        $uid = (int)$uid;
        if ($uid <= 0) {
            return;
        }

        $changedFields = $this->resolveChangedFields($status, $fieldArray, $dataHandler, $uid, $table);
        if ($changedFields === []) {
            return;
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
