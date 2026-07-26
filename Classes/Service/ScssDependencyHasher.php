<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

/**
 * Fingerprints an SCSS entry file together with its resolved @import/@use/@forward tree.
 *
 * CompiledAssetPublisher and ScssProcessor cache by content hash of the entry path
 * only; without walking imports, edits to partials keep serving stale CSS.
 */
final class ScssDependencyHasher
{
    /**
     * @return non-empty-string
     */
    public function hash(string $absoluteSourcePath): string
    {
        $hashes = [];
        $queue = [$absoluteSourcePath];
        $seen = [];

        while ($queue !== []) {
            $path = array_shift($queue);
            $real = realpath($path) ?: $path;
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;

            if (!is_file($real)) {
                continue;
            }

            $fileHash = hash_file('sha256', $real);
            if ($fileHash === false) {
                continue;
            }
            $hashes[] = $fileHash;

            if (!str_ends_with(strtolower($real), '.scss')) {
                continue;
            }

            foreach ($this->collectImports($real) as $importPath) {
                $queue[] = $importPath;
            }
        }

        sort($hashes);

        return hash('sha256', implode("\0", $hashes));
    }

    /**
     * @return list<string>
     */
    private function collectImports(string $absoluteFilePath): array
    {
        $content = (string)file_get_contents($absoluteFilePath);
        if ($content === '') {
            return [];
        }

        // Strip /* */ and // comments so disabled imports are ignored.
        $content = preg_replace('!/\*.*?\*/!s', '', $content) ?? $content;
        $content = preg_replace('!^\s*//.*$!m', '', $content) ?? $content;

        if (preg_match_all(
            '/@(?:import|use|forward)\s+(?:url\()?["\']([^"\']+)["\']\)?/i',
            $content,
            $matches,
        ) === false || $matches[1] === []) {
            return [];
        }

        $baseDir = dirname($absoluteFilePath);
        $resolved = [];

        foreach ($matches[1] as $specifier) {
            if ($this->isRemoteOrCssUrl($specifier)) {
                continue;
            }

            $candidate = $this->resolveImport($baseDir, $specifier);
            if ($candidate !== null) {
                $resolved[] = $candidate;
            }
        }

        return $resolved;
    }

    private function isRemoteOrCssUrl(string $specifier): bool
    {
        return str_starts_with($specifier, 'http://')
            || str_starts_with($specifier, 'https://')
            || str_starts_with($specifier, '//')
            || str_ends_with(strtolower($specifier), '.css');
    }

    private function resolveImport(string $baseDir, string $specifier): ?string
    {
        $specifier = str_replace('\\', '/', $specifier);
        $hasExtension = (bool)preg_match('/\.(scss|sass)$/i', $specifier);
        $dir = dirname($specifier);
        $basename = basename($specifier);
        $prefix = $dir === '.' ? '' : $dir . '/';

        $candidates = [];
        if ($hasExtension) {
            $candidates[] = $specifier;
            if (!str_starts_with($basename, '_')) {
                $candidates[] = $prefix . '_' . $basename;
            }
        } else {
            $candidates[] = $prefix . $basename . '.scss';
            $candidates[] = $prefix . '_' . $basename . '.scss';
            $candidates[] = $prefix . $basename . '.sass';
            $candidates[] = $prefix . '_' . $basename . '.sass';
            $candidates[] = $prefix . $basename . '/index.scss';
            $candidates[] = $prefix . $basename . '/_index.scss';
        }

        foreach ($candidates as $relative) {
            $absolute = $baseDir . '/' . $relative;
            $real = realpath($absolute);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }
}
