<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

/**
 * Pure HTML minification service — no DI, no side effects.
 * Order: protect blocks → strip comments → collapse whitespace → restore blocks.
 */
final class HtmlMinificationService
{
    /**
     * TYPO3-internal comment markers that must never be stripped.
     */
    private const PRESERVED_COMMENT_PATTERNS = [
        'INT_SCRIPT',
        'HD_',
        'TDS_',
        'FD_',
        'CSS_INCLUDE_',
        'CSS_INLINE_',
        'JS_LIBS',
        'JS_INCLUDE',
        'JS_INLINE',
        'JS_LIBS_FOOTER',
        'JS_INCLUDE_FOOTER',
        'JS_INLINE_FOOTER',
        'HEADERDATA',
        'FOOTERDATA',
        'TYPO3SEARCH_begin',
        'TYPO3SEARCH_end',
        'PROTECTED_',
    ];

    /**
     * Tags that are always protected regardless of configuration.
     */
    private const ALWAYS_PROTECTED_TAGS = ['script', 'style', 'textarea'];

    public function minify(string $html, array $config): string
    {
        if ($html === '') {
            return $html;
        }

        $stripComments = (bool)($config['stripComments'] ?? true);
        $preserveTagsRaw = (string)($config['preserveTags'] ?? 'pre,code,textarea');
        $preserveTags = array_filter(array_map('trim', explode(',', $preserveTagsRaw)));

        // Merge always-protected tags with configurable ones (deduplicated)
        $allProtectedTags = array_unique(array_merge(self::ALWAYS_PROTECTED_TAGS, $preserveTags));

        [$protected, $map] = $this->protectBlocks($html, $allProtectedTags);

        if ($stripComments) {
            $protected = $this->stripComments($protected);
        }

        $protected = $this->collapseWhitespace($protected);

        return $this->restoreBlocks($protected, $map);
    }

    /**
     * Replace protected tag content with placeholder tokens.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function protectBlocks(string $html, array $tags): array
    {
        $map = [];
        $counter = 0;

        foreach ($tags as $tag) {
            $tag = preg_quote($tag, '/');
            $pattern = '/(<' . $tag . '[\s>].*?<\/' . $tag . '>)/is';
            $html = preg_replace_callback($pattern, static function (array $matches) use (&$map, &$counter): string {
                $token = '<!--PROTECTED_' . $counter . '-->';
                $map[$token] = $matches[1];
                $counter++;
                return $token;
            }, $html) ?? $html;
        }

        return [$html, $map];
    }

    /**
     * Restore placeholder tokens back to original content.
     *
     * @param array<string, string> $map
     */
    private function restoreBlocks(string $html, array $map): string
    {
        return strtr($html, $map);
    }

    /**
     * Strip HTML comments, preserving TYPO3-internal markers and section markers.
     */
    private function stripComments(string $html): string
    {
        // Build negative lookahead for preserved patterns
        $lookaheads = array_map(
            static fn(string $p): string => preg_quote($p, '/'),
            self::PRESERVED_COMMENT_PATTERNS
        );
        // Also preserve <!-- ###...  section markers
        $lookaheads[] = ' ###';

        $negLookahead = '(?!' . implode('|', $lookaheads) . ')';

        return preg_replace('/<!--' . $negLookahead . '.*?-->/s', '', $html) ?? $html;
    }

    /**
     * Collapse inter-element whitespace.
     */
    private function collapseWhitespace(string $html): string
    {
        // Collapse whitespace runs between > and <
        $html = preg_replace('/>\s+</u', '> <', $html) ?? $html;

        // Process line by line: trim and discard empty lines
        $lines = explode("\n", $html);
        $result = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return implode("\n", $result);
    }
}
