<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Command;

use Maispace\MaiAssets\Command\ImageMeasureResetCommand;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementPageRefresher;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImageMeasureResetCommandTest extends TestCase
{
    /** @var ImageMeasurementRepository&MockObject */
    private ImageMeasurementRepository $repository;
    /** @var ImageMeasurementPageRefresher&MockObject */
    private ImageMeasurementPageRefresher $pageRefresher;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ImageMeasurementRepository::class);
        $this->pageRefresher = $this->createMock(ImageMeasurementPageRefresher::class);
    }

    public function testResettingASlotRefreshesThePagesItWasReportedFrom(): void
    {
        $this->pageRefresher->method('pagesOfSlot')->with('s.textmedia-left')->willReturn([3, 7]);
        $this->repository->expects(self::once())->method('deleteForSlot')->with('s.textmedia-left')->willReturn(4);
        $this->pageRefresher->expects(self::once())->method('refresh')->with([3, 7])->willReturn(2);

        $tester = $this->runCommand(['target' => 's.textmedia-left']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('refreshed 2 pages', $tester->getDisplay());
    }

    public function testResettingAnElementRefreshesItsPages(): void
    {
        $this->pageRefresher->method('pagesOfElement')->with(123)->willReturn([1, 9]);
        $this->repository->method('deleteForElement')->with(123)->willReturn(2);
        $this->pageRefresher->expects(self::once())->method('refresh')->with([1, 9])->willReturn(2);

        self::assertSame(Command::SUCCESS, $this->runCommand(['target' => '123'])->getStatusCode());
    }

    public function testResettingAllRefreshesEveryKnownPage(): void
    {
        $this->pageRefresher->method('allPages')->willReturn([1, 2, 3]);
        $this->repository->method('deleteAll')->willReturn(10);
        $this->pageRefresher->expects(self::once())->method('refresh')->with([1, 2, 3])->willReturn(3);

        self::assertSame(Command::SUCCESS, $this->runCommand(['--all' => true])->getStatusCode());
    }

    public function testNothingDeletedRefreshesNothing(): void
    {
        $this->pageRefresher->method('pagesOfSlot')->willReturn([3]);
        $this->repository->method('deleteForSlot')->willReturn(0);
        $this->pageRefresher->expects(self::never())->method('refresh');

        self::assertSame(Command::SUCCESS, $this->runCommand(['target' => 's.hero'])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(new ImageMeasureResetCommand($this->repository, $this->pageRefresher));
        $tester->execute($input);

        return $tester;
    }
}
