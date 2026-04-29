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

$APPLICATION->SetTitle('БЛОКСИ: ИИ SEO — Генерация по ссылкам');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$cssMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/local/modules/blocksee.aiseo/assets/admin.css') ?: time();
$jsMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/local/modules/blocksee.aiseo/assets/list_urls.js') ?: time();
$APPLICATION->SetAdditionalCSS('/local/modules/blocksee.aiseo/assets/admin.css?v=' . $cssMtime);
$APPLICATION->AddHeadScript('/local/modules/blocksee.aiseo/assets/list_urls.js?v=' . $jsMtime);

$targetField = Options::getTargetField();
// switch вместо match() для совместимости с PHP 7.4.
switch ($targetField) {
    case 'PREVIEW_TEXT':
        $targetLabel = 'Краткое описание (PREVIEW_TEXT)';
        break;
    case 'BOTH':
        $targetLabel = 'Подробное + краткое описание';
        break;
    case 'PROPERTY':
        $targetLabel = 'Свойство: ' . Options::getTargetPropertyCode();
        break;
    default:
        $targetLabel = 'Подробное описание (DETAIL_TEXT)';
}
$customPrompt = Options::getCustomPrompt();
?>
<div class="bsee-app">

    <div class="bsee-header-bar">
        <div class="bsee-header-info">
            <div class="bsee-header-target">
                <span class="bsee-label">Сохранять в:</span>
                <b><?= htmlspecialcharsbx($targetLabel) ?></b>
            </div>
            <a href="blocksee_aiseo_list.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">К списку товаров</a>
            <a href="blocksee_aiseo_options.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Настройки модуля</a>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Дополнительный промпт для описаний товаров</h4>
            <small>Добавляется к базовому промпту на стороне API. Пусто — используется стандартный промпт сервера. Сохраняется глобально и применяется и здесь, и в обычной массовой генерации.</small>
        </div>
        <textarea id="bsee-custom-prompt" placeholder="Например: тон речи — экспертный, фокус на материалах и преимуществах, добавлять призыв к действию в финале..."><?= htmlspecialcharsbx($customPrompt) ?></textarea>
        <div class="bsee-prompt-footer">
            <button type="button" class="bsee-btn bsee-btn-primary" id="bsee-save-prompt">Сохранить промпт</button>
            <span id="bsee-prompt-status" class="bsee-muted"></span>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Список URL карточек товаров</h4>
            <small>Каждая ссылка с новой строки. Поддерживаются полные URL (<code>https://site.ru/catalog/cat/PRODUCT-CODE/</code>) и просто пути (<code>/catalog/cat/PRODUCT-CODE/</code>). Резолв идёт по символьному коду (последний сегмент пути), при необходимости — по DETAIL_PAGE_URL. Можно вставить хоть 1000 строк.</small>
        </div>
        <textarea id="bsee-urls-input" placeholder="https://mebelesd.ru/catalog/.../my-product/&#10;https://mebelesd.ru/catalog/.../another-product/&#10;..." rows="8"></textarea>
        <div class="bsee-prompt-footer">
            <button type="button" class="bsee-btn bsee-btn-primary" id="bsee-resolve-urls">Найти товары</button>
            <button type="button" class="bsee-btn bsee-btn-accent" id="bsee-bulk-generate-urls" style="display:none;">Сгенерировать описания →</button>
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
    controller: 'blocksee:aiseo.generator',
};
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
