<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Collector;

use Maispace\MaiAssets\Configuration\ExtensionConfigurationDiscovery;
use Maispace\MaiAssets\Event\AfterSpriteBuiltEvent;
use Maispace\MaiAssets\Event\BeforeSpriteSymbolRegisteredEvent;
use Maispace\MaiAssets\Utility\SvgIdScoper;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\SingletonInterface;

final class SvgSpriteCollector extends AbstractAssetCollector implements SingletonInterface
{
    private bool $discovered = false;

    #[\Override]
    public function register(string $identifier, string $filePath): void
    {
        $event = new BeforeSpriteSymbolRegisteredEvent($identifier, $filePath);
        $this->eventDispatcher->dispatch($event);
        if (!$event->isCancelled()) {
            parent::register($identifier, $filePath);
        }
    }

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ExtensionConfigurationDiscovery $extensionConfigurationDiscovery,
    ) {}

    /** Placeholder written by mai:svg.sprite; replaced by the sprite once all icons are known. */
    public const string PLACEHOLDER = '<!--mai-svg-sprite-->';

    /** @var array<string, true> identifiers already delivered in this request */
    private array $delivered = [];

    private bool $built = false;

    public function build(): string
    {
        if (!$this->discovered) {
            $this->discovered = true;
            foreach ($this->extensionConfigurationDiscovery->discoverSpriteIcons() as $identifier => $svgPath) {
                $this->register($identifier, $svgPath);
            }
        }

        $this->built = true;
        $sprite = $this->buildSymbols(array_keys($this->getAll()));
        if ($sprite === '') {
            return '';
        }

        $event = new AfterSpriteBuiltEvent($sprite);
        $this->eventDispatcher->dispatch($event);

        return $event->getSprite();
    }

    /**
     * True once the page sprite was built. Icons registered later (non-cached
     * USER_INT parts render after the sprite) must bring their own symbol.
     */
    public function isBuilt(): bool
    {
        return $this->built;
    }

    public function isDelivered(string $identifier): bool
    {
        return isset($this->delivered[$identifier]);
    }

    /**
     * Hidden <svg> with the symbols of the given (registered, not yet delivered) icons.
     *
     * @param list<string> $identifiers
     */
    public function buildSymbols(array $identifiers): string
    {
        $assets = $this->getAll();
        $symbols = '';

        foreach ($identifiers as $identifier) {
            if (!isset($assets[$identifier]) || isset($this->delivered[$identifier])) {
                continue;
            }
            $symbol = $this->buildSymbol($identifier, $assets[$identifier]);
            if ($symbol !== '') {
                $symbols .= $symbol;
                $this->delivered[$identifier] = true;
            }
        }

        return $symbols === ''
            ? ''
            : '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">' . $symbols . '</svg>';
    }

    private function buildSymbol(string $identifier, string $filePath): string
    {
        if (!is_file($filePath)) {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML((string) file_get_contents($filePath), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $document->documentElement : null;
        if ($root === null || strtolower($root->localName) !== 'svg') {
            return '';
        }

        // All child nodes, including elements of other XML namespaces (SimpleXML's
        // children() only returned the default namespace).
        $innerXml = '';
        foreach ($root->childNodes as $child) {
            $innerXml .= $document->saveXML($child);
        }

        $viewBox = $root->getAttribute('viewBox');
        $viewBoxAttr = $viewBox !== '' ? ' viewBox="' . htmlspecialchars($viewBox, ENT_XML1) . '"' : '';

        // Internal ids (gradients, clip paths) are scoped per symbol, otherwise icons of
        // the same sprite reference each other's definitions.
        return '<symbol id="' . htmlspecialchars($identifier, ENT_XML1) . '"' . $viewBoxAttr . '>'
            . SvgIdScoper::scope($innerXml, $identifier)
            . '</symbol>';
    }
}
