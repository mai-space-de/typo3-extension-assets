<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Render an HTML5 `<video>` element with optional `<source>` tags.
 *
 * Accepts one or more video sources as a comma-separated list in the `src` argument,
 * or inline child `<source>` tags. MIME types are auto-detected from the file extension.
 * Sources given via `src` are resolved through `EXT:` notation and served from their
 * public URLs — no temp file is generated.
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Single source video with controls -->
 *   <mai:video src="EXT:theme/Resources/Public/Video/intro.mp4"
 *             width="1280" height="720" controls="true" />
 *
 *   <!-- Multiple sources (browser picks the first supported format) -->
 *   <mai:video src="EXT:theme/Resources/Public/Video/hero.webm,
 *                   EXT:theme/Resources/Public/Video/hero.mp4"
 *             width="1920" height="1080" autoplay="true" muted="true" loop="true"
 *             poster="EXT:theme/Resources/Public/Video/hero-poster.jpg" />
 *
 *   <!-- Background video (autoplay, muted, loop — required by browsers for autoplay) -->
 *   <mai:video src="EXT:theme/Resources/Public/Video/bg.mp4"
 *             autoplay="true" muted="true" loop="true" playsinline="true"
 *             class="hero-bg-video" width="1920" height="1080" />
 *
 *   <!-- Lazy-loaded video (browser does not preload until the video enters the viewport) -->
 *   <mai:video src="EXT:theme/Resources/Public/Video/demo.mp4"
 *             preload="none" width="800" height="450" controls="true" />
 *
 *   <!-- Video with a poster image and accessible fallback text -->
 *   <mai:video src="EXT:theme/Resources/Public/Video/product.mp4"
 *             poster="EXT:theme/Resources/Public/Video/product-poster.jpg"
 *             width="800" controls="true">
 *       Your browser does not support the video tag. Please download the video.
 *   </mai:video>
 *
 * Notes:
 *  - Autoplay in most browsers requires `muted="true"` — unmuted autoplay is blocked.
 *  - Use `playsinline="true"` on mobile to prevent full-screen takeover on iOS.
 *  - For background videos, combine `autoplay="true" muted="true" loop="true" playsinline="true"`.
 *  - Multiple `src` values are rendered as individual `<source>` tags in the order given;
 *    the browser plays the first format it supports.
 */
