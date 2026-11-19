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
        ], [$idArgument => $idArgument === 'youtubeId' ? 'dQw4w9WgXcQ' : '76979871']));

        $html = $viewHelper->render();
        $root = substr($html, 0, (int) strpos($html, '>') + 1);

        self::assertSame(1, substr_count($root, 'class='));
        self::assertStringContainsString('class="' . $expectedClass . '"', $root);
    }

    public function testCriticalFacadeLoadsItsPosterEagerly(): void
    {
        $html = $this->render(['youtubeId' => 'dQw4w9WgXcQ', 'isCritical' => true]);
        self::assertStringContainsString('loading="eager" fetchpriority="high"', $html);

        $html = $this->render(['youtubeId' => 'dQw4w9WgXcQ']);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testInvalidVideoIdsAreRejected(): void
    {
        self::assertSame('', $this->render(['youtubeId' => 'abc/../../x?a=1']));
        self::assertSame('', $this->render(['vimeoId' => '123?autoplay=0&x']));
        self::assertStringContainsString('player.vimeo.com/video/76979871?autoplay=1', $this->render(['vimeoId' => '76979871']));
    }

    public function testCodecVariantsAreFoundForEncodedFileNames(): void
    {
        $root = sys_get_temp_dir() . '/mai_video_' . uniqid('', true);
        mkdir($root . '/public/fileadmin', 0777, true);
        \TYPO3\CMS\Core\Core\Environment::initialize(
            new \TYPO3\CMS\Core\Core\ApplicationContext('Testing'),
            true,
            true,
            $root,
            $root . '/public',
            $root . '/var',
            $root . '/config',
            $root . '/public/index.php',
            'UNIX',
        );
        touch($root . '/public/fileadmin/my clip.av1.mp4');
        $file = new class {
            public function getPublicUrl(): string
            {
                return '/fileadmin/my%20clip.mp4';
            }
        };

        try {
            $html = $this->render(['file' => $file]);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        self::assertStringContainsString('<source src="/fileadmin/my%20clip.av1.mp4" type="video/mp4; codecs=av01.0.05M.08">', $html);
        self::assertStringNotContainsString('hevc', $html);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(array $arguments): string
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
            'class' => '',
        ], $arguments));

        return $viewHelper->render();
    }
}
