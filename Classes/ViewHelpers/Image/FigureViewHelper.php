<?php

declare(strict_types=1);

namespace Maispace\MaiAssets\ViewHelpers\Image;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Wraps child content in a semantic <figure> element with optional <figcaption>.
 *
 * Usage:
 *   <mai:figure caption="A descriptive caption" class="my-figure">
 *     <mai:image ... />
 *   </mai:figure>
 */
final class FigureViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('caption', 'string', 'Caption text for <figcaption>', false, '');
        $this->registerArgument('class', 'string', 'CSS class on <figure>', false, '');
        $this->registerArgument('id', 'string', 'id attribute on <figure>', false, '');
        $this->registerArgument('role', 'string', 'role attribute on <figure>', false, '');
    }

    public function render(): string
    {
        $caption = (string)$this->arguments['caption'];
        $class = (string)$this->arguments['class'];
        $id = (string)$this->arguments['id'];
        $role = (string)$this->arguments['role'];

        $attrs = '';
        if ($class !== '') {
            $attrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"';
        }
        if ($id !== '') {
            $attrs .= ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"';
        }
        if ($role !== '') {
            $attrs .= ' role="' . htmlspecialchars($role, ENT_QUOTES) . '"';
        }

        $children = $this->renderChildren();
        $figcaption = $caption !== ''
            ? '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES) . '</figcaption>'
            : '';

        return '<figure' . $attrs . '>' . $children . $figcaption . '</figure>';
    }
}
