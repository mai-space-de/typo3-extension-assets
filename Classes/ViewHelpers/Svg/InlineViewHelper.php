<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Svg;

use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Maispace\MaiAssets\Utility\SvgIdScoper;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class InlineViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('src', 'string', 'EXT: path or absolute path to SVG file', true);
        $this->registerArgument('title', 'string', 'Injects a <title> element for accessibility', false, '');
        $this->registerArgument('ariaLabel', 'string', 'aria-label attribute on the <svg> element', false, '');
        $this->registerArgument('class', 'string', 'CSS class attribute on the <svg> element', false, '');
        $this->registerArgument('stripDimensions', 'bool', 'Strip width/height from <svg> root (CSS sizing preferred)', false, true);
    }

    public function render(): string
    {
        $src = (string) $this->arguments['src'];
        $title = (string) $this->arguments['title'];
        $ariaLabel = (string) $this->arguments['ariaLabel'];
        $class = (string) $this->arguments['class'];
        $stripDimensions = (bool) $this->arguments['stripDimensions'];

        $resolvedPath = $this->requireFile($src);

        $cacheKey = 'svginline_' . (string) hash_file('sha256', $resolvedPath) . '_' . md5($title . $ariaLabel . $class . ($stripDimensions ? '1' : '0'));

        try {
            $cache = $this->cacheManager->getCache('mai_assets');
            if ($cache->has($cacheKey)) {
                return self::scopeIds((string) $cache->get($cacheKey));
            }
        } catch (\Exception) {
            $cache = null;
        }

        $svgContent = (string) file_get_contents($resolvedPath);

        // Strip XML declaration
        $svgContent = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $svgContent) ?? $svgContent;

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($svgContent);
        if ($xml === false) {
            libxml_clear_errors();
            return '';
        }

        if ($stripDimensions) {
            unset($xml->attributes()->width, $xml->attributes()->height);
        }

        if ($class !== '') {
            $xml->attributes()->class = $class;
        }

        if ($ariaLabel !== '') {
            $xml->attributes()->{'aria-label'} = $ariaLabel;
            $xml->attributes()->role = 'img';
        }

        if ($title !== '') {
            // Inject <title> as first child
            $titleXml = new \SimpleXMLElement('<title>' . htmlspecialchars($title, ENT_XML1) . '</title>');
            $dom = dom_import_simplexml($xml);
            $titleDom = dom_import_simplexml($titleXml);
            $titleDom = $dom->ownerDocument->importNode($titleDom, true);
            $dom->insertBefore($titleDom, $dom->firstChild);
            $xml = simplexml_import_dom($dom);
        }

        $result = $xml->asXML();
        if ($result === false) {
            return '';
        }

        // Strip XML declaration that asXML() may re-add
        $result = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $result) ?? $result;

        if (isset($cache)) {
            $cache->set($cacheKey, $result);
        }

        return self::scopeIds($result);
    }

    /**
     * Each embedding gets its own id prefix: the same SVG twice on a page would
     * otherwise share gradient/clip-path ids (the second one references the first).
     */
    private static function scopeIds(string $svg): string
    {
        if (!str_contains($svg, 'id=')) {
            return $svg;
        }

        $prefix = 'svg' . bin2hex(random_bytes(4));
        $scoped = SvgIdScoper::scope($svg, $prefix);

        // The root element's own id is addressed from outside (CSS, JS, anchors): keep it.
        if (preg_match('/^\s*<svg\b[^>]*\bid\s*=\s*(["\'])([^"\']+)\1/', $svg, $root) === 1) {
            $scoped = (string) preg_replace(
                '/^(\s*<svg\b[^>]*\bid\s*=\s*["\'])' . preg_quote($prefix . '-' . $root[2], '/') . '(["\'])/',
                '${1}' . $root[2] . '${2}',
                $scoped,
                1,
            );
        }

        return $scoped;
    }
}
