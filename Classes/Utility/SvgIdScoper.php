<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

/**
 * Makes the internal ids of an SVG fragment unique (gradients, clip paths, masks,
 * filters, <title> ids). Several icons in one sprite, or the same inline SVG twice
 * on a page, otherwise share ids like "a" and reference each other's definitions.
 */
final class SvgIdScoper
{
    public static function scope(string $markup, string $prefix): string
    {
        if (preg_match_all('/(?<![\w:-])id\s*=\s*(["\'])([^"\']+)\1/', $markup, $matches) < 1) {
            return $markup;
        }

        $prefix = (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $prefix);
        foreach (array_unique($matches[2]) as $id) {
            $scoped = $prefix . '-' . $id;
            $quoted = preg_quote($id, '/');
            $markup = (string) preg_replace('/((?<![\w:-])id\s*=\s*["\'])' . $quoted . '(["\'])/', '${1}' . $scoped . '${2}', $markup);
            $markup = (string) preg_replace('/(url\(\s*["\']?#)' . $quoted . '(["\']?\s*\))/', '${1}' . $scoped . '${2}', $markup);
            $markup = (string) preg_replace('/((?:xlink:)?href\s*=\s*["\']#)' . $quoted . '(["\'])/', '${1}' . $scoped . '${2}', $markup);
            $markup = (string) preg_replace_callback(
                '/\b(aria-labelledby|aria-describedby)\s*=\s*(["\'])([^"\']*)\2/',
                static fn(array $m): string => $m[1] . '=' . $m[2]
                    . implode(' ', array_map(static fn(string $token): string => $token === $id ? $scoped : $token, preg_split('/\s+/', trim($m[3])) ?: []))
                    . $m[2],
                $markup,
            );
        }

        return $markup;
    }
}
