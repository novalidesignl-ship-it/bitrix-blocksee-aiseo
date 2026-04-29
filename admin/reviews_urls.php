<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Blocksee\Aiseo\Options;

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

if (!Loader::includeModule('blocksee.aiseo')) {
    ShowError('Модуль blocksee.aiseo не подключён');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

Loc::loadMessages(__FILE__);
Loader::includeModule('iblock');

$APPLICATION->SetTitle('БЛОКСИ: ИИ SEO — Генерация отзывов по ссылкам');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$assetCss = Options::getAssetUrl('/assets/admin.css');
$assetJs = Options::getAssetUrl('/assets/reviews_urls.js');
$cssMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . $assetCss) ?: time();
$jsMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . $assetJs) ?: time();
$APPLICATION->SetAdditionalCSS($assetCss . '?v=' . $cssMtime);
$APPLICATION->AddHeadScript($assetJs . '?v=' . $jsMtime);

$reviewsPerProduct = Options::getReviewsPerProduct();
$reviewsSettings = Options::getReviewsSettings();
$customPrompt = (string)($reviewsSettings['custom_prompt'] ?? '');

$src = Options::resolveReviewsSource();
$srcLabel = '';
if ($src === Options::REVIEWS_SOURCE_BLOG) {
    $blogId = Options::getReviewsBlogId();
    $srcLabel = 'Блог «' . htmlspecialcharsbx(Options::getReviewsBlogUrl()) . '»' . ($blogId > 0 ? ' · ID ' . $blogId : '');
} elseif ($src === Options::REVIEWS_SOURCE_FORUM) {
    $forumId = Options::getReviewsForumId();
    $srcLabel = 'Форум' . ($forumId > 0 ? ' ID ' . $forumId : ' (не задан)');
} else {
    $srcLabel = '<span style="color:#dc2626;">не настроен</span>';
}
?>
<div class="bsee-app">

    <div class="bsee-header-bar">
        <div class="bsee-header-info">
            <div class="bsee-header-target">
                <span class="bsee-label">Источник отзывов:</span>
                <b><?= $srcLabel ?></b>
            </div>
            <a href="blocksee_aiseo_reviews.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">К списку товаров</a>
            <a href="blocksee_aiseo_options.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Настройки модуля</a>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Дополнительный промпт для отзывов</h4>
            <small>Добавляется к базовому промпту на стороне API. Пусто — используется стандартный промпт сервера. Сохраняется глобально и применяется и здесь, и на обычной странице массовой генерации отзывов.</small>
        </div>
        <textarea id="bsee-custom-prompt" placeholder="Например: упоминать конкретные сценарии использования, не использовать восклицания, тон спокойный..."><?= htmlspecialcharsbx($customPrompt) ?></textarea>
        <div class="bsee-prompt-footer">
            <button type="button" class="bsee-btn bsee-btn-primary" id="bsee-save-prompt">Сохранить промпт</button>
            <span id="bsee-prompt-status" class="bsee-muted"></span>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Список URL карточек товаров</h4>
            <small>Каждая ссылка с новой строки. Поддерживаются полные URL (<code>https://site.ru/catalog/cat/PRODUCT-CODE/</code>) и пути (<code>/catalog/cat/PRODUCT-CODE/</code>). Резолв идёт по символьному коду (последний сегмент пути). Можно вставить хоть 1000 строк.</small>
        </div>
        <textarea id="bsee-urls-input" placeholder="https://mebelesd.ru/catalog/.../my-product/&#10;https://mebelesd.ru/catalog/.../another-product/&#10;..." rows="8"></textarea>
        <div class="bsee-prompt-footer" style="gap: 14px; flex-wrap: wrap;">
            <label class="bsee-field" style="display:flex; align-items:center; gap:8px; margin:0;">
                <span style="white-space:nowrap;">Отзывов на товар:</span>
                <input type="number" id="bsee-rev-count" min="1" max="20" value="<?= (int)$reviewsPerProduct ?>" class="bsee-rev-count-input" style="width: 70px;">
            </label>
            <label id="bsee-filter-no-reviews-wrap" style="display:none; align-items:center; gap:6px; margin:0; cursor: pointer;">
                <input type="checkbox" id="bsee-filter-no-reviews" style="margin:0;">
                <span style="white-space:nowrap;">Только без отзывов</span>
                <span id="bsee-filter-counter" class="bsee-muted" style="font-size:12px;"></span>
            </label>
            <button type="button" class="bsee-btn bsee-btn-primary" id="bsee-resolve-urls">Найти товары</button>
            <button type="button" class="bsee-btn bsee-btn-accent" id="bsee-bulk-generate-urls" style="display:none;">Сгенерировать отзывы →</button>
            <span id="bsee-urls-status" class="bsee-muted"></span>
        </div>
    </div>

    <div id="bsee-progress" class="bsee-progress" style="display:none;">
        <div class="bsee-progress-head">
            <span id="bsee-progress-text">Обработка...</span>
            <span id="bsee-progress-counts">0 / 0</span>
        </div>
        <div class="bsee-progress-track"><div class="bsee-progress-bar" id="bsee-progress-bar"></div></div>
        <button type="button" class="bsee-btn bsee-btn-ghost bsee-btn-small" id="bsee-progress-cancel">Отменить</button>
    </div>

    <table class="bsee-table" id="bsee-urls-table" style="display:none;">
        <colgroup>
            <col style="width:60px">
            <col>
            <col style="width:280px">
            <col style="width:130px">
            <col style="width:180px">
        </colgroup>
        <thead>
            <tr>
                <th>#</th>
                <th>URL</th>
                <th>Товар</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody id="bsee-urls-tbody"></tbody>
    </table>

</div>

<script>
window.BlockseeAiseoUrlsConfig = {
    sessid: <?= \CUtil::PhpToJSObject(bitrix_sessid()) ?>,
    ajaxUrl: '/bitrix/services/main/ajax.php',
    resolveController: 'blocksee:aiseo.generator',
    generateController: 'blocksee:aiseo.reviews',
    promptController: 'blocksee:aiseo.reviews',
};
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
