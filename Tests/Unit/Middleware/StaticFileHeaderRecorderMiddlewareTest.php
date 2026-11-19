<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Middleware;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Middleware\StaticFileHeaderRecorderMiddleware;
use Maispace\MaiAssets\StaticFileCache\StaticFileCacheDirectory;
use Maispace\MaiAssets\StaticFileCache\StaticFileWriteRegistry;
use Maispace\MaiAssets\StaticFileCache\StaticHtmlWriterService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;

final class StaticFileHeaderRecorderMiddlewareTest extends TestCase
{
    private string $root;
    private StaticHtmlWriterService $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mai_assets_recorder_' . uniqid('', true);
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = ['enableCompression' => false];
        $configuration = new ExtensionConfiguration((new \ReflectionClass(Typo3ExtensionConfiguration::class))->newInstanceWithoutConstructor());
        $this->writer = new StaticHtmlWriterService(new StaticFileCacheDirectory($configuration), $configuration);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets']);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testHeadersOfTheResponseAreStoredForTheWrittenFile(): void
    {
        $variant = str_repeat('c', 20);
        $this->writer->writeByPageId('<!DOCTYPE html><html></html>', 5, 1, $variant);
        $registry = new StaticFileWriteRegistry();
        $registry->remember(5, 1, $variant, 'NONCEnonceNONCE', 2000000000);

        $this->process($registry, new HtmlResponse('x', 200, ['X-Frame-Options' => 'DENY', 'Set-Cookie' => 'a=b']));

        self::assertSame(
            ['headers' => ['X-Frame-Options' => ['DENY']], 'nonce' => 'NONCEnonceNONCE', 'expires' => 2000000000],
            $this->writer->readMetadata(5, 1, $variant),
        );
        self::assertNull($registry->take());
    }

    public function testNothingIsStoredForErrorsOrWithoutWrittenFile(): void
    {
        $this->writer->writeByPageId('<!DOCTYPE html><html></html>', 5, 0);
        $registry = new StaticFileWriteRegistry();
        $registry->remember(5, 0, '', '', 0);

        $this->process($registry, new HtmlResponse('error', 500, ['X-Frame-Options' => 'DENY']));
        $this->process(new StaticFileWriteRegistry(), new HtmlResponse('x', 200, ['X-Frame-Options' => 'DENY']));

        self::assertSame([], $this->writer->readMetadata(5, 0, '')['headers']);
    }

    public function testRewritingTheHtmlDropsOutdatedMetadata(): void
    {
        // A new rendering has a new nonce; old metadata must never be combined with it.
        $this->writer->writeByPageId('<!DOCTYPE html><html>v1</html>', 5, 0);
        $this->writer->writeMetadata(5, 0, '', ['headers' => [], 'nonce' => 'OLD', 'expires' => 1]);

        $this->writer->writeByPageId('<!DOCTYPE html><html>v2</html>', 5, 0);

        self::assertSame('', $this->writer->readMetadata(5, 0, '')['nonce']);
    }

    private function process(StaticFileWriteRegistry $registry, HtmlResponse $response): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        (new StaticFileHeaderRecorderMiddleware($registry, $this->writer))->process(new ServerRequest('https://example.test/'), $handler);
    }
}
