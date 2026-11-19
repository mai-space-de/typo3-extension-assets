<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Utility;

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;

/**
 * Inserts markup into a rendered HTML document at exactly one place.
 *
 * str_ireplace('</body>', …) replaced every occurrence, including ones inside
 * inline scripts, JSON or srcdoc attributes; preg_replace() interpreted "$1" or
 * "\1" in the inserted markup as back references.
 */
final class HtmlInjectionUtility
{
    /**
     * Before the last closing body tag (the document's own one); unchanged when there is none.
     */
    public static function beforeBodyEnd(string $html, string $insert): string
    {
        $position = strripos($html, '</body>');

        return $position === false ? $html : substr_replace($html, $insert, $position, 0);
    }

    /**
     * Before the first closing head tag; unchanged when there is none.
     */
    public static function beforeHeadEnd(string $html, string $insert): string
    {
        $position = stripos($html, '</head>');

        return $position === false ? $html : substr_replace($html, $insert, $position, 0);
    }

    /**
     * Right after the opening body tag; unchanged when there is none.
     */
    public static function afterBodyStart(string $html, string $insert): string
    {
        if (preg_match('/<body\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }

        return substr_replace($html, $insert, $matches[0][1] + strlen($matches[0][0]), 0);
    }

    /**
     * ' nonce="…"' for inline scripts/styles when the request carries a CSP nonce.
     * TYPO3 substitutes the value in cached pages (NonceValueSubstitution).
     */
    public static function nonceAttribute(mixed $nonce): string
    {
        if (!$nonce instanceof ConsumableNonce) {
            return '';
        }

        return ' nonce="' . htmlspecialchars($nonce->consume(), ENT_QUOTES) . '"';
    }
}
