<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\IconProvider;

use Maispace\MaiAssets\Collector\SvgSpriteCollector;
use Maispace\MaiAssets\IconProvider\MaiSvgIconProvider;
use PHPUnit\Framework\TestCase;

final class MaiSvgIconProviderTest extends TestCase
{
    public function testInlineMarkupStripsOnlyTheRootDimensions(): void
    {
        // Regression: width/height were removed from every element, which collapsed <rect>s.
        $file = sys_get_temp_dir() . '/mai_icon_' . uniqid('', true) . '.svg';
        file_put_contents($file, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="24" height=\'24\' viewBox="0 0 24 24"><rect width="10" height="4"/></svg>');

        try {
            $provider = new MaiSvgIconProvider((new \ReflectionClass(SvgSpriteCollector::class))->newInstanceWithoutConstructor());
            $markup = (new \ReflectionMethod(MaiSvgIconProvider::class, 'generateInlineMarkup'))->invoke($provider, ['source' => $file]);
        } finally {
            unlink($file);
        }

        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="10" height="4"/></svg>', $markup);
    }
}
