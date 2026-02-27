<?php

declare(strict_types=1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use Closure;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Emit a resource hint `<link>` tag into `<head>`.
 *
 * Supports preconnect, dns-prefetch, modulepreload, prefetch, and preload.
 * All hints are injected via PageRenderer::addHeaderData() and always land
 * in `<head>` — no footer placement is possible (resource hints only work there).
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Warm up a TCP+TLS connection to a CDN origin -->
 *   <mai:hint rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />
 *
 *   <!-- Cheap DNS-only hint (no TLS handshake) -->
 *   <mai:hint rel="dns-prefetch" href="https://cdn.example.com" />
 *
 *   <!-- Preload an ES module and its dependencies in parallel -->
 *   <mai:hint rel="modulepreload" href="/assets/app.js" />
 *
 *   <!-- Preload a web font (crossorigin is required for fonts) -->
 *   <mai:hint rel="preload" href="/fonts/Inter.woff2"
 *            as="font" type="font/woff2" crossorigin="anonymous" />
 *
 *   <!-- Conditional preload scoped to a viewport size -->
 *   <mai:hint rel="preload" href="/images/hero-mobile.webp"
 *            as="image" media="(max-width: 767px)" />
 *
 *   <!-- Prefetch a resource likely needed on the next navigation -->
 *   <mai:hint rel="prefetch" href="/next-page.html" />
 *
 * Notes:
 *  - Resource hints do not require a CSP nonce — they are `<link>` tags, not scripts.
 *  - For SRI on preload hints, TYPO3's PageRenderer does not support the integrity
 *    attribute on addHeaderData() strings; use `additionalAttributes` if needed.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Attributes/rel/preconnect
 */
final class HintViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** This ViewHelper produces no output of its own — it only injects into <head>. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'href',
            'string',
            'The URL of the resource to hint. For preconnect/dns-prefetch this is the origin (e.g. "https://cdn.example.com"). For preload/modulepreload it is the full asset URL.',
            true,
        );

        $this->registerArgument(
            'rel',
            'string',
            'The link relationship type. Accepted values: "preconnect", "dns-prefetch", "modulepreload", "prefetch", "preload".',
            true,
        );

        $this->registerArgument(
            'as',
            'string',
            'Destination for preload/modulepreload hints. Accepted values: "script", "style", "font", "image", "document", "fetch". Required for rel="preload"; optional for "modulepreload" (defaults to "script").',
            false,
            null,
        );

        $this->registerArgument(
            'type',
            'string',
            'MIME type of the resource, e.g. "font/woff2" or "image/webp". Helps the browser decide whether to honour the preload hint based on format support.',
            false,
            null,
        );

        $this->registerArgument(
            'crossorigin',
            'string',
            'Add the crossorigin attribute. Required for font preloads ("anonymous"). '
            . 'Also needed on preconnect when the connection will be used for CORS requests. '
            . 'Accepted values: "anonymous", "use-credentials".',
            false,
            null,
        );

        $this->registerArgument(
            'media',
            'string',
            'Media query scoping the hint, e.g. "(max-width: 767px)". The browser only acts on the hint when the media query matches.',
            false,
            null,
        );

        $this->registerArgument(
            'additionalAttributes',
            'array',
            'Additional HTML attributes merged onto the <link> tag.',
            false,
            [],
        );
    }

    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        $href = trim((string)($arguments['href'] ?? ''));
        $rel  = trim((string)($arguments['rel'] ?? ''));

        if ($href === '' || $rel === '') {
            return '';
        }

        $attrs = [];
        $attrs['rel']  = $rel;
        $attrs['href'] = $href;

        $as = $arguments['as'] ?? null;
        if (is_string($as) && $as !== '') {
            $attrs['as'] = $as;
        } elseif ($rel === 'modulepreload') {
            // modulepreload defaults to as="script" per spec.
            $attrs['as'] = 'script';
        }

        $type = $arguments['type'] ?? null;
        if (is_string($type) && $type !== '') {
            $attrs['type'] = $type;
        }

        $crossorigin = $arguments['crossorigin'] ?? null;
        if (is_string($crossorigin) && $crossorigin !== '') {
            $attrs['crossorigin'] = $crossorigin;
        }

        $media = $arguments['media'] ?? null;
        if (is_string($media) && $media !== '') {
            $attrs['media'] = $media;
        }

        // Merge any caller-supplied extra attributes.
        foreach ((array)($arguments['additionalAttributes'] ?? []) as $name => $value) {
            $attrs[(string)$name] = (string)$value;
        }

        // Build the <link> tag.
        $tag = '<link';
        foreach ($attrs as $name => $value) {
            $tag .= ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1)
                . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . '"';
        }
        $tag .= '>';

        GeneralUtility::makeInstance(PageRenderer::class)->addHeaderData($tag);

        return '';
    }
}
