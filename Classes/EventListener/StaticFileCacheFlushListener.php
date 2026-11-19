<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EventListener;

use Maispace\MaiAssets\StaticFileCache\StaticFileRemovalService;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;

/**
 * "typo3 cache:flush" (all or the pages group) also removes the static HTML files.
 */
#[AsEventListener(identifier: 'mai-assets/static-file-cache-flush')]
final readonly class StaticFileCacheFlushListener
{
    public function __construct(
        private StaticFileRemovalService $removalService,
    ) {}

    public function __invoke(CacheFlushEvent $event): void
    {
        if ($event->hasGroup('pages') || $event->hasGroup('all')) {
            try {
                $this->removalService->purgeAll();
            } catch (\Throwable $e) {
                $event->addError('mai_assets: static file cache could not be removed: ' . $e->getMessage());
            }
        }
    }
}
