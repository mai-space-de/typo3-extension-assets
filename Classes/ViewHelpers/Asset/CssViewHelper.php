<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidate;
use Maispace\MaiAssets\EarlyHints\EarlyHintCandidateCollector;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Processing\ScssProcessor;
use Maispace\MaiAssets\Service\AssetCriticalityResolver;
use Maispace\MaiAssets\Service\CompiledAssetPublisher;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Maispace\MaiAssets\Utility\AttributeUtility;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class CssViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly ScssProcessor $scssProcessor,
        private readonly MinificationProcessor $minificationProcessor,
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
        $this->registerArgument('integrity', 'string', 'Explicit SRI hash; auto-computed if empty and file is local', false, '');
        $this->registerArgument('crossorigin', 'string', 'CORS attribute', false, '');
    }

    public function render(): string
    {
        $src = (string)$this->arguments['src'];
        $identifier = (string)$this->arguments['identifier'];
        $critical = (string)$this->arguments['critical'];
        $priority = (bool)$this->arguments['priority'];
        $media = (string)$this->arguments['media'];
        $nonce = (string)$this->arguments['nonce'];
        $integrity = (string)$this->arguments['integrity'];
        $crossorigin = (string)$this->arguments['crossorigin'];
        $minify = $this->arguments['minify'] !== null
            ? (bool)$this->arguments['minify']
            : $this->extensionConfiguration->isEnableMinification();

        $pageUid = (int)($GLOBALS['TYPO3_REQUEST']->getAttribute('routing')?->getPageId() ?? 0);

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
            // Compile + minify via publisher (cached), then read content and fire event
            $compiledPath = $this->compiledAssetPublisher->publishStylesheet($resolvedPath, $minify);
            $content = (string)file_get_contents($compiledPath);

            $event = new BeforeAssetInjectionEvent($content, 'css', $resolvedPath);
            $this->eventDispatcher->dispatch($event);
            $content = $event->getContent();

            // Register the compiled external file as an early hint for uncached follow-up requests
            $publicPath = PathUtility::getAbsoluteWebPath($compiledPath);
            $this->earlyHintCollector->add(new EarlyHintCandidate(
                href: $publicPath,
                rel: 'preload',
                as: 'style',
            ));

            $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';
            return '<style' . $nonceAttr . '>' . $content . '</style>';
        }

        $resolvedPath = $this->compiledAssetPublisher->publishStylesheet($resolvedPath, $minify);
        $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);

        if ($integrity === '') {
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

        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();
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
        $publicPath = rtrim((string)$devServerUri, '/') . '/' . ltrim($src, '/');

        $tagAttributes = ['media' => $media];
        $tagAttributes = AttributeUtility::normalizeCssAttributes($tagAttributes);
        if ($nonce !== '') {
            $tagAttributes['nonce'] = $nonce;
        }

        $options = ['priority' => $priority];

        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();
        if ($typo3Version->getMajorVersion() >= 13) {
            $options['external'] = true;
        }

        $this->assetCollector->addStyleSheet($identifier, $publicPath, $tagAttributes, $options);

        return '';
    }
}