final class VideoViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    private const MIME_MAP = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogg'  => 'video/ogg',
        'ogv'  => 'video/ogg',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
    ];

    /** Disable output escaping — this ViewHelper returns raw HTML. */
    protected $escapeOutput = false;

    /** Allow child content (fallback text or inline <source> tags) to render unescaped. */
    protected $escapeChildren = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'src',
            'string',
            'Comma-separated list of video source paths. Each entry is resolved via EXT: notation, '
            . 'a public-relative path, or an external URL. MIME types are auto-detected from the '
            . 'file extension. When multiple sources are given, the browser plays the first '
            . 'format it supports.',
            false,
            null,
        );

        $this->registerArgument(
            'poster',
            'string',
            'Path to the poster image shown before the video plays. Accepts EXT: notation, '
            . 'a public-relative path, or an external URL.',
            false,
            null,
        );

        $this->registerArgument(
            'width',
            'string',
            'Width of the video element in pixels or any CSS unit, e.g. "1280" or "100%". '
            . 'Applied as the width attribute on the <video> element.',
            false,
            null,
        );

        $this->registerArgument(
            'height',
            'string',
            'Height of the video element. Applied as the height attribute on the <video> element.',
            false,
            null,
        );

        $this->registerArgument(
            'autoplay',
            'bool',
            'Start playback automatically. Browsers require muted="true" for autoplay to work.',
            false,
            false,
        );

        $this->registerArgument(
            'muted',
            'bool',
            'Mute the video. Required for autoplay in most browsers.',
            false,
            false,
        );

        $this->registerArgument(
            'loop',
            'bool',
            'Loop the video continuously.',
            false,
            false,
        );

        $this->registerArgument(
            'controls',
            'bool',
            'Show the browser\'s native video controls (play/pause/volume/fullscreen).',
            false,
            false,
        );

        $this->registerArgument(
            'playsinline',
            'bool',
            'Play the video inline on iOS instead of entering full-screen automatically. '
            . 'Recommended for background videos and small embedded clips.',
            false,
            false,
        );

        $this->registerArgument(
            'preload',
            'string',
            'Hint to the browser how much of the video to preload. '
            . 'Accepted values: "none" (no preloading, good for bandwidth saving), '
            . '"metadata" (preload duration and dimensions only), '
            . '"auto" (browser decides, may download the full file). '
            . 'Omit to use the browser default.',
            false,
            null,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the <video> element.',
            false,
            null,
        );

        $this->registerArgument(
            'id',
            'string',
            'id attribute for the <video> element.',
            false,
            null,
        );

        $this->registerArgument(
            'additionalAttributes',
            'array',
            'Additional HTML attributes merged onto the <video> element.',
            false,
            [],
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        $attrs = self::buildVideoAttributes($arguments);

        // Resolve poster image.
        $poster = self::resolvePublicUrl((string)($arguments['poster'] ?? ''));
        if ($poster !== '') {
            $attrs['poster'] = $poster;
        }

        // Build opening <video> tag.
        $attrString = '';
        foreach ($attrs as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            // Boolean attributes: autoplay, muted, loop, controls, playsinline.
            if ($value === true) {
                $attrString .= ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1);
            } else {
                $attrString .= ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1)
                    . '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1) . '"';
            }
        }

        // Render <source> tags for comma-separated src list.
        $sourcesHtml = '';
        $srcArg = trim((string)($arguments['src'] ?? ''));
        if ($srcArg !== '') {
            $sources = array_filter(array_map('trim', explode(',', $srcArg)));
            foreach ($sources as $source) {
                $url      = self::resolvePublicUrl($source);
                $mimeType = self::detectMimeType($source);
                if ($url === '') {
                    continue;
                }
                $sourceAttr = ' src="' . htmlspecialchars($url, ENT_QUOTES | ENT_XML1) . '"';
                if ($mimeType !== '') {
                    $sourceAttr .= ' type="' . htmlspecialchars($mimeType, ENT_QUOTES | ENT_XML1) . '"';
                }
                $sourcesHtml .= '<source' . $sourceAttr . '>';
            }
        }

        // Render child content (may contain inline <source> tags or fallback text).
        $children = (string)$renderChildrenClosure();

        return '<video' . $attrString . '>'
            . $sourcesHtml
            . $children
            . '</video>';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the attribute array for the <video> element.
     *
     * @return array<string, string|true>
     */
    private static function buildVideoAttributes(array $arguments): array
    {
        $attrs = [];

        if (!empty($arguments['id'])) {
            $attrs['id'] = (string)$arguments['id'];
        }

        if (!empty($arguments['class'])) {
            $attrs['class'] = (string)$arguments['class'];
        }

        if (!empty($arguments['width'])) {
            $attrs['width'] = (string)$arguments['width'];
        }

        if (!empty($arguments['height'])) {
            $attrs['height'] = (string)$arguments['height'];
        }

        if ((bool)($arguments['autoplay'] ?? false)) {
            $attrs['autoplay'] = true;
        }

        if ((bool)($arguments['muted'] ?? false)) {
            $attrs['muted'] = true;
        }

        if ((bool)($arguments['loop'] ?? false)) {
            $attrs['loop'] = true;
        }

        if ((bool)($arguments['controls'] ?? false)) {
            $attrs['controls'] = true;
        }

        if ((bool)($arguments['playsinline'] ?? false)) {
            $attrs['playsinline'] = true;
        }

        $preload = $arguments['preload'] ?? null;
        if (is_string($preload) && in_array($preload, ['none', 'metadata', 'auto'], true)) {
            $attrs['preload'] = $preload;
        }

        // Additional caller-supplied attributes.
        foreach ((array)($arguments['additionalAttributes'] ?? []) as $name => $value) {
            $attrs[(string)$name] = (string)$value;
        }

        return $attrs;
    }

    /**
     * Resolve a source path to a public URL.
     *
     * External URLs are returned unchanged.
     * EXT: paths and absolute paths are resolved to public-relative URLs.
     */
    private static function resolvePublicUrl(string $src): string
    {
        if ($src === '') {
            return '';
        }

        // External URLs pass through unchanged.
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//')) {
            return $src;
        }

        $absolute = GeneralUtility::getFileAbsFileName($src);
        if ($absolute === '' || !is_file($absolute)) {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(self::class)
                ->warning('maispace_assets: Video/poster file not found: ' . $src);
            return '';
        }

        return PathUtility::getAbsoluteWebPath($absolute);
    }

    /**
     * Detect the MIME type for a video source from its file extension.
     */
    private static function detectMimeType(string $src): string
    {
        $ext = strtolower(pathinfo(trim($src), PATHINFO_EXTENSION));
        return self::MIME_MAP[$ext] ?? '';
    }
}
