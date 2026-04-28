<?php

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $USER;
if (!$USER || !$USER->IsAdmin()) {
    return false;
}

return [
    'parent_menu' => 'global_menu_services',
    'sort' => 500,
    'text' => Loc::getMessage('BLOCKSEE_AISEO_MENU_ROOT') ?: 'AI Описания товаров',
    'title' => Loc::getMessage('BLOCKSEE_AISEO_MENU_ROOT_TITLE') ?: 'Генерация описаний товаров через AI',
    'url' => 'blocksee_aiseo_list.php?lang=' . LANGUAGE_ID,
    'icon' => 'iblock_menu_icon_blocks',
    'page_icon' => 'iblock_page_icon_blocks',
    'items_id' => 'menu_blocksee_aiseo',
    'items' => [
        [
            'text' => Loc::getMessage('BLOCKSEE_AISEO_MENU_LIST') ?: 'Описания товаров',
            'url' => 'blocksee_aiseo_list.php?lang=' . LANGUAGE_ID,
            'more_url' => ['blocksee_aiseo_list_urls.php'],
            'title' => Loc::getMessage('BLOCKSEE_AISEO_MENU_LIST_TITLE') ?: 'Генерация описаний',
        ],
        [
            'text' => Loc::getMessage('BLOCKSEE_AISEO_MENU_REVIEWS') ?: 'Отзывы товаров',
            'url' => 'blocksee_aiseo_reviews.php?lang=' . LANGUAGE_ID,
            'more_url' => ['blocksee_aiseo_reviews_urls.php'],
            'title' => Loc::getMessage('BLOCKSEE_AISEO_MENU_REVIEWS_TITLE') ?: 'Генерация отзывов',
        ],
        [
            'text' => Loc::getMessage('BLOCKSEE_AISEO_MENU_OPTIONS') ?: 'Настройки',
            'url' => 'blocksee_aiseo_options.php?lang=' . LANGUAGE_ID,
            'more_url' => [],
            'title' => Loc::getMessage('BLOCKSEE_AISEO_MENU_OPTIONS_TITLE') ?: 'Настройки модуля',
        ],
    ],
];
