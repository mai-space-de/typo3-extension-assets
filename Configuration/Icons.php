<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'ext-maispace-mai_assets' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:mai_assets/Resources/Public/Icons/Extension.svg',
    ],
];
