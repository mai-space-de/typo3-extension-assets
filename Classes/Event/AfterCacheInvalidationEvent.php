<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

/**
 * Dispatched AFTER cache invalidation has been executed.
 *
 * Immutable event: listeners receive a read-only report of what was invalidated.
 */
final readonly class AfterCacheInvalidationEvent
{
    /**
     * @param string $trigger One of BeforeCacheInvalidationEvent::TRIGGER_* constants.
     * @param int $pageUid The affected page UID.
     * @param array<string> $invalidatedTargets Targets that were actually invalidated.
     */
    public function __construct(
        private string $trigger,
        private int $pageUid,
        private array $invalidatedTargets,
    ) {}

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    /**
     * @return array<string>
     */
    public function getInvalidatedTargets(): array
    {
        return $this->invalidatedTargets;
    }
}
