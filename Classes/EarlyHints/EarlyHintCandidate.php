<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EarlyHints;

/**
 * Immutable value object representing a single Early Hint link directive.
 *
 * Maps to one HTTP Link header line:
 *   Link: <$href>; rel=$rel[; as=$as][; type=$type][; crossorigin=$crossorigin]
 */
final class EarlyHintCandidate
{
    public function __construct(
        public readonly string $href,
        public readonly string $rel,
        public readonly string $as = '',
        public readonly string $type = '',
        public readonly string $crossorigin = '',
    ) {}

    public function toLinkHeaderValue(): string
    {
        $value = '<' . $this->href . '>; rel=' . $this->rel;

        if ($this->as !== '') {
            $value .= '; as=' . $this->as;
        }
        if ($this->type !== '') {
            $value .= '; type=' . $this->type;
        }
        if ($this->crossorigin !== '') {
            $value .= '; crossorigin=' . $this->crossorigin;
        }

        return $value;
    }

    public function key(): string
    {
        return md5($this->rel . '|' . $this->href . '|' . $this->as);
    }
}
