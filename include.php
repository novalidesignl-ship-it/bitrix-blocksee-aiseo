<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
    'blocksee.aiseo',
    [
        'Blocksee\\Aiseo\\ApiClient' => 'lib/apiclient.php',
        'Blocksee\\Aiseo\\Generator' => 'lib/generator.php',
        'Blocksee\\Aiseo\\ReviewsGenerator' => 'lib/reviewsgenerator.php',
        'Blocksee\\Aiseo\\Options' => 'lib/options.php',
        'Blocksee\\Aiseo\\TextSanitizer' => 'lib/textsanitizer.php',
        'Blocksee\\Aiseo\\Controller\\Generator' => 'lib/controller/generator.php',
        'Blocksee\\Aiseo\\Controller\\Reviews' => 'lib/controller/reviews.php',
    ]
);
