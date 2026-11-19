<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Video;

use Maispace\MaiAssets\ViewHelpers\Video\VideoViewHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoViewHelperTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function facades(): array
    {
        return [
            'youtube' => ['youtubeId', 'mai-video-facade mai-video-youtube hero-video'],
            'vimeo' => ['vimeoId', 'mai-video-facade mai-video-vimeo hero-video'],
        ];
    }

    #[DataProvider('facades')]
    public function testFacadeHasOneClassAttributeWithTheCustomClass(string $idArgument, string $expectedClass): void
    {
        $viewHelper = new VideoViewHelper();
        $viewHelper->setArguments(array_merge([
            'file' => null,
            'youtubeId' => '',
            'vimeoId' => '',
            'poster' => null,
            'isCritical' => false,
            'type' => 'content',
            'title' => 'Intro',
            'class' => 'hero-video',
        ], [$idArgument => 'abc123']));

        $html = $viewHelper->render();
        $root = substr($html, 0, (int) strpos($html, '>') + 1);

        self::assertSame(1, substr_count($root, 'class='));
        self::assertStringContainsString('class="' . $expectedClass . '"', $root);
    }
}
