<?php

$EM_CONF[$_EXTKEY] = [
    'title'            => 'Maispace Assets',
    'description'      => 'Easy inline or path-based asset inclusion (CSS, JS, SCSS) from Fluid templates with minification, SVG sprites, and performance-first defaults.',
    'category'         => 'fe',
    'version'          => '1.0.0',
    'state'            => 'stable',
    'author'           => 'Maispace',
    'author_email'     => '',
    'author_company'   => 'Maispace',
    'clearCacheOnLoad' => true,
    'constraints'      => [
        'depends'   => [
            'php'   => '8.1.0-0.0.0',
            'typo3' => '12.4.0-13.9.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
];
