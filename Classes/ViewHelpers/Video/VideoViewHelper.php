<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Video;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class VideoViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('file', 'object', 'FAL file reference for self-hosted video', false, null);
        $this->registerArgument('youtubeId', 'string', 'YouTube video ID', false, '');
        $this->registerArgument('vimeoId', 'string', 'Vimeo video ID', false, '');
        $this->registerArgument('poster', 'object', 'Poster FAL file reference', false, null);
        $this->registerArgument('isCritical', 'bool', 'Whether the video is above-fold critical', false, false);
        $this->registerArgument('type', 'string', 'Display type: "background" or "content"', false, 'content');
        $this->registerArgument('title', 'string', 'Video title for accessibility', false, '');
        $this->registerArgument('class', 'string', 'CSS class', false, '');
    }

    public function render(): string
    {
        $file = $this->arguments['file'];
        $youtubeId = (string)$this->arguments['youtubeId'];
        $vimeoId = (string)$this->arguments['vimeoId'];
        $poster = $this->arguments['poster'];
        $isCritical = (bool)$this->arguments['isCritical'];
        $type = (string)$this->arguments['type'];
        $title = (string)$this->arguments['title'];
        $class = (string)$this->arguments['class'];

        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';

        if ($type === 'background') {
            return $this->renderBackground($file, $isCritical, $classAttr);
        }

        // Content type
        if ($youtubeId !== '') {
            return $this->renderYoutubeFacade($youtubeId, $poster, $title, $classAttr);
        }

        if ($vimeoId !== '') {
            return $this->renderVimeoFacade($vimeoId, $poster, $title, $classAttr);
        }

        if ($file !== null) {
            return $this->renderSelfHosted($file, $poster, $classAttr);
        }

        return '';
    }

    private function renderBackground(?object $file, bool $isCritical, string $classAttr): string
    {
        if ($file === null) {
            return '';
        }

        $url = $this->getFileUrl($file);
        if ($url === '') {
            return '';
        }

        if ($isCritical) {
            // Autoplay background, eager load
            return '<video'
                . $classAttr
                . ' preload="metadata"'
                . ' autoplay muted loop playsinline'
                . ' src="' . htmlspecialchars($url, ENT_QUOTES) . '"'
                . '></video>';
        }

        // Lazy background
        return '<video'
            . $classAttr
            . ' preload="none"'
            . ' data-lazy'
            . ' data-src="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ' muted loop playsinline'
            . '></video>';
    }

    private function renderYoutubeFacade(string $youtubeId, ?object $poster, string $title, string $classAttr): string
    {
        $posterUrl = $poster !== null ? $this->getFileUrl($poster) : '';
        if ($posterUrl === '') {
            $posterUrl = 'https://img.youtube.com/vi/' . htmlspecialchars($youtubeId, ENT_QUOTES) . '/maxresdefault.jpg';
        }

        $titleAttr = $title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '';
        $iframeUrl = 'https://www.youtube-nocookie.com/embed/' . htmlspecialchars($youtubeId, ENT_QUOTES) . '?autoplay=1';

        return '<div class="mai-video-facade mai-video-youtube"' . $classAttr . '>'
            . '<img src="' . htmlspecialchars($posterUrl, ENT_QUOTES) . '"'
            . ' alt="' . htmlspecialchars($title, ENT_QUOTES) . '"'
            . ' loading="lazy" class="mai-video-poster">'
            . '<button class="mai-video-play" aria-label="Play video"' . $titleAttr . '>'
            . '<svg aria-hidden="true" focusable="false"><use href="#mai-icon-play"/></svg>'
            . '</button>'
            . '<template><iframe src="' . htmlspecialchars($iframeUrl, ENT_QUOTES) . '"'
            . ' frameborder="0" allowfullscreen'
            . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
            . '></iframe></template>'
            . '</div>';
    }

    private function renderVimeoFacade(string $vimeoId, ?object $poster, string $title, string $classAttr): string
    {
        $posterUrl = $poster !== null ? $this->getFileUrl($poster) : '';
        $titleAttr = $title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '';
        $iframeUrl = 'https://player.vimeo.com/video/' . htmlspecialchars($vimeoId, ENT_QUOTES) . '?autoplay=1';

        $posterHtml = $posterUrl !== ''
            ? '<img src="' . htmlspecialchars($posterUrl, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '" loading="lazy" class="mai-video-poster">'
            : '';

        return '<div class="mai-video-facade mai-video-vimeo"' . $classAttr . '>'
            . $posterHtml
            . '<button class="mai-video-play" aria-label="Play video"' . $titleAttr . '>'
            . '<svg aria-hidden="true" focusable="false"><use href="#mai-icon-play"/></svg>'
            . '</button>'
            . '<template><iframe src="' . htmlspecialchars($iframeUrl, ENT_QUOTES) . '"'
            . ' frameborder="0" allowfullscreen'
            . ' allow="autoplay; fullscreen; picture-in-picture"'
            . '></iframe></template>'
            . '</div>';
    }

    private function renderSelfHosted(object $file, ?object $poster, string $classAttr): string
    {
        $url = $this->getFileUrl($file);
        if ($url === '') {
            return '';
        }

        $posterAttr = '';
        if ($poster !== null) {
            $posterUrl = $this->getFileUrl($poster);
            if ($posterUrl !== '') {
                $posterAttr = ' poster="' . htmlspecialchars($posterUrl, ENT_QUOTES) . '"';
            }
        }

        // Source order: AV1 → HEVC → H264
        $sources = $this->buildSelfHostedSources($url);

        return '<video'
            . $classAttr
            . ' preload="none"'
            . ' data-lazy'
            . ' controls'
            . $posterAttr
            . '>'
            . $sources
            . '</video>';
    }

    private function buildSelfHostedSources(string $url): string
    {
        // AV1
        $av1Url = $this->deriveVariantUrl($url, 'av1');
        // HEVC / H265
        $hevcUrl = $this->deriveVariantUrl($url, 'hevc');

        $html = '';

        if ($av1Url !== '') {
            $html .= '<source src="' . htmlspecialchars($av1Url, ENT_QUOTES) . '" type="video/mp4; codecs=av01.0.05M.08">';
        }
        if ($hevcUrl !== '') {
            $html .= '<source src="' . htmlspecialchars($hevcUrl, ENT_QUOTES) . '" type="video/mp4; codecs=hvc1">';
        }
        // H264 fallback (original URL assumed to be H264 mp4)
        $html .= '<source src="' . htmlspecialchars($url, ENT_QUOTES) . '" type="video/mp4">';

        return $html;
    }

    private function deriveVariantUrl(string $url, string $codec): string
    {
        // Convention: variant files are named originalname.av1.mp4 or originalname.hevc.mp4
        $pathInfo = pathinfo($url);
        $base = $pathInfo['dirname'] . '/' . $pathInfo['filename'];
        $variantPath = $base . '.' . $codec . '.mp4';

        // Check if variant file exists on filesystem
        $absPath = \TYPO3\CMS\Core\Core\Environment::getPublicPath() . $variantPath;
        if (file_exists($absPath)) {
            return $variantPath;
        }

        return '';
    }

    private function getFileUrl(object $fileReference): string
    {
        if (method_exists($fileReference, 'getPublicUrl')) {
            return (string)$fileReference->getPublicUrl();
        }
        if (method_exists($fileReference, 'getOriginalFile')) {
            return (string)$fileReference->getOriginalFile()->getPublicUrl();
        }
        return '';
    }
}
