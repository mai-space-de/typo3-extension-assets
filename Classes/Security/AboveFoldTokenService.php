<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Security;

/**
 * Signs the above-fold report endpoint parameters.
 *
 * The token is embedded in HTML that lives in the TYPO3 page cache and in the
 * static file cache for an unbounded time. It therefore must not expire on its
 * own: a time window would turn every cached page into a source of 403
 * responses. Invalidation happens through the page's reset timestamp, which is
 * part of the signed message and is bumped whenever the observer data is reset.
 *
 * The token binds a report to exactly one page and one reset generation. It is
 * not a secret towards visitors; it only prevents reports for arbitrary pages.
 * The reported UIDs are additionally validated against the page's content.
 */
final class AboveFoldTokenService
{
    public function generate(int $pageUid, int $resetTimestamp): string
    {
        $secret = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        return hash_hmac('sha256', 'pageUid=' . $pageUid . '&ts=' . $resetTimestamp, $secret);
    }

    public function verify(string $token, int $pageUid, int $resetTimestamp): bool
    {
        return hash_equals($this->generate($pageUid, $resetTimestamp), $token);
    }
}
