<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Exception\AssetFileNotFoundException;

final class SriHashService
{
    /**
     * Compute a sha384 SRI hash for a local file.
     * Returns a string ready for use as the `integrity` attribute value, e.g. "sha384-{base64}".
     *
     * @throws AssetFileNotFoundException if the file does not exist
     */
    public function computeForFile(string $absolutePath): string
    {
        if (!file_exists($absolutePath)) {
            throw new AssetFileNotFoundException(
                sprintf('Cannot compute SRI hash: file not found at "%s"', $absolutePath),
                1700000010
            );
        }

        $content = (string)file_get_contents($absolutePath);
        return $this->computeForContent($content);
    }

    /**
     * Compute a sha384 SRI hash for an arbitrary string of content.
     * Returns "sha384-{base64}".
     */
    public function computeForContent(string $content): string
    {
        $hash = hash('sha384', $content, true);
        return 'sha384-' . base64_encode($hash);
    }
}
