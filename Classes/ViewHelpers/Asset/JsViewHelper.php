<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Maispace\MaiAssets\Utility\AttributeUtility;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class JsViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly MinificationProcessor $minificationProcessor,
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
        $this->registerArgument('src', 'string', 'EXT: path or absolute path to JS file', true);
        $this->registerArgument('priority', 'bool', '<head> (true) vs. footer (false)', false, false);
        $this->registerArgument('minify', 'bool', 'Override per-call minification', false, null);
        $this->registerArgument('critical', 'string', 'Criticality: auto|true|false', false, 'auto');
        $this->registerArgument('defer', 'bool', 'defer attribute', false, true);
        $this->registerArgument('async', 'bool', 'async attribute (mutually exclusive with defer)', false, false);
        $this->registerArgument('type', 'string', 'MIME type; set "module" for ES6 modules', false, '');
        $this->registerArgument('nomodule', 'bool', 'Fallback for non-module browsers', false, false);
        $this->registerArgument('nonce', 'string', 'CSP nonce', false, '');
        $this->registerArgument('integrity', 'string', 'SRI hash; auto-computed for local files', false, '');
        $this->registerArgument('crossorigin', 'string', 'CORS attribute', false, '');
    }

    public function render(): string
    {
        $src = (string)$this->arguments['src'];
        $identifier = (string)$this->arguments['identifier'];
        $critical = (string)$this->arguments['critical'];
        $priority = (bool)$this->arguments['priority'];
        $defer = (bool)$this->arguments['defer'];
        $async = (bool)$this->arguments['async'];
        $type = (string)$this->arguments['type'];
        $nomodule = (bool)$this->arguments['nomodule'];
        $nonce = (string)$this->arguments['nonce'];
        $integrity = (string)$this->arguments['integrity'];
        $crossorigin = (string)$this->arguments['crossorigin'];
        $minify = $this->arguments['minify'] !== null
            ? (bool)$this->arguments['minify']
            : $this->extensionConfiguration->isEnableMinification();

        $pageUid = (int)($GLOBALS['TYPO3_REQUEST']?->getAttribute('routing')?->getPageId() ?? 0);

        $isCritical = match ($critical) {
            'true'  => true,
            'false' => false,
            default => $pageUid > 0 && $this->criticalityResolver->pageHasObserverData($pageUid),
        };

        if ($this->extensionConfiguration->isUseDevServer()) {
            return $this->renderFromDevServer($src, $identifier, $priority, $defer, $async, $type, $nomodule, $nonce, $isCritical);
        }

        $resolvedPath = $this->requireFile($src);

        if ($minify) {
            $content = (string)file_get_contents($resolvedPath);
            $content = $this->minificationProcessor->process($content, $resolvedPath);

            $event = new BeforeAssetInjectionEvent($content, 'js', $resolvedPath);
            $this->eventDispatcher->dispatch($event);
            $content = $event->getContent();

            $cacheDir = GeneralUtility::getFileAbsFileName('typo3temp/assets/mai_assets/compiled/');
            GeneralUtility::mkdir_deep($cacheDir);
            $cacheFile = $cacheDir . md5($resolvedPath) . '.js';
            file_put_contents($cacheFile, $content);
            $resolvedPath = $cacheFile;
        }

        $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);
        
        // For TYPO3 14+, convert relative paths to absolute URLs to avoid SystemResourceFactory resolution issues
        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();
        if ($typo3Version->getMajorVersion() >= 14 && !str_starts_with($publicPath, 'http')) {
            $siteUrl = (string)$GLOBALS['TYPO3_REQUEST']->getAttribute('normalizedParams')->getSiteUrl();
            $publicPath = rtrim($siteUrl, '/') . '/' . ltrim($publicPath, '/');
        }

        if ($integrity === '') {
            try {
                $integrity = $this->sriHashService->computeForFile($resolvedPath);
            } catch (\Exception) {
                $integrity = '';
            }
        }

        $tagAttributes = [];
        $tagAttributes = AttributeUtility::normalizeScriptAttributes($tagAttributes);
        // type="module" is always deferred by browser spec; only add defer for non-module scripts
        if ($type === 'module') {
            $tagAttributes['type'] = 'module';
        } else {
            if ($async) {
                $tagAttributes['async'] = 'async';
            } elseif ($defer && !$isCritical) {
                $tagAttributes['defer'] = 'defer';
            }
            if ($type !== '') {
                $tagAttributes['type'] = $type;
            }
        }
        if ($isCritical) {
            $tagAttributes['fetchpriority'] = 'high';
        }
        if ($nomodule) {
            $tagAttributes['nomodule'] = 'nomodule';
        }
        if ($nonce !== '') {
            $tagAttributes['nonce'] = $nonce;
        }
        if ($integrity !== '') {
            $tagAttributes['integrity'] = $integrity;
        }
        if ($crossorigin !== '') {
            $tagAttributes['crossorigin'] = $crossorigin;
        }

        $options = ['priority' => $priority];

        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();
        if ($typo3Version->getMajorVersion() >= 13) {
            $options['external'] = true;
        }

        $this->assetCollector->addJavaScript($identifier, $publicPath, $tagAttributes, $options);

        if ($isCritical) {
            $rel = ($type === 'module') ? 'modulepreload' : 'preload';
            $as = ($type === 'module') ? '' : 'script';
            $this->earlyHintCollector->add(new EarlyHintCandidate(
                href: $publicPath,
                rel: $rel,
                as: $as,
            ));
        }

        return '';
    }

    private function renderFromDevServer(
        string $src,
        string $identifier,
        bool $priority,
        bool $defer,
        bool $async,
        string $type,
        bool $nomodule,
        string $nonce,
        bool $isCritical
    ): string {
        $devServerUri = $this->extensionConfiguration->getDevServerUri();
        $publicPath = rtrim((string)$devServerUri, '/') . '/' . ltrim($src, '/');

        $tagAttributes = [];
        $tagAttributes = AttributeUtility::normalizeScriptAttributes($tagAttributes);
        
        if ($type === 'module') {
            $tagAttributes['type'] = 'module';
        } else {
            if ($async) {
                $tagAttributes['async'] = 'async';
            } elseif ($defer && !$isCritical) {
                $tagAttributes['defer'] = 'defer';
            }
            if ($type !== '') {
                $tagAttributes['type'] = $type;
            }
        }
        if ($isCritical) {
            $tagAttributes['fetchpriority'] = 'high';
        }
        if ($nomodule) {
            $tagAttributes['nomodule'] = 'nomodule';
        }
        if ($nonce !== '') {
            $tagAttributes['nonce'] = $nonce;
        }

        $options = ['priority' => $priority];

        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();
        if ($typo3Version->getMajorVersion() >= 13) {
            $options['external'] = true;
        }

        $this->assetCollector->addJavaScript($identifier, $publicPath, $tagAttributes, $options);

        return '';
    }
}
