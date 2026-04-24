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
        'Blocksee\\Aiseo\\Reviews\\Backend' => 'lib/reviews/backend.php',
        'Blocksee\\Aiseo\\Reviews\\ForumBackend' => 'lib/reviews/forumbackend.php',
        'Blocksee\\Aiseo\\Reviews\\BlogBackend' => 'lib/reviews/blogbackend.php',
        'Blocksee\\Aiseo\\Reviews\\Factory' => 'lib/reviews/factory.php',
        'Blocksee\\Aiseo\\Reviews\\Scenarios' => 'lib/reviews/scenarios.php',
        'Blocksee\\Aiseo\\Reviews\\PersonaPool' => 'lib/reviews/personapool.php',
    ]
);
