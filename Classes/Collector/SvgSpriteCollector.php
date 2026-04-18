<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Collector;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\Event\AfterSpriteBuiltEvent;
use Maispace\MaiAssets\Event\BeforeSpriteSymbolRegisteredEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\SingletonInterface;

final class SvgSpriteCollector extends AbstractAssetCollector implements SingletonInterface
{
    private bool $discovered = false;

    public function register(string $identifier, string $filePath): void
    {
        $event = new BeforeSpriteSymbolRegisteredEvent($identifier, $filePath);
        $this->eventDispatcher->dispatch($event);
        if (!$event->isCancelled()) {
            parent::register($identifier, $filePath);
        }
    }

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ExtensionConfigurationDiscovery $extensionConfigurationDiscovery,
    ) {}

    public function build(): string
    {
        if (!$this->discovered) {
            $this->discovered = true;
            foreach ($this->extensionConfigurationDiscovery->discoverSpriteIcons() as $identifier => $svgPath) {
                $this->register($identifier, $svgPath);
            }
        }

        $assets = $this->getAll();
        if ($assets === []) {
            return '';
        }

        $stripAttributes = $this->extensionConfiguration->getSvgStripAttributes();
        $symbols = '';

        foreach ($assets as $identifier => $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $svgContent = file_get_contents($filePath);
            if ($svgContent === false) {
                continue;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($svgContent);
            if ($xml === false) {
                libxml_clear_errors();
                continue;
            }

            // Extract viewBox
            $viewBox = (string)($xml->attributes()['viewBox'] ?? '');

            // Strip attributes from root SVG element
            foreach ($stripAttributes as $attr) {
                unset($xml->attributes()->$attr);
            }

            // Extract inner XML from root SVG
            $innerXml = '';
            foreach ($xml->children() as $child) {
                $innerXml .= $child->asXML();
            }

            $viewBoxAttr = $viewBox !== '' ? ' viewBox="' . htmlspecialchars($viewBox, ENT_XML1) . '"' : '';
            $symbols .= '<symbol id="' . htmlspecialchars($identifier, ENT_XML1) . '"' . $viewBoxAttr . '>'
                . $innerXml
                . '</symbol>';
        }

        if ($symbols === '') {
            return '';
        }

        $sprite = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">'
            . $symbols
            . '</svg>';

        $event = new AfterSpriteBuiltEvent($sprite);
        $this->eventDispatcher->dispatch($event);

        return $event->getSprite();
    }
}
