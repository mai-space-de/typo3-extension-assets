<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Event;

/**
 * Dispatched BEFORE cache invalidation occurs.
 *
 * Mutable event: listeners may add or remove invalidation targets, or add
 * explanations for logging/debugging. This follows the same architectural
 * pattern as staticfilecache's {@link \SFC\Staticfilecache\Event\CacheRuleEvent}.
 */
final class BeforeCacheInvalidationEvent
{
    public const TRIGGER_CONTENT_SAVE = 'content_save';
    public const TRIGGER_BUCKET_UPDATE = 'bucket_update';

    /** @var array<string, string> Human-readable reason codes keyed by target */
    private array $explanations = [];

    /**
     * @param string $trigger One of the TRIGGER_* constants.
     * @param int $pageUid The affected page UID.
     * @param array<string> $targets Invalidation targets to apply (mutable).
     * @param array<string, mixed> $context Additional context (changed fields, bucket name, etc.).
     */
    public function __construct(
        private readonly string $trigger,
        private readonly int $pageUid,
        private array $targets,
        private readonly array $context = [],
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
     * @return array<string> Current invalidation targets.
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * Replace the entire list of invalidation targets.
     *
     * @param array<string> $targets
     */
    public function setTargets(array $targets): void
    {
        $this->targets = array_values($targets);
    }

    /**
     * Append a target if it is not already present.
     */
    public function addTarget(string $target): void
    {
        if (!in_array($target, $this->targets, true)) {
            $this->targets[] = $target;
        }
    }

    /**
     * Remove a specific target from the list.
     */
    public function removeTarget(string $target): void
    {
        $this->targets = array_values(
            array_filter($this->targets, static fn(string $t): bool => $t !== $target)
        );
    }

    /**
     * Append an explanation string for a given invalidation target.
     */
    public function addExplanation(string $key, string $message): void
    {
        $this->explanations[$key] = $message;
    }

    /**
     * @return array<string, string>
     */
    public function getExplanations(): array
    {
        return $this->explanations;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
