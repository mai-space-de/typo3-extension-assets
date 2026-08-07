<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Service;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Maispace\MaiAssets\Service\WarmupHttpClientService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * GuzzleClientFactory is a readonly TYPO3 core class and cannot be mocked,
 * so the mock HTTP responses are injected the way TYPO3 itself supports:
 * via a pre-built HandlerStack in $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'].
 */
final class WarmupHttpClientServiceTest extends TestCase
{
    private ?array $originalHttpConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHttpConfig = $GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalHttpConfig === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = $this->originalHttpConfig;
        }
        parent::tearDown();
    }

    private function createSubjectWithMockedResponses(array $responsesOrExceptions): WarmupHttpClientService
    {
        $mockHandler = new MockHandler($responsesOrExceptions);

        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'verify' => true,
            'handler' => HandlerStack::create($mockHandler),
        ];

        return new WarmupHttpClientService(new GuzzleClientFactory());
    }

    public function testRunBatchReturnsEmptyArrayForEmptyUrls(): void
    {
        $subject = $this->createSubjectWithMockedResponses([]);

        self::assertSame([], $subject->runBatch([]));
    }

    public function testRunBatchReturnsStatusCodesIndexedLikeInput(): void
    {
        $subject = $this->createSubjectWithMockedResponses([
            new Response(200),
            new Response(404),
        ]);

        $results = $subject->runBatch([
            10 => 'https://example.test/a',
            20 => 'https://example.test/b',
        ]);

        self::assertSame(200, $results[10]);
        self::assertSame(404, $results[20]);
    }

    public function testRunBatchReportsZeroForFailedRequests(): void
    {
        $subject = $this->createSubjectWithMockedResponses([
            new ConnectException('connection refused', new Request('GET', 'https://example.test/a')),
        ]);

        $results = $subject->runBatch(['a' => 'https://example.test/a']);

        self::assertSame(0, $results['a']);
    }

    public function testRunBatchHandlesMixedSuccessAndFailure(): void
    {
        $subject = $this->createSubjectWithMockedResponses([
            new Response(200),
            new ConnectException('connection refused', new Request('GET', 'https://example.test/b')),
            new Response(500),
        ]);

        $results = $subject->runBatch([
            'ok' => 'https://example.test/a',
            'fail' => 'https://example.test/b',
            'server-error' => 'https://example.test/c',
        ]);

        self::assertSame(200, $results['ok']);
        self::assertSame(0, $results['fail']);
        self::assertSame(500, $results['server-error']);
    }
}
