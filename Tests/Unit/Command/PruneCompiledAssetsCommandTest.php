<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Command;

use Maispace\MaiAssets\Command\PruneCompiledAssetsCommand;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class PruneCompiledAssetsCommandTest extends TestCase
{
    private string $root;
    private string $compiled;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mai_assets_prune_' . uniqid('', true);
        $this->compiled = $this->root . '/public/' . PruneCompiledAssetsCommand::COMPILED_DIR;
        mkdir($this->compiled, 0777, true);
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

    public function testDeletesOldFilesAndFlushesPagesThatMayReferenceThem(): void
    {
        file_put_contents($this->compiled . 'old.css', 'a{}');
        touch($this->compiled . 'old.css', time() - 7200);
        file_put_contents($this->compiled . 'new.css', 'b{}');

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::once())->method('flushCachesInGroup')->with('pages');
        $removal = $this->createMock(StaticFileRemovalService::class);
        $removal->expects(self::once())->method('purgeAll');

        $tester = $this->tester(0, $cacheManager, $removal);
        $tester->execute(['--older-than' => '3600']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->compiled . 'old.css');
        self::assertFileExists($this->compiled . 'new.css');
    }

    public function testNothingToDeleteKeepsTheCaches(): void
    {
        file_put_contents($this->compiled . 'new.css', 'b{}');

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::never())->method('flushCachesInGroup');
        $removal = $this->createMock(StaticFileRemovalService::class);
        $removal->expects(self::never())->method('purgeAll');

        $tester = $this->tester(86400, $cacheManager, $removal);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testRequiresAnAge(): void
    {
        $tester = $this->tester(0, $this->createMock(CacheManager::class), $this->createMock(StaticFileRemovalService::class));

        self::assertSame(Command::INVALID, $tester->execute([]));
    }

    private function tester(int $lifetime, CacheManager $cacheManager, StaticFileRemovalService $removal): CommandTester
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['processingCacheLifetime' => $lifetime];

        return new CommandTester(new PruneCompiledAssetsCommand(
            new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor()),
            $cacheManager,
            $removal,
        ));
    }
}
