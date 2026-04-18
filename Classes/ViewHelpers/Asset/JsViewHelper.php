<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Maispace\MaiAssets\Processing\MinificationProcessor;
use Maispace\MaiAssets\Service\SriHashService;
use Maispace\MaiAssets\Traits\FileResolutionTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class JsViewHelper extends AbstractViewHelper
{
    use FileResolutionTrait;

    protected $escapeOutput = false;

    public function __construct(
        private readonly MinificationProcessor $minificationProcessor,
        private readonly SriHashService $sriHashService,
        private readonly AssetCollector $assetCollector,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('identifier', 'string', 'Deduplication key', true);
        $this->registerArgument('src', 'string', 'EXT: path or absolute path to JS file', true);
        $this->registerArgument('priority', 'bool', '<head> (true) vs. footer (false)', false, false);
        $this->registerArgument('minify', 'bool', 'Override per-call minification', false, null);
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

        $resolvedPath = $this->requireFile($src);

        if ($minify) {
            $content = (string)file_get_contents($resolvedPath);
            $content = $this->minificationProcessor->process($content, $resolvedPath);

            $event = new BeforeAssetInjectionEvent($content, 'js', $resolvedPath);
            $this->eventDispatcher->dispatch($event);
            $content = $event->getContent();

            $cacheDir = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName('typo3temp/assets/mai_assets/compiled/');
            \TYPO3\CMS\Core\Utility\GeneralUtility::mkdir_deep($cacheDir);
            $cacheFile = $cacheDir . md5($resolvedPath) . '.js';
            file_put_contents($cacheFile, $content);
            $resolvedPath = $cacheFile;
        }

        $publicPath = PathUtility::getAbsoluteWebPath($resolvedPath);

        if ($integrity === '') {
            try {
                $integrity = $this->sriHashService->computeForFile($resolvedPath);
            } catch (\Exception) {
                $integrity = '';
            }
        }

        $tagAttributes = [];
        // type="module" is always deferred by browser spec; only add defer for non-module scripts
        if ($type === 'module') {
            $tagAttributes['type'] = 'module';
        } else {
            if ($async) {
                $tagAttributes['async'] = 'async';
            } elseif ($defer) {
                $tagAttributes['defer'] = 'defer';
            }
            if ($type !== '') {
                $tagAttributes['type'] = $type;
            }
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

        $this->assetCollector->addJavaScript($identifier, $publicPath, $tagAttributes, $options);

        return '';
    }
}
