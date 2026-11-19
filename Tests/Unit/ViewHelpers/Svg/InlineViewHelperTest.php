<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\ViewHelpers\Svg;

use Maispace\MaiAssets\ViewHelpers\Svg\InlineViewHelper;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

final class InlineViewHelperTest extends TestCase
{
    public function testEachEmbeddingGetsOwnInternalIdsButKeepsTheRootId(): void
    {
        $file = sys_get_temp_dir() . '/mai_inline_' . uniqid('', true) . '.svg';
        file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg" id="logo" viewBox="0 0 10 10"><defs><clipPath id="c"><rect width="5" height="5"/></clipPath></defs><g clip-path="url(#c)"/></svg>');

        try {
            $first = $this->render($file);
            $second = $this->render($file);
        } finally {
            unlink($file);
        }

        foreach ([$first, $second] as $svg) {
            self::assertMatchesRegularExpression('/^<svg[^>]*\bid="logo"/', $svg);
            self::assertMatchesRegularExpression('/<clipPath id="(svg[0-9a-f]{8}-c)">.*clip-path="url\(#\1\)"/', $svg);
        }
        preg_match('/<clipPath id="([^"]+)"/', $first, $a);
        preg_match('/<clipPath id="([^"]+)"/', $second, $b);
        self::assertNotSame($a[1], $b[1]);
    }

    private function render(string $file): string
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willThrowException(new \RuntimeException('no cache', 1763900001));

        $viewHelper = new InlineViewHelper($cacheManager);
        $viewHelper->setArguments(['src' => $file, 'title' => '', 'ariaLabel' => '', 'class' => '', 'stripDimensions' => true]);

        return $viewHelper->render();
    }
}
