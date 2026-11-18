<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Hook;

use PHPUnit\Framework\MockObject\MockObject;
use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Hook\ContentElementSaveHook;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementPageRefresher;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class ContentElementSaveHookTest extends TestCase
{
    /** @var InvalidationService&MockObject */
    private InvalidationService $invalidationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invalidationService = $this->createMock(InvalidationService::class);
    }

    private function createHook(): ContentElementSaveHook
    {
        return new ContentElementSaveHook($this->invalidationService);
    }

    public function testIgnoresNonTtContentTable(): void
    {
        $this->invalidationService
            ->expects(self::never())
            ->method('invalidateAfterContentSave');

        $dataHandler = $this->createMock(DataHandler::class);
        $this->createHook()->processDatamap_afterDatabaseOperations(
            'update',
            'pages',
            1,
            ['title' => 'New Title'],
            $dataHandler,
        );
    }

    public function testHandlesNewContentElementWithNewId(): void
    {
        $this->invalidationService
            ->expects(self::once())
            ->method('invalidateAfterContentSave')
            ->with(42, ['pid', 'colPos', 'sorting']);

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->substNEWwithIDs = ['NEW123' => 42];

        $this->createHook()->processDatamap_afterDatabaseOperations(
            'new',
            'tt_content',
            'NEW123',
            ['pid' => 1, 'colPos' => 0, 'sorting' => 0],
            $dataHandler,
        );
    }

    public function testHandlesExistingContentElementUpdate(): void
    {
        $this->invalidationService
            ->expects(self::once())
            ->method('invalidateAfterContentSave')
            ->with(42, ['header', 'bodytext']);

        $dataHandler = $this->createMock(DataHandler::class);
        $this->createHook()->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            42,
            ['header' => 'New Header', 'bodytext' => 'New Content'],
            $dataHandler,
        );
    }

    public function testIgnoresNewIdWithoutSubstitution(): void
    {
        $this->invalidationService
            ->expects(self::never())
            ->method('invalidateAfterContentSave');

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->substNEWwithIDs = [];

        $this->createHook()->processDatamap_afterDatabaseOperations(
            'new',
            'tt_content',
            'NEW123',
            ['pid' => 1],
            $dataHandler,
        );
    }

    public function testIgnoresInvalidUid(): void
    {
        $this->invalidationService
            ->expects(self::never())
            ->method('invalidateAfterContentSave');

        $dataHandler = $this->createMock(DataHandler::class);

        $this->createHook()->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            0,
            ['header' => 'Test'],
            $dataHandler,
        );
    }

    public function testHandlesStringUidThatIsNotNew(): void
    {
        $this->invalidationService
            ->expects(self::once())
            ->method('invalidateAfterContentSave')
            ->with(123, ['header']);

        $dataHandler = $this->createMock(DataHandler::class);
        $this->createHook()->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            '123',
            ['header' => 'Test'],
            $dataHandler,
        );
    }

    public function testLayoutChangeRefreshesOtherPagesShowingTheElement(): void
    {
        $repository = $this->createMock(ImageMeasurementRepository::class);
        $repository->expects(self::once())->method('deleteForElement')->with(42)->willReturn(3);
        $pageRefresher = $this->createMock(ImageMeasurementPageRefresher::class);
        $pageRefresher->method('pagesOfElement')->with(42)->willReturn([1, 8]);
        $pageRefresher->expects(self::once())->method('refresh')->with([1, 8]);

        (new ContentElementSaveHook($this->invalidationService, $repository, $pageRefresher))
            ->processDatamap_afterDatabaseOperations('update', 'tt_content', 42, ['imageorient' => 25], $this->createMock(DataHandler::class));
    }

    public function testNoMeasurementsMeansNoExtraRefresh(): void
    {
        $repository = $this->createMock(ImageMeasurementRepository::class);
        $repository->method('deleteForElement')->willReturn(0);
        $pageRefresher = $this->createMock(ImageMeasurementPageRefresher::class);
        $pageRefresher->method('pagesOfElement')->willReturn([1, 8]);
        $pageRefresher->expects(self::never())->method('refresh');

        (new ContentElementSaveHook($this->invalidationService, $repository, $pageRefresher))
            ->processDatamap_afterDatabaseOperations('update', 'tt_content', 42, ['imageorient' => 25], $this->createMock(DataHandler::class));
    }
}
