<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Tests\Unit\Configuration;

use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ExtensionConfigurationDiscoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    public function testCurrentSiteIsTheSiteOfTheRequest(): void
    {
        // Regression: the first configured site was used for every request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://second.example/'))
            ->withAttribute('site', new Site('second', 1, ['base' => 'https://second.example/']));

        self::assertSame('second', $this->currentSite());
    }

    public function testWithoutRequestOnlyWildcardConfigurationsApply(): void
    {
        self::assertSame('', $this->currentSite());
    }

    private function currentSite(): string
    {
        $method = new \ReflectionMethod(ExtensionConfigurationDiscovery::class, 'getCurrentSiteIdentifier');

        return $method->invoke(new ExtensionConfigurationDiscovery());
    }
}
