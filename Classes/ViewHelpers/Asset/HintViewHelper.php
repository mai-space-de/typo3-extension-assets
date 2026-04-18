<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Asset;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

final class HintViewHelper extends AbstractViewHelper
{
    private const ALLOWED_REL = ['preconnect', 'dns-prefetch', 'preload', 'prefetch', 'modulepreload'];

    protected $escapeOutput = false;

    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {
        parent::__construct();
    }

    public function initializeArguments(): void
    {
        $this->registerArgument('rel', 'string', 'Link relation type', true);
        $this->registerArgument('href', 'string', 'URL', true);
        $this->registerArgument('as', 'string', 'Resource type for preload/modulepreload', false, '');
        $this->registerArgument('type', 'string', 'MIME type', false, '');
        $this->registerArgument('crossorigin', 'string', 'CORS attribute', false, '');
        $this->registerArgument('media', 'string', 'Media query', false, '');
    }

    public function render(): string
    {
        $rel = (string)$this->arguments['rel'];
        $href = (string)$this->arguments['href'];
        $as = (string)$this->arguments['as'];
        $type = (string)$this->arguments['type'];
        $crossorigin = (string)$this->arguments['crossorigin'];
        $media = (string)$this->arguments['media'];

        if (!in_array($rel, self::ALLOWED_REL, true)) {
            return '';
        }

        // preconnect should always have crossorigin; auto-add anonymous if missing
        if ($rel === 'preconnect' && $crossorigin === '') {
            $crossorigin = 'anonymous';
        }

        // preload with as="font" requires crossorigin
        if ($rel === 'preload' && $as === 'font' && $crossorigin === '') {
            $crossorigin = 'anonymous';
        }

        $attrs = 'rel="' . htmlspecialchars($rel, ENT_QUOTES) . '"'
            . ' href="' . htmlspecialchars($href, ENT_QUOTES) . '"';

        if ($as !== '') {
            $attrs .= ' as="' . htmlspecialchars($as, ENT_QUOTES) . '"';
        }
        if ($type !== '') {
            $attrs .= ' type="' . htmlspecialchars($type, ENT_QUOTES) . '"';
        }
        if ($crossorigin !== '') {
            $attrs .= ' crossorigin="' . htmlspecialchars($crossorigin, ENT_QUOTES) . '"';
        }
        if ($media !== '') {
            $attrs .= ' media="' . htmlspecialchars($media, ENT_QUOTES) . '"';
        }

        $tag = '<link ' . $attrs . '>';
        $deduplicationKey = 'mai-hint-' . md5($rel . $href);
        $this->pageRenderer->addHeaderData($tag . '<!-- ' . $deduplicationKey . ' -->');

        return '';
    }
}
