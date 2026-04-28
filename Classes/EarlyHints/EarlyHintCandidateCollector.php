<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EarlyHints;

use TYPO3\CMS\Core\SingletonInterface;

final class EarlyHintCandidateCollector implements SingletonInterface
{
    /** @var array<string, EarlyHintCandidate> */
    private array $candidates = [];

    public function add(EarlyHintCandidate $candidate): void
    {
        $key = $candidate->key();
        if (!isset($this->candidates[$key])) {
            $this->candidates[$key] = $candidate;
        }
    }

    /**
     * @return array<string, EarlyHintCandidate>
     */
    public function getAll(): array
    {
        return $this->candidates;
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function reset(): void
    {
        $this->candidates = [];
    }
}
