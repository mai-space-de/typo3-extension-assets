<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Security;

/**
 * Signs image measurement reports.
 *
 * Every measured {@code <img>} carries a token bound to exactly one page and
 * one image slot. Only slots that the server really rendered can therefore
 * be reported, and nobody can create measurement rows for arbitrary keys.
 *
 * Like the above-fold token it does not expire: it lives in cached HTML. It is
 * not a secret towards visitors, it merely proves that the slot was issued by
 * this installation. Everything else is bounded by validation, the width
 * ladder, row caps, hit caps and rate limiting.
 */
final class ImageMeasureTokenService
{
    private const int TOKEN_LENGTH = 32;

    public function generate(int $pageUid, string $key): string
    {
        $secret = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($secret === '') {
            return '';
        }

        return substr(hash_hmac('sha256', 'mai-img|' . $pageUid . '|' . $key, $secret), 0, self::TOKEN_LENGTH);
    }

    public function verify(string $token, int $pageUid, string $key): bool
    {
        $expected = $this->generate($pageUid, $key);

        return $expected !== '' && hash_equals($expected, $token);
    }
}
