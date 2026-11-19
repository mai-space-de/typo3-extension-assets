<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Command;

use Maispace\MaiAssets\Command\ImageMeasureWarmupCommand;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementPageRefresher;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfileResolver;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementRepository;
use Maispace\MaiAssets\Service\ImageVariantService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class ImageMeasureWarmupCommandTest extends TestCase
{
    public function testPruningRefreshesPagesOfSlotsWhoseProfileChanged(): void
    {
        $repository = $this->createMock(ImageMeasurementRepository::class);
        $repository->method('getSlotKeysOlderThan')->willReturn(['s.changed', 's.same']);
        $repository->expects(self::once())->method('deleteOlderThan')->willReturn(5);
        $repository->method('getSlotKeys')->willReturn([]);

        $profileResolver = $this->createMock(ImageMeasurementProfileResolver::class);
        $profileResolver->method('signature')->willReturnOnConsecutiveCalls('a', 'b', '', 'b');

        $pageRefresher = $this->createMock(ImageMeasurementPageRefresher::class);
        $pageRefresher->method('pagesOfSlot')->with('s.changed')->willReturn([4, 6]);
        $pageRefresher->expects(self::once())->method('refresh')->with([4, 6])->willReturn(2);

        $tester = new CommandTester(new ImageMeasureWarmupCommand(
            $repository,
            $profileResolver,
            (new \ReflectionClass(ImageVariantService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor(),
            $pageRefresher,
        ));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Pruned 5 stale measurement rows, refreshed 2 pages.', $tester->getDisplay());
    }

    public function testKeepDaysZeroDisablesPruning(): void
    {
        $repository = $this->createMock(ImageMeasurementRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');
        $repository->method('getSlotKeys')->willReturn([]);

        $tester = new CommandTester(new ImageMeasureWarmupCommand(
            $repository,
            $this->createMock(ImageMeasurementProfileResolver::class),
            (new \ReflectionClass(ImageVariantService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor(),
            $this->createMock(ImageMeasurementPageRefresher::class),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--keep-days' => '0']));
    }
}
