<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Processing;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use Maispace\MaiAssets\Event\AfterCssProcessedEvent;
use Maispace\MaiAssets\Event\AfterJsProcessedEvent;
use Maispace\MaiAssets\Event\BeforeAssetInjectionEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractAssetProcessor implements AssetProcessorInterface
{
    private const CACHE_DIR = 'typo3temp/assets/mai_assets/compiled/';

    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    final public function process(string $content, string $sourcePath): string
    {
        $cacheKey = md5(hash_file('sha256', $sourcePath) . serialize($this->getSettingsHash()));
        $cacheFile = $this->getCacheDir() . $cacheKey . '.' . $this->getCacheExtension();

        if (file_exists($cacheFile)) {
            $processed = (string)file_get_contents($cacheFile);
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

        $event = new BeforeAssetInjectionEvent($processed, $type, $sourcePath);
        $this->eventDispatcher->dispatch($event);

        return $event->getContent();
    }

    abstract protected function doProcess(string $content, string $sourcePath): string;

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
