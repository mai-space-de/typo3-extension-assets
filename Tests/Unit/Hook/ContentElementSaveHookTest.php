<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Hook;

use Maispace\MaiAssets\Cache\InvalidationService;
use Maispace\MaiAssets\Hook\ContentElementSaveHook;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class ContentElementSaveHookTest extends TestCase
{
    /** @var InvalidationService&\PHPUnit\Framework\MockObject\MockObject */
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
            $dataHandler
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
            $dataHandler
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
            $dataHandler
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
            $dataHandler
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
            $dataHandler
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
            $dataHandler
        );
    }
}
