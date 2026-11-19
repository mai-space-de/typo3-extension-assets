<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Processing;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\AfterCssProcessedEvent;
use Maispace\MaiAssets\Event\AfterJsProcessedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractAssetProcessor implements AssetProcessorInterface
{
    private const string CACHE_DIR = 'typo3temp/assets/mai_assets/compiled/';

    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    final public function process(string $content, string $sourcePath): string
    {
        // The input content is part of the key: callers pass already transformed content
        // (e.g. compiled SCSS for minification), whose source file hash alone would not
        // change when only an imported partial changes.
        $cacheKey = md5(
            hash('sha256', $content)
            . $this->getSourceFingerprint($sourcePath)
            . serialize($this->getSettingsHash()),
        );
        $cacheFile = $this->getCacheDir() . $cacheKey . '.' . $this->getCacheExtension();

        if (file_exists($cacheFile)) {
            $processed = (string) file_get_contents($cacheFile);
        } else {
            $processed = $this->doProcess($content, $sourcePath);
            GeneralUtility::mkdir_deep(dirname($cacheFile));
            file_put_contents($cacheFile, $processed);
        }

        $type = $this->getContentType($sourcePath);

        // Fire type-specific post-processing events
        if ($type === 'js') {
            $jsEvent = new AfterJsProcessedEvent($processed, $sourcePath);
            $this->eventDispatcher->dispatch($jsEvent);
            $processed = $jsEvent->getProcessedJs();
        } else {
            $cssEvent = new AfterCssProcessedEvent($processed, $sourcePath);
            $this->eventDispatcher->dispatch($cssEvent);
            $processed = $cssEvent->getProcessedCss();
        }

        // BeforeAssetInjectionEvent is dispatched by the ViewHelper that injects the
        // content; dispatching it here as well applied listeners twice.
        return $processed;
    }

    abstract protected function doProcess(string $content, string $sourcePath): string;

    /**
     * Content fingerprint used for the processor cache key.
     * SCSS overrides this to include the resolved import tree.
     */
    protected function getSourceFingerprint(string $sourcePath): string
    {
        return (string) hash_file('sha256', $sourcePath);
    }

    protected function getSettingsHash(): array
    {
        return [];
    }

    protected function getCacheExtension(): string
    {
        return 'css';
    }

    protected function getContentType(string $sourcePath): string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'js' => 'js',
            default => 'css',
        };
    }

    private function getCacheDir(): string
    {
        return GeneralUtility::getFileAbsFileName(self::CACHE_DIR);
    }
}
