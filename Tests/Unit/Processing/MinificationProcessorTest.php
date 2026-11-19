<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Processing;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\AfterCssProcessedEvent;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class MinificationProcessorTest extends TestCase
{
    private string $root;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [];
        $this->root = sys_get_temp_dir() . '/mai_assets_minify_' . uniqid('', true);
        mkdir($this->root . '/public', 0777, true);
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $this->root,
            $this->root . '/public',
            $this->root . '/var',
            $this->root . '/config',
            $this->root . '/public/index.php',
            'UNIX',
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testCompiledContentOfTheSameSourceIsNotServedFromAStaleCache(): void
    {
        // Regression: the cache key was the hash of the root .scss file only, so a changed
        // partial produced new compiled CSS but the minifier returned the old output.
        $source = $this->root . '/public/main.scss';
        file_put_contents($source, '@import "variables";');

        $subject = $this->subject();

        self::assertSame('a{color:red}', $subject->process("a {\n  color: red;\n}", $source));
        self::assertSame('a{color:blue}', $subject->process("a {\n  color: blue;\n}", $source));
    }

    public function testInjectionEventIsLeftToTheInjectingViewHelper(): void
    {
        $source = $this->root . '/public/main.css';
        file_put_contents($source, 'a { color: red; }');

        $this->subject()->process('a { color: red; }', $source);

        $types = array_map(get_class(...), $this->dispatched);
        self::assertContains(AfterCssProcessedEvent::class, $types);
        self::assertNotContains(BeforeAssetInjectionEvent::class, $types);
    }

    private function subject(): MinificationProcessor
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->dispatched[] = $event;

            return $event;
        });

        return new MinificationProcessor(
            $dispatcher,
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
        );
    }
}
