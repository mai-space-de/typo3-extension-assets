<?php

declare(strict_types = 1);

namespace Maispace\MaispaceAssets\ViewHelpers;

use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;

/**
 * Render a Lottie animation using the `<lottie-player>` web component.
 *
 * Lottie is a JSON-based animation format that renders vector animations
 * exported from Adobe After Effects. This ViewHelper outputs a
 * `<lottie-player>` custom element and optionally registers the player
 * JavaScript via TYPO3's AssetCollector.
 *
 * The player script (`@lottiefiles/lottie-player`) is loaded as a
 * type="module" script so it never blocks rendering. Configure the
 * default player URL via TypoScript:
 *
 *   plugin.tx_maispace_assets.lottie.playerSrc = https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js
 *
 * Global namespace: declared as "mai" in ext_localconf.php.
 *
 * Usage examples:
 *
 *   <!-- Basic looping animation from an EXT: path -->
 *   <mai:lottie src="EXT:theme/Resources/Public/Animations/hero.json"
 *              width="400px" height="400px" />
 *
 *   <!-- One-shot animation without controls, no loop -->
 *   <mai:lottie src="EXT:theme/Resources/Public/Animations/checkmark.json"
 *              loop="false" autoplay="true" width="80px" height="80px" />
 *
 *   <!-- Bouncing animation with visible player controls -->
 *   <mai:lottie src="/animations/wave.json"
 *              mode="bounce" controls="true" width="300px" />
 *
 *   <!-- Reverse playback direction -->
 *   <mai:lottie src="/animations/loading.json"
 *              direction="-1" loop="true" />
 *
 *   <!-- External Lottie JSON from a CDN -->
 *   <mai:lottie src="https://assets.example.com/animations/hero.json"
 *              width="100%" height="500px" />
 *
 *   <!-- Custom player script per animation (override global TypoScript) -->
 *   <mai:lottie src="/animations/splash.json"
 *              playerSrc="EXT:theme/Resources/Public/Vendor/lottie-player.js" />
 *
 *   <!-- User manages player script themselves — skip auto-registration -->
 *   <mai:lottie src="/animations/icon.json" playerSrc="" width="48px" />
 *
 * @see https://lottiefiles.github.io/lottie-player/
 */
final class LottieViewHelper extends AbstractViewHelper
{
    use CompileWithRenderStatic;

