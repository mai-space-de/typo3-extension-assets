<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ImageMeasurement;

use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementProfileResolver;
use Maispace\MaiAssets\ImageMeasurement\ImageMeasurementScale;
use PHPUnit\Framework\TestCase;

final class ImageMeasurementProfileResolverTest extends TestCase
{
    public function testReturnsNullWithoutEnoughSamples(): void
    {
        self::assertNull(ImageMeasurementProfileResolver::buildProfile([]));
        self::assertNull(ImageMeasurementProfileResolver::buildProfile([480 => [400 => 2]]));
    }

    public function testBuildsSizesAndWidthsFromMeasuredSteps(): void
    {
        $profile = ImageMeasurementProfileResolver::buildProfile([
            480 => [400 => 10],
            1440 => [640 => 10],
        ]);

        self::assertNotNull($profile);
        // Steps without samples take the wider neighbour.
        self::assertSame('(max-width: 480px) 400px, 640px', $profile->sizes);
        self::assertSame([400, 640, 800, 1280], $profile->widths);
    }

    public function testNarrowViewportKeepsItsOwnWidth(): void
    {
        $profile = ImageMeasurementProfileResolver::buildProfile([
            320 => [320 => 5],
            480 => [400 => 5],
            2560 => [800 => 5],
        ]);

        self::assertNotNull($profile);
        self::assertStringStartsWith('(max-width: 320px) 320px, (max-width: 480px) 400px, ', $profile->sizes);
        self::assertStringEndsWith(', 800px', $profile->sizes);
    }

    public function testCoverageIgnoresOutliers(): void
    {
        $profile = ImageMeasurementProfileResolver::buildProfile([
            1440 => [640 => 95, 3840 => 5],
        ]);

        self::assertNotNull($profile);
        self::assertSame('640px', $profile->sizes);
    }

    public function testWidthsAreCappedToOriginalAndStayOnLadder(): void
    {
        $profile = ImageMeasurementProfileResolver::buildProfile([1440 => [1200 => 10]], 1500);

        self::assertNotNull($profile);
        self::assertSame([1200, 1500], $profile->widths);

        $uncapped = ImageMeasurementProfileResolver::buildProfile([1440 => [1200 => 10]]);
        self::assertNotNull($uncapped);
        foreach ($uncapped->widths as $width) {
            self::assertContains($width, ImageMeasurementScale::WIDTH_LADDER);
        }
    }

    public function testScaleRoundsUpAndRejectsOutOfRange(): void
    {
        self::assertSame(400, ImageMeasurementScale::ceilToLadder(375));
        self::assertSame(3840, ImageMeasurementScale::ceilToLadder(3840));
        self::assertNull(ImageMeasurementScale::ceilToLadder(3841));
        self::assertNull(ImageMeasurementScale::ceilToLadder(0));
        self::assertSame(320, ImageMeasurementScale::viewportStep(200));
        self::assertSame(480, ImageMeasurementScale::viewportStep(375 + 100));
        self::assertSame(2720, ImageMeasurementScale::viewportStep(3000));
    }
}
