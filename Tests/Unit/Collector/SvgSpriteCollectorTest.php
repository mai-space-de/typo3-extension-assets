<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Collector;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\EventListener\SvgSpriteInjectionListener;
use Maispace\MaiAssets\Collector\FontPreloadCollector;
use Maispace\MaiAssets\Utility\SvgIdScoper;
use Maispace\MaiAssets\ViewHelpers\Svg\IconViewHelper;
use Maispace\MaiAssets\ViewHelpers\Svg\SpriteViewHelper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;

final class SvgSpriteCollectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mai_sprite_' . uniqid('', true);
        mkdir($this->dir);
        $gradientIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="a"/></defs><path fill="url(#a)" d="M0 0"/></svg>';
        file_put_contents($this->dir . '/home.svg', $gradientIcon);
        file_put_contents($this->dir . '/user.svg', $gradientIcon);
        file_put_contents(
            $this->dir . '/ns.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" viewBox="0 0 8 8"><inkscape:g/><circle r="4"/></svg>',
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testInternalIdsAreScopedPerSymbol(): void
    {
        $collector = $this->collector(['home', 'user']);

        $sprite = $collector->build();

        self::assertStringContainsString('<symbol id="home" viewBox="0 0 24 24">', $sprite);
        self::assertStringContainsString('id="home-a"', $sprite);
        self::assertStringContainsString('url(#home-a)', $sprite);
        self::assertStringContainsString('id="user-a"', $sprite);
        self::assertStringNotContainsString('id="a"', $sprite);
    }

    public function testChildrenOfOtherNamespacesAreKept(): void
    {
        $sprite = $this->collector(['ns'])->build();

        self::assertStringContainsString('inkscape:g', $sprite);
        self::assertStringContainsString('<circle', $sprite);
    }

    public function testSpriteViewHelperPositionIsFilledOnceWithAllIcons(): void
    {
        // Regression: mai:svg.sprite built the sprite early and the listener added a second one.
        $collector = $this->collector(['home']);
        $html = '<html><body>' . (new SpriteViewHelper($collector))->render() . '<p>x</p>' . SvgSpriteCollector::PLACEHOLDER . '</body></html>';
        $collector->register('user', $this->dir . '/user.svg');

        $listener = new SvgSpriteInjectionListener(
            $collector,
            $this->fontCollector(),
            new ExtensionConfigurationDiscovery(),
            new EarlyHintCandidateCollector(),
        );
        $event = new AfterCacheableContentIsGeneratedEvent(new ServerRequest('https://example.test/'), $html, 'id', true);
        $this->withoutDiscovery(fn() => $listener($event));

        $result = $event->getContent();
        self::assertSame(1, substr_count($result, 'style="display:none"'));
        self::assertStringStartsWith('<html><body><svg', $result);
        self::assertStringContainsString('<symbol id="user"', $result);
        self::assertStringNotContainsString(SvgSpriteCollector::PLACEHOLDER, $result);
    }

    public function testIconsRenderedAfterTheSpriteBringTheirSymbolOnce(): void
    {
        // USER_INT parts render after the sprite was built.
        $collector = $this->collector(['home']);
        $collector->build();

        $first = $this->renderIcon($collector, 'user');
        $second = $this->renderIcon($collector, 'user');
        $alreadyInSprite = $this->renderIcon($collector, 'home');

        self::assertStringContainsString('<symbol id="user"', $first);
        self::assertStringNotContainsString('<symbol', $second);
        self::assertStringNotContainsString('<symbol', $alreadyInSprite);
    }

    public function testScoperKeepsUnrelatedIdsAndUpdatesAriaReferences(): void
    {
        self::assertSame(
            '<title id="p-t"/><g aria-labelledby="p-t other"/><use href="#p-t"/><b data-id="t"/>',
            SvgIdScoper::scope('<title id="t"/><g aria-labelledby="t other"/><use href="#t"/><b data-id="t"/>', 'p'),
        );
    }

    /**
     * @param list<string> $icons
     */
    private function collector(array $icons): SvgSpriteCollector
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $collector = new SvgSpriteCollector($dispatcher, new ExtensionConfigurationDiscovery());
        (new \ReflectionProperty(SvgSpriteCollector::class, 'discovered'))->setValue($collector, true);
        foreach ($icons as $icon) {
            $collector->register($icon, $this->dir . '/' . $icon . '.svg');
        }

        return $collector;
    }

    private function renderIcon(SvgSpriteCollector $collector, string $identifier): string
    {
        $viewHelper = new IconViewHelper($collector);
        $viewHelper->setRenderingContext(new RenderingContext());
        $viewHelper->setArguments([
            'identifier' => $identifier,
            'source' => $this->dir . '/' . $identifier . '.svg',
            'label' => '',
            'class' => '',
            'size' => '',
        ]);

        return $viewHelper->render();
    }

    private function fontCollector(): FontPreloadCollector
    {
        $collector = (new \ReflectionClass(FontPreloadCollector::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(FontPreloadCollector::class, 'discovered'))->setValue($collector, true);

        return $collector;
    }

    private function withoutDiscovery(callable $callback): void
    {
        // Preconnect discovery reads the loaded extensions: none here.
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);
        ExtensionManagementUtility::setPackageManager($packageManager);

        $callback();
    }
}
