<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use MatthiasMullie\Minify\JS;

/**
 * Builds the inline above-fold observer {@code <script>} tag from the public
 * JS template, applying placeholder substitution and optional minification.
 */
final readonly class AboveFoldObserverScriptBuilder
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @param string $scriptTemplate Raw JS template (may contain ###PLACEHOLDER### markers)
     */
    public function build(
        string $scriptTemplate,
        int $pageUid,
        int $resetTimestamp,
        string $token,
        string $validBucketsJson,
    ): string {
        if ($this->extensionConfiguration->isEnableMinification()) {
            $minifier = new JS();
            $minifier->add($scriptTemplate);
            $scriptTemplate = $minifier->minify();
            // MatthiasMullie may leave a few ASI newlines — strip them for inline delivery.
            $scriptTemplate = str_replace(["\r\n", "\r", "\n"], '', $scriptTemplate);
        }

        $script = str_replace(
            ['###PAGE_UID###', '###SERVER_RESET_TIMESTAMP###', '###REPORT_TOKEN###', '###VALID_BUCKETS###'],
            [(string)$pageUid, (string)$resetTimestamp, $token, $validBucketsJson],
            $scriptTemplate
        );

        return '<script>' . $script . '</script>';
    }
}
