<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Mai Assets',
    'description' => 'The canonical asset pipeline for the entire extension set. Provides Fluid ViewHelper-based asset inclusion with minification, SCSS compilation, and SVG sprite building. Also manages the TYPO3 file abstraction layer via `cms-filelist` and `cms-filemetadata`. All other extensions that need SCSS compilation or asset minification depend on this extension rather than pulling in `scssphp` or minification libraries directly.',
    'category' => 'module',
    'author' => 'Maispace',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
