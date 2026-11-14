<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\Service;

use Maispace\MaiAssets\Configuration\ExtensionConfiguration;
use MatthiasMullie\Minify\JS;

/**
 * Builds the inline image measurement {@code <script>} tag from the public JS template.
 */
final readonly class ImageMeasureScriptBuilder
{
    public const string ENDPOINT = '/api/mai-assets/image-measure';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function build(string $scriptTemplate, int $pageUid): string
    {
        if ($this->extensionConfiguration->isEnableMinification()) {
            $minifier = new JS();
            $minifier->add($scriptTemplate);
            $scriptTemplate = str_replace(["\r\n", "\r", "\n"], '', $minifier->minify());
        }

        $script = str_replace(
            ['###PAGE_UID###', '###ENDPOINT###', '###SAMPLE_RATE###'],
            [
                (string) $pageUid,
                self::ENDPOINT,
                (string) $this->extensionConfiguration->getImageMeasureSampleRate(),
            ],
            $scriptTemplate,
        );

        return '<script>' . $script . '</script>';
    }
}