    /** Disable output escaping — this ViewHelper returns a raw custom element. */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'src',
            'string',
            'Path to the Lottie JSON animation file. Accepts EXT: notation, a public-relative path, or an external URL.',
            true,
        );

        $this->registerArgument(
            'autoplay',
            'bool',
            'Start the animation automatically when it enters the viewport. Default: true.',
            false,
            true,
        );

        $this->registerArgument(
            'loop',
            'bool',
            'Loop the animation continuously. Default: true.',
            false,
            true,
        );

        $this->registerArgument(
            'controls',
            'bool',
            'Show built-in player controls (play/pause/progress). Default: false.',
            false,
            false,
        );

        $this->registerArgument(
            'speed',
            'float',
            'Playback speed multiplier. 1.0 = normal speed, 0.5 = half speed, 2.0 = double speed. Default: 1.0.',
            false,
            1.0,
        );

        $this->registerArgument(
            'direction',
            'int',
            'Playback direction. 1 = forward (default), -1 = backward.',
            false,
            1,
        );

        $this->registerArgument(
            'mode',
            'string',
            'Playback mode. "normal" = play through once (or loop). "bounce" = play forward then reverse. Default: "normal".',
            false,
            'normal',
        );

        $this->registerArgument(
            'renderer',
            'string',
            'Rendering engine. "svg" (default, best quality/scaling), "canvas" (better performance), "html" (CSS-based). Default: "svg".',
            false,
            'svg',
        );

        $this->registerArgument(
            'background',
            'string',
            'Background colour of the animation container. Accepts any CSS colour value. Default: "transparent".',
            false,
            'transparent',
        );

        $this->registerArgument(
            'width',
            'string',
            'Width of the animation container, e.g. "400px" or "100%". Applied as the width attribute on the <lottie-player> element.',
            false,
            null,
        );

        $this->registerArgument(
            'height',
            'string',
            'Height of the animation container. Applied as the height attribute on the <lottie-player> element.',
            false,
            null,
        );

        $this->registerArgument(
            'class',
            'string',
            'CSS class(es) for the <lottie-player> element.',
            false,
            null,
        );

        $this->registerArgument(
            'playerSrc',
            'string',
            'URL or EXT: path to the lottie-player JavaScript library. '
            . 'When null (default), falls back to the TypoScript setting plugin.tx_maispace_assets.lottie.playerSrc. '
            . 'Pass an empty string "" to skip auto-registration entirely (useful when you include the player script via another mechanism).',
            false,
            null,
        );

        $this->registerArgument(
            'playerIdentifier',
            'string',
            'AssetCollector identifier for the player script. Defaults to "maispace-lottie-player". '
            . 'Override this when including multiple Lottie player versions on the same page.',
            false,
            'maispace-lottie-player',
        );

        $this->registerArgument(
            'additionalAttributes',
            'array',
            'Additional HTML attributes merged onto the <lottie-player> element.',
            false,
            [],
        );
    }

    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext,
    ): string {
        $rawSrc = (string)($arguments['src'] ?? '');
        if ($rawSrc === '') {
            return '';
        }

        // Resolve animation src to a public URL.
        $animationSrc = self::resolveAnimationSrc($rawSrc);
        if ($animationSrc === '') {
            return '';
        }

        // Optionally register the player script.
        self::maybeRegisterPlayerScript($arguments);

        // Build the <lottie-player> element.
        return self::buildTag($animationSrc, $arguments);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the animation JSON path to a public URL.
     *
     * External URLs are passed through unchanged.
     * EXT: paths and absolute paths are resolved to public-relative URLs.
     */
    private static function resolveAnimationSrc(string $src): string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://') || str_starts_with($src, '//')) {
            return $src;
        }

        $absolute = GeneralUtility::getFileAbsFileName($src);
        if ($absolute === '' || !is_file($absolute)) {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('maispace_assets: Lottie animation file not found: ' . $src);

            return '';
        }

        return PathUtility::getAbsoluteWebPath($absolute);
    }

    /**
     * Determine the player script URL (argument → TypoScript → skip) and
     * register it with AssetCollector when applicable.
     */
    private static function maybeRegisterPlayerScript(array $arguments): void
    {
        $playerSrcArg = $arguments['playerSrc'] ?? null;

        // Explicit empty string = user explicitly opted out.
        if ($playerSrcArg === '') {
            return;
        }

        // Resolve: explicit argument → TypoScript setting.
        $playerSrc = null;
        if (is_string($playerSrcArg) && $playerSrcArg !== '') {
            $playerSrc = $playerSrcArg;
        } else {
            $tsValue = self::getTypoScriptSetting('lottie.playerSrc', '');
            if (is_string($tsValue) && $tsValue !== '') {
                $playerSrc = $tsValue;
            }
        }

        if ($playerSrc === null) {
            return; // No player configured — user handles it.
        }

        // Resolve EXT: paths to public URLs; leave external URLs unchanged.
        if (!str_starts_with($playerSrc, 'http://') && !str_starts_with($playerSrc, 'https://') && !str_starts_with($playerSrc, '//')) {
            $absolute = GeneralUtility::getFileAbsFileName($playerSrc);
            if ($absolute !== '' && is_file($absolute)) {
                $playerSrc = PathUtility::getAbsoluteWebPath($absolute);
            }
        }

        $identifier = (string)($arguments['playerIdentifier'] ?? 'maispace-lottie-player');
        if ($identifier === '') {
            $identifier = 'maispace-lottie-player';
        }

        /** @var AssetCollector $collector */
        $collector = GeneralUtility::makeInstance(AssetCollector::class);
        // lottie-player v2+ is an ES module; type="module" defers and scopes it safely.
        $collector->addJavaScript(
            $identifier,
            $playerSrc,
            ['type'     => 'module'],
            ['priority' => false],
        );
    }

    /**
     * Build the `<lottie-player>` HTML element string.
     */
    private static function buildTag(string $animationSrc, array $arguments): string
    {
        $attrs = [];

        $attrs['src'] = $animationSrc;

        if ((bool)($arguments['autoplay'] ?? true)) {
            $attrs['autoplay'] = 'autoplay';
        }

        if ((bool)($arguments['loop'] ?? true)) {
            $attrs['loop'] = 'loop';
        }

        if ((bool)($arguments['controls'] ?? false)) {
            $attrs['controls'] = 'controls';
        }

        $speed = (float)($arguments['speed'] ?? 1.0);
        if ($speed !== 1.0) {
            $attrs['speed'] = (string)$speed;
        }

        $direction = (int)($arguments['direction'] ?? 1);
        if ($direction !== 1) {
            $attrs['direction'] = (string)$direction;
        }

        $mode = (string)($arguments['mode'] ?? 'normal');
        if ($mode !== '' && $mode !== 'normal') {
            $attrs['mode'] = $mode;
        }

        $renderer = (string)($arguments['renderer'] ?? 'svg');
        if ($renderer !== '' && $renderer !== 'svg') {
            $attrs['renderer'] = $renderer;
        }

        $background = (string)($arguments['background'] ?? 'transparent');
        if ($background !== '' && $background !== 'transparent') {
            $attrs['background'] = $background;
        }

        $width = $arguments['width'] ?? null;
        if (is_string($width) && $width !== '') {
            $attrs['style'] = 'width:' . $width . ';';
        }

        $height = $arguments['height'] ?? null;
        if (is_string($height) && $height !== '') {
            $existing = $attrs['style'] ?? '';
            $attrs['style'] = $existing . 'height:' . $height . ';';
        }

        $class = $arguments['class'] ?? null;
        if (is_string($class) && $class !== '') {
            $attrs['class'] = $class;
        }

        // Additional caller-supplied attributes.
        foreach ((array)($arguments['additionalAttributes'] ?? []) as $name => $value) {
            $attrs[(string)$name] = (string)$value;
        }

        $tag = '<lottie-player';
        foreach ($attrs as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            // Boolean attributes (autoplay, loop, controls) output as name="name".
            $tag .= ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1)
                . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1) . '"';
        }
        $tag .= '></lottie-player>';

        return $tag;
    }

    /**
     * Read a TypoScript setting from plugin.tx_maispace_assets.{dotPath}.
     */
    private static function getTypoScriptSetting(string $dotPath, mixed $default): mixed
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return $default;
        }

        $fts = $request->getAttribute('frontend.typoscript');
        if ($fts === null) {
            return $default;
        }

        $setup = $fts->getSetupArray();
        $root = $setup['plugin.']['tx_maispace_assets.'] ?? [];

        $parts = explode('.', $dotPath);
        $node = $root;
        foreach ($parts as $i => $part) {
            $isLast = ($i === count($parts) - 1);
            if ($isLast) {
                return $node[$part] ?? $default;
            }
            $node = $node[$part . '.'] ?? [];
            if (!is_array($node)) {
                return $default;
            }
        }

        return $default;
    }
}
