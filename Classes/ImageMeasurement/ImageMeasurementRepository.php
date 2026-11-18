<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ImageMeasurement;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stores the measured layout widths as a small histogram per image slot and
 * viewport step. The storage is bounded on purpose: a capped number of width
 * rows per step and a capped number of samples per step.
 */
class ImageMeasurementRepository
{
    public const string TABLE = 'tx_maiassets_image_measure';
    /** Pages an image slot was reported from; needed to refresh every page that renders a slot. */
    public const string PAGE_TABLE = 'tx_maiassets_image_measure_page';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Counts one sample. Returns false when the viewport step is already converged.
     *
     * Once all width rows of a step are in use, a new width is merged into the
     * next wider row. That keeps the table bounded and errs on the side of
     * larger (never blurry) images.
     */
    public function record(string $slotKey, int $viewportStep, int $cssWidth): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('css_width', 'hits')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('slot_key', $queryBuilder->createNamedParameter($slotKey)),
                $queryBuilder->expr()->eq('vp_step', $queryBuilder->createNamedParameter($viewportStep, Connection::PARAM_INT)),
            )
            ->orderBy('css_width', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $widths = [];
        $total = 0;
        foreach ($rows as $row) {
            $widths[] = (int) $row['css_width'];
            $total += (int) $row['hits'];
        }

        if ($total >= ImageMeasurementScale::MAX_HITS_PER_STEP) {
            return false;
        }

        $target = $cssWidth;
        if (!in_array($cssWidth, $widths, true)) {
            if (count($widths) < ImageMeasurementScale::MAX_ROWS_PER_STEP) {
                try {
                    $connection->insert(self::TABLE, [
                        'slot_key' => $slotKey,
                        'vp_step' => $viewportStep,
                        'css_width' => $cssWidth,
                        'hits' => 1,
                        'tstamp' => time(),
                    ]);

                    return true;
                } catch (UniqueConstraintViolationException) {
                    // A parallel request created the row first: count on it instead.
                }
            } else {
                $target = $this->nextWiderOrWidest($widths, $cssWidth);
            }
        }

        $update = $connection->createQueryBuilder();
        $update
            ->update(self::TABLE)
            ->set('hits', $update->quoteIdentifier('hits') . ' + 1', false)
            ->set('tstamp', time())
            ->where(
                $update->expr()->eq('slot_key', $update->createNamedParameter($slotKey)),
                $update->expr()->eq('vp_step', $update->createNamedParameter($viewportStep, Connection::PARAM_INT)),
                $update->expr()->eq('css_width', $update->createNamedParameter($target, Connection::PARAM_INT)),
            )
            ->executeStatement();

        return true;
    }

    /**
     * @return array<int, array<int, int>> viewport step => (css width => hits)
     */
    public function getHistogram(string $slotKey): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $rows = $queryBuilder
            ->select('vp_step', 'css_width', 'hits')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('slot_key', $queryBuilder->createNamedParameter($slotKey)))
            ->executeQuery()
            ->fetchAllAssociative();

        $histogram = [];
        foreach ($rows as $row) {
            $histogram[(int) $row['vp_step']][(int) $row['css_width']] = (int) $row['hits'];
        }

        return $histogram;
    }

    /**
     * @return list<string>
     */
    public function getSlotKeys(): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $keys = $queryBuilder
            ->select('slot_key')
            ->distinct()
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(strval(...), $keys));
    }

    /**
     * Removes the measurements of all slots of a content element (prefix "e123.").
     */
    public function deleteForElement(int $elementUid): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->like(
                'slot_key',
                $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards('e' . $elementUid . '.') . '%'),
            ))
            ->executeStatement();
    }

    public function deleteForSlot(string $slotKey): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->eq('slot_key', $queryBuilder->createNamedParameter($slotKey)))
            ->executeStatement();
    }

    public function deleteAll(): int
    {
        return (int) $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->delete(self::TABLE)
            ->executeStatement();
    }

    public function deleteOlderThan(int $timestamp): int
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * Remembers that a slot is rendered on a page. Returns true when the page was not known yet.
     *
     * The number of pages per slot is capped by {@see ImageMeasurementScale::MAX_PAGES_PER_SLOT}.
     */
    public function rememberPage(string $slotKey, int $pageUid): bool
    {
        if ($pageUid <= 0) {
            return false;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::PAGE_TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $pageUids = array_map(intval(...), $queryBuilder
            ->select('page_uid')
            ->from(self::PAGE_TABLE)
            ->where($queryBuilder->expr()->eq('slot_key', $queryBuilder->createNamedParameter($slotKey)))
            ->executeQuery()
            ->fetchFirstColumn());

        if (in_array($pageUid, $pageUids, true) || count($pageUids) >= ImageMeasurementScale::MAX_PAGES_PER_SLOT) {
            return false;
        }

        try {
            $connection->insert(self::PAGE_TABLE, [
                'slot_key' => $slotKey,
                'page_uid' => $pageUid,
                'tstamp' => time(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel request registered the page first.
            return false;
        }

        return true;
    }

    /**
     * @return list<int> pages the slot was reported from
     */
    public function getPageUids(string $slotKey): array
    {
        $queryBuilder = $this->connectionPool->getConnectionForTable(self::PAGE_TABLE)->createQueryBuilder();

        return array_values(array_map(intval(...), $queryBuilder
            ->select('page_uid')
            ->from(self::PAGE_TABLE)
            ->where($queryBuilder->expr()->eq('slot_key', $queryBuilder->createNamedParameter($slotKey)))
            ->executeQuery()
            ->fetchFirstColumn()));
    }

    /**
     * @param list<int> $sortedWidths
     */
    private function nextWiderOrWidest(array $sortedWidths, int $cssWidth): int
    {
        foreach ($sortedWidths as $width) {
            if ($width > $cssWidth) {
                return $width;
            }
        }

        return (int) end($sortedWidths);
    }
}
