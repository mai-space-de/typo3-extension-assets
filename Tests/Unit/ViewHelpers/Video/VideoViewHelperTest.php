<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Video;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\ViewHelpers\Video\VideoViewHelper;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Page\AssetCollector;
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

    public function testYoutubePosterFallsBackToHqdefault(): void
    {
        $html = $this->render(['youtubeId' => 'dQw4w9WgXcQ']);

        self::assertStringContainsString('src="https://img.youtube.com/vi/dQw4w9WgXcQ/maxresdefault.jpg"', $html);
        self::assertStringContainsString('data-fallback-src="https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg"', $html);
        self::assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1" title="Intro"', $html);
    }

    public function testFacadeIncludesTheVideoScriptOnce(): void
    {
        $assetCollector = new AssetCollector();

        $this->render(['youtubeId' => 'dQw4w9WgXcQ'], $assetCollector, []);
        $this->render(['vimeoId' => '76979871'], $assetCollector, []);

        self::assertSame(
            ['mai-video' => ['source' => ExtensionConfiguration::DEFAULT_VIDEO_SCRIPT, 'attributes' => ['defer' => 'defer'], 'options' => []]],
            $assetCollector->getJavaScripts(),
        );
    }

    public function testThemeScriptReplacesTheDefaultAndEmptyDisablesIt(): void
    {
        $theme = new AssetCollector();
        $this->render(['youtubeId' => 'dQw4w9WgXcQ'], $theme, ['videoScript' => 'https://cdn.example/video.js']);
        self::assertSame('https://cdn.example/video.js', $theme->getJavaScripts()['mai-video']['source']);
        self::assertSame(['external' => true], $theme->getJavaScripts()['mai-video']['options']);

        $none = new AssetCollector();
        $this->render(['youtubeId' => 'dQw4w9WgXcQ'], $none, ['videoScript' => '']);
        self::assertSame([], $none->getJavaScripts());
    }

    public function testThemeRegistrationWithTheSameIdentifierWins(): void
    {
        $assetCollector = new AssetCollector();
        $assetCollector->addJavaScript('mai-video', 'EXT:site/Resources/Public/JavaScript/video.js');

        $this->render(['youtubeId' => 'dQw4w9WgXcQ'], $assetCollector, []);

        self::assertSame('EXT:site/Resources/Public/JavaScript/video.js', $assetCollector->getJavaScripts()['mai-video']['source']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $configuration extension configuration; null = no script handling
     */
    private function render(array $arguments, ?AssetCollector $assetCollector = null, ?array $configuration = null): string
    {
        $extensionConfiguration = null;
        if ($configuration !== null) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = $configuration;
            $extensionConfiguration = new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor());
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        }
        $viewHelper = new VideoViewHelper($assetCollector, $extensionConfiguration);
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
