<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use TYPO3\CMS\Core\Information\Typo3Version;
use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Maispace\MaiAssets\Utility\AttributeUtility;
use Maispace\MaiAssets\Utility\HtmlInjectionUtility;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class CssViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly CompiledAssetPublisher $compiledAssetPublisher,
        private readonly SriHashService $sriHashService,
        private readonly AssetCollector $assetCollector,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AssetCriticalityResolver $criticalityResolver,
        private readonly EarlyHintCandidateCollector $earlyHintCollector,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('identifier', 'string', 'Deduplication key', true);
        $this->registerArgument('src', 'string', 'EXT: path or absolute path to CSS/SCSS file', true);
        $this->registerArgument('priority', 'bool', 'Render in <head> before page CSS', false, false);
        $this->registerArgument('minify', 'bool', 'Override per-call minification', false, null);
        $this->registerArgument('critical', 'string', 'Criticality: auto|true|false', false, 'auto');
        $this->registerArgument('media', 'string', 'CSS media attribute', false, 'all');
        $this->registerArgument('nonce', 'string', 'CSP nonce for inline blocks', false, '');
        $this->registerArgument('integrity', 'string', 'SRI-Hash. Leer = automatisch. "none" = kein Hash, nötig für Look-Vorschau ohne CORS.', false, '');
        $this->registerArgument('crossorigin', 'string', 'CORS attribute', false, '');
    }

    public function render(): string
    {
        $src = (string) $this->arguments['src'];
        $identifier = (string) $this->arguments['identifier'];
        $critical = (string) $this->arguments['critical'];
        $priority = (bool) $this->arguments['priority'];
        $media = (string) $this->arguments['media'];
        $nonce = (string) $this->arguments['nonce'];
        $integrity = (string) $this->arguments['integrity'];
        $crossorigin = (string) $this->arguments['crossorigin'];
        $minify = $this->arguments['minify'] !== null
            ? (bool) $this->arguments['minify']
            : $this->extensionConfiguration->isEnableMinification();

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $routing = $request instanceof ServerRequestInterface ? $request->getAttribute('routing') : null;
        $pageUid = is_object($routing) && method_exists($routing, 'getPageId')
            ? (int) $routing->getPageId()
            : 0;

        $isCritical = match ($critical) {
            'true'  => true,
            'false' => false,
            default => $pageUid > 0 && $this->criticalityResolver->pageHasObserverData($pageUid),
        };

        if ($this->extensionConfiguration->isUseDevServer()) {
            return $this->renderFromDevServer($src, $identifier, $priority, $media, $nonce);
        }

        $resolvedPath = $this->requireFile($src);

        if ($isCritical) {
            // Compiled, minified and with absolute url() references (relative ones would
            // resolve against the page URL once inlined).
            $content = $this->compiledAssetPublisher->getInlineStylesheet($resolvedPath, $minify);

            // critical="auto" inlines small stylesheets only: a large bundle inlined into every
            // page bloats the HTML and can no longer be cached by the browser across pages.
            $limit = $this->extensionConfiguration->getCriticalCssMaxInlineBytes();
            if ($critical !== 'true' && $limit > 0 && strlen($content) > $limit) {
                $isCritical = false;
            }
        }

        if ($isCritical) {
            $event = new BeforeAssetInjectionEvent($content, 'css', $resolvedPath);
            $this->eventDispatcher->dispatch($event);
            $content = $event->getContent();

            // No preload hint: the stylesheet is inlined, a preload would download it for nothing.
            // Without an explicit nonce the CSP nonce of the request is used (TYPO3 replaces it
            // in cached pages), otherwise a strict style-src blocks the inline styles.
            $nonceAttr = $nonce !== ''
                ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"'
                : HtmlInjectionUtility::nonceAttribute($request instanceof ServerRequestInterface ? $request->getAttribute('nonce') : null);
            return '<style' . $nonceAttr . '>' . $content . '</style>';
        }

        $resolvedPath = $this->compiledAssetPublisher->publishStylesheet($resolvedPath, $minify);
        $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);

        // For TYPO3 14+, convert relative paths to absolute URLs to avoid SystemResourceFactory resolution issues
        $typo3Version = new Typo3Version();
        $normalizedParams = $request instanceof ServerRequestInterface ? $request->getAttribute('normalizedParams') : null;
        if ($typo3Version->getMajorVersion() >= 14 && !str_starts_with($publicPath, 'http') && is_object($normalizedParams)) {
            $siteUrl = (string) $normalizedParams->getSiteUrl();
            $publicPath = rtrim($siteUrl, '/') . '/' . ltrim($publicPath, '/');
        }

        if ($integrity === 'none') {
            $integrity = '';
        } elseif ($integrity === '') {
            try {
                $integrity = $this->sriHashService->computeForFile($resolvedPath);
            } catch (\Exception) {
                $integrity = '';
            }
        }

        $tagAttributes = ['media' => $media];
        $tagAttributes = AttributeUtility::normalizeCssAttributes($tagAttributes);
        if ($integrity !== '') {
            $tagAttributes['integrity'] = $integrity;
        }
        if ($crossorigin !== '') {
            $tagAttributes['crossorigin'] = $crossorigin;
        }

        $options = ['priority' => $priority];

        $typo3Version = new Typo3Version();
        if ($typo3Version->getMajorVersion() >= 13) {
            $options['external'] = true;
        }

        $this->assetCollector->addStyleSheet($identifier, $publicPath, $tagAttributes, $options);

        $this->earlyHintCollector->add(new EarlyHintCandidate(
            href: $publicPath,
            rel: 'preload',
            as: 'style',
        ));

        return '';
    }

    private function renderFromDevServer(string $src, string $identifier, bool $priority, string $media, string $nonce): string
    {
        $devServerUri = $this->extensionConfiguration->getDevServerUri();
        $publicPath = rtrim((string) $devServerUri, '/') . '/' . ltrim($src, '/');

        $tagAttributes = ['media' => $media];
        $tagAttributes = AttributeUtility::normalizeCssAttributes($tagAttributes);
        if ($nonce !== '') {
            $tagAttributes['nonce'] = $nonce;
        }

        $options = ['priority' => $priority];

        $typo3Version = new Typo3Version();
        if ($typo3Version->getMajorVersion() >= 13) {
            $options['external'] = true;
        }

        $this->assetCollector->addStyleSheet($identifier, $publicPath, $tagAttributes, $options);

        return '';
    }
}
