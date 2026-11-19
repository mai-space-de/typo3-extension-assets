<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

/**
 * Which headers of a rendered page are replayed with its static file.
 *
 * Kept: everything TYPO3 or extensions add for the document itself, e.g.
 * config.additionalHeaders (X-Frame-Options, Referrer-Policy, …) and Cache-Control.
 * Dropped: transport and per-request headers, headers the static delivery sets
 * itself (Content-Type, Link, Vary) and the CSP, which is regenerated with a fresh
 * nonce for every delivery.
 */
final class StaticResponseHeaders
{
    private const array DROPPED = [
        'set-cookie', 'content-length', 'content-encoding', 'transfer-encoding', 'connection',
        'keep-alive', 'date', 'age', 'etag', 'last-modified', 'expires', 'content-type', 'link',
        'vary', 'content-security-policy', 'content-security-policy-report-only', 'reporting-endpoints',
        'x-powered-by', 'x-mai-static-cache', 'x-mai-static-ready',
    ];

    /**
     * @param array<string, list<string>> $headers PSR-7 header map of the rendered response
     * @return array<string, list<string>>
     */
    public static function filterForStorage(array $headers): array
    {
        $kept = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name) || in_array(strtolower($name), self::DROPPED, true)) {
                continue;
            }
            $kept[$name] = array_values(array_map(strval(...), (array) $values));
        }

        return $kept;
    }

    /**
     * Headers to send with the static file. A max-age/s-maxage is capped to the
     * remaining lifetime of the file; replaying the original value would let shared
     * caches keep the page longer than TYPO3 does.
     *
     * @param array<string, list<string>> $stored
     * @return array<string, list<string>>
     */
    public static function forDelivery(array $stored, int $expires, int $now): array
    {
        $remaining = max(0, $expires - $now);
        $headers = self::filterForStorage($stored);

        foreach ($headers as $name => $values) {
            if (strtolower($name) !== 'cache-control') {
                continue;
            }
            $headers[$name] = array_map(
                static fn(string $value): string => (string) preg_replace_callback(
                    '/\b(max-age|s-maxage)\s*=\s*(\d+)/i',
                    static fn(array $m): string => $m[1] . '=' . min((int) $m[2], $remaining),
                    $value,
                ),
                $values,
            );
        }

        return $headers;
    }
}
