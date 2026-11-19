<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\StaticFileCache;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Remembers, within one request, which static file was written, so the response
 * headers of that request can be stored next to it
 * ({@see \Maispace\MaiAssets\Middleware\StaticFileHeaderRecorderMiddleware}).
 */
final class StaticFileWriteRegistry implements SingletonInterface
{
    /** @var array{page: int, language: int, variant: string, nonce: string, expires: int}|null */
    private ?array $pending = null;

    public function remember(int $pageUid, int $languageUid, string $variant, string $nonce, int $expires): void
    {
        $this->pending = [
            'page' => $pageUid,
            'language' => $languageUid,
            'variant' => $variant,
            'nonce' => $nonce,
            'expires' => $expires,
        ];
    }

    /**
     * @return array{page: int, language: int, variant: string, nonce: string, expires: int}|null
     */
    public function take(): ?array
    {
        $pending = $this->pending;
        $this->pending = null;

        return $pending;
    }
}
