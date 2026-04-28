<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Security;

final class AboveFoldTokenService
{
    public function generate(int $pageUid, int $resetTimestamp, ?int $windowTs = null): string
    {
        $secret  = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $window  = $this->currentWindow($windowTs);
        $message = 'pageUid=' . $pageUid . '&ts=' . $resetTimestamp . '&window=' . $window;
        return hash_hmac('sha256', $message, $secret);
    }

    public function verify(string $token, int $pageUid, int $resetTimestamp): bool
    {
        $currentWindow = $this->currentWindow();
        if (hash_equals($this->generate($pageUid, $resetTimestamp, $currentWindow), $token)) {
            return true;
        }
        return hash_equals($this->generate($pageUid, $resetTimestamp, $currentWindow - 300), $token);
    }

    private function currentWindow(?int $ts = null): int
    {
        return (int)(floor((($ts ?? time())) / 300) * 300);
    }
}
