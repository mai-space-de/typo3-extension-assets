
# HMAC Token Security for Above-Fold Report API

## Overview

The `/api/mai-assets/above-fold-report` endpoint is currently open to abuse. Anyone can POST arbitrary `pageUid` and `criticalUids` values — causing cache flushes and cache poisoning without ever having visited the page. This document specifies the HMAC token security layer that closes that gap.

---

## Threat Model

| Attack | Impact | Current protection |
|---|---|---|
| Cache-busting DoS — spam valid `pageUid`s | Repeated `flushCachesByTag` hammers the DB | None |
| Cache poisoning — push bogus `criticalUids` | Corrupts above-fold data, degrades render perf | None |
| Page enumeration — probe all UIDs | Discovers published page tree | None |

---

## Solution: Server-Signed HMAC Token

A short-lived, unforgeable token is generated at **render time** and verified at **POST time**. No session, no DB lookup, no round-trip.

### Token formula

```
message = "pageUid={$pageUid}&ts={$resetTimestamp}"
token   = HMAC-SHA256($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], $message)
```

The token binds to a **specific page** (via `pageUid`) and a **specific render cycle** (via `resetTimestamp`). It self-expires the moment the above-fold cache for that page is manually reset.

---

## Flow

```
TYPO3 renders page
  └─ AboveFoldObserverListener
       pageUid          = 42
       resetTimestamp   = 1714300000
       token            = HMAC(encryptionKey, "pageUid=42&ts=1714300000")
       ↓
     <script>
       var PAGE_UID               = 42;
       var SERVER_RESET_TIMESTAMP = 1714300000;
       var REPORT_TOKEN           = "a3f9c2e1...";
     </script>

Browser — IntersectionObserver fires
  └─ POST /api/mai-assets/above-fold-report
       { pageUid: 42, resetTimestamp: 1714300000,
         token: "a3f9c2e1...", bucket: "desktop",
         criticalUids: [5, 8, 12] }

AboveFoldReportMiddleware
  └─ tokenService->verify("a3f9c2e1...", 42, 1714300000)
       re-compute HMAC → hash_equals → true ✓
  └─ updateCriticalUids(42, "desktop", [5, 8, 12])
```

An attacker who cannot reproduce the HMAC secret receives `403 Forbidden`.

---

## Files Created / Modified

### New file: `Classes/Security/AboveFoldTokenService.php`

```php
<?php
declare(strict_types=1);

namespace Maispace\MaiAssets\Security;

final class AboveFoldTokenService
{
    public function generate(int $pageUid, int $resetTimestamp): string
    {
        $secret  = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        $message = 'pageUid=' . $pageUid . '&ts=' . $resetTimestamp;
        return hash_hmac('sha256', $message, $secret);
    }

    public function verify(string $token, int $pageUid, int $resetTimestamp): bool
    {
        return hash_equals($this->generate($pageUid, $resetTimestamp), $token);
    }
}
```

### Modified: `Classes/EventListener/AboveFoldObserverListener.php`

`AboveFoldTokenService` injected via constructor DI; token generated after `$resetTimestamp` is resolved; `###REPORT_TOKEN###` added to the `str_replace` call:

```php
public function __construct(
    private readonly AboveFoldCacheService $aboveFoldCacheService,
    private readonly AboveFoldTokenService $tokenService,
    private readonly EventDispatcherInterface $eventDispatcher,
) {}

// inside __invoke(), after $resetTimestamp is set:
$token = $this->tokenService->generate($pageUid, $resetTimestamp);

$script = str_replace(
    ['###PAGE_UID###', '###SERVER_RESET_TIMESTAMP###', '###REPORT_TOKEN###'],
    [(string)$pageUid, (string)$resetTimestamp, $token],
    $scriptTemplate
);
```

### Modified: `Resources/Public/JavaScript/AboveFoldObserver.js`

`REPORT_TOKEN` variable declared alongside the existing placeholders; `resetTimestamp` and `token` included in the POST payload:

```js
var PAGE_UID               = ###PAGE_UID###;
var SERVER_RESET_TIMESTAMP = ###SERVER_RESET_TIMESTAMP###;
var REPORT_TOKEN           = '###REPORT_TOKEN###';

// inside sendReport():
var payload = JSON.stringify({
    pageUid:        PAGE_UID,
    resetTimestamp: SERVER_RESET_TIMESTAMP,
    token:          REPORT_TOKEN,
    bucket:         bucket,
    criticalUids:   criticalUids,
    url:            window.location.href
});
```

### Modified: `Classes/Middleware/AboveFoldReportMiddleware.php`

`AboveFoldTokenService` injected via constructor DI; payload size checked before JSON decode; token verified before `validate()`; `criticalUids` count capped at 50:

```php
public function __construct(
    private readonly AboveFoldCacheService $aboveFoldCacheService,
    private readonly ExtensionConfiguration $extensionConfiguration,
    private readonly AboveFoldTokenService $tokenService,
) {}

// in process(), before json_decode:
if (strlen($body) > 4096) {
    return new JsonResponse(['status' => 'invalid', 'errors' => ['Payload too large']], 413);
}

// after json_decode, before validate():
$token   = (string)($data['token'] ?? '');
$ts      = (int)($data['resetTimestamp'] ?? 0);
$pageUid = (int)($data['pageUid'] ?? 0);

if (!$this->tokenService->verify($token, $pageUid, $ts)) {
    return new JsonResponse(['status' => 'forbidden'], 403);
}

// in validate():
} elseif (count($data['criticalUids']) > 50) {
    $errors[] = 'criticalUids must not contain more than 50 entries';
}
```

---

## Additional Hardening

These are independent of the HMAC token and are included in the implementation:

1. **Limit `criticalUids` count** — payloads with `count($criticalUids) > 50` are rejected with a 400 validation error.
2. **Limit payload size** — `strlen($body) > 4096` returns `413 Content Too Large` before JSON decoding.

---

## Why Not Other Approaches?

| Approach | Why it doesn't fit |
|---|---|
| Session / CSRF token | Anonymous visitors have no FE session |
| Origin / Referer header | Trivially spoofed by any `curl` command |
| IP allowlist | Real visitors are on public IPs |
| Rate limiting only | Doesn't prevent cache poisoning |
| JWT | Overhead with no benefit over plain HMAC |
