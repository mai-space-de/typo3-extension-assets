<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\EarlyHints;

/**
 * Immutable value object representing a single Early Hint link directive.
 *
 * Maps to one HTTP Link header line:
 *   Link: <$href>; rel=$rel[; as=$as][; type=$type][; crossorigin=$crossorigin]
 *         [; imagesrcset="…"][; imagesizes="…"][; media="…"]
 *
 * Responsive images must be preloaded with imagesrcset/imagesizes (and media for
 * art-directed sources). A preload of one fixed variant makes the browser
 * download that variant in addition to the one it picks from the srcset.
 */
final readonly class EarlyHintCandidate
{
    public function __construct(
        public string $href,
        public string $rel,
        public string $as = '',
        public string $type = '',
        public string $crossorigin = '',
        public string $imageSrcset = '',
        public string $imageSizes = '',
        public string $media = '',
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
        if ($this->imageSrcset !== '') {
            $value .= '; imagesrcset=' . self::quote($this->imageSrcset);
        }
        if ($this->imageSizes !== '') {
            $value .= '; imagesizes=' . self::quote($this->imageSizes);
        }
        if ($this->media !== '') {
            $value .= '; media=' . self::quote($this->media);
        }

        return $value;
    }

    public function key(): string
    {
        return md5($this->rel . '|' . $this->href . '|' . $this->as . '|' . $this->media . '|' . $this->imageSrcset);
    }

    /**
     * RFC 8288 quoted-string; also keeps the commas of a srcset from splitting the header.
     */
    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value) . '"';
    }
}
