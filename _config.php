<?php

use SilverStripe\Forms\HTMLEditor\TinyMCEConfig;

$selector = 'p,h1,h2,h3,h4,h5,h6,td,th,li,div,ul,ol,table,img,figure,blockquote';

TinyMCEConfig::get('newsletter')
    ->setOption('friendly_name', 'Newsletter')
    ->setOption('formats', [
        'alignleft' => [
            'selector' => $selector,
            'styles' => ['text-align' => 'left'],
        ],
        'aligncenter' => [
            'selector' => $selector,
            'styles' => ['text-align' => 'center'],
        ],
        'alignright' => [
            'selector' => $selector,
            'styles' => ['text-align' => 'right'],
        ],
        'alignjustify' => [
            'selector' => $selector,
            'styles' => ['text-align' => 'justify'],
        ],
    ]);
