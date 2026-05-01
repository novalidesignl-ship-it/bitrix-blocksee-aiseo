<?php

use Bitrix\Main\Loader;

// Полифиллы для совместимости с PHP < 7.3 (часть хостингов до сих пор на 7.2).
// array_key_first/last появились в PHP 7.3.
if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach ($arr as $key => $unused) {
            return $key;
        }
        return null;
    }
}
if (!function_exists('array_key_last')) {
    function array_key_last(array $arr) {
        return key(array_slice($arr, -1, 1, true));
    }
}

Loader::registerAutoLoadClasses(
    'blocksee.aiseo',
    [
        'Blocksee\\Aiseo\\ApiClient' => 'lib/apiclient.php',
        'Blocksee\\Aiseo\\Generator' => 'lib/generator.php',
        'Blocksee\\Aiseo\\ReviewsGenerator' => 'lib/reviewsgenerator.php',
        'Blocksee\\Aiseo\\CategoryGenerator' => 'lib/categorygenerator.php',
        'Blocksee\\Aiseo\\Options' => 'lib/options.php',
        'Blocksee\\Aiseo\\TextSanitizer' => 'lib/textsanitizer.php',
        'Blocksee\\Aiseo\\BackupStorage' => 'lib/backupstorage.php',
        'Blocksee\\Aiseo\\Controller\\Generator' => 'lib/controller/generator.php',
        'Blocksee\\Aiseo\\Controller\\Reviews' => 'lib/controller/reviews.php',
        'Blocksee\\Aiseo\\Controller\\Category' => 'lib/controller/category.php',
        'Blocksee\\Aiseo\\Reviews\\Backend' => 'lib/reviews/backend.php',
        'Blocksee\\Aiseo\\Reviews\\ForumBackend' => 'lib/reviews/forumbackend.php',
        'Blocksee\\Aiseo\\Reviews\\BlogBackend' => 'lib/reviews/blogbackend.php',
        'Blocksee\\Aiseo\\Reviews\\Factory' => 'lib/reviews/factory.php',
        'Blocksee\\Aiseo\\Reviews\\Scenarios' => 'lib/reviews/scenarios.php',
        'Blocksee\\Aiseo\\Reviews\\PersonaPool' => 'lib/reviews/personapool.php',
    ]
);
