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

$APPLICATION->SetTitle(Loc::getMessage('BLOCKSEE_AISEO_CATEGORIES_TITLE') ?: 'AI-генерация описаний категорий');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$catalogIblocks = Options::getCatalogIblocks();

$selectedIblockId = (int)($_REQUEST['IBLOCK_ID'] ?? Options::getIblockId());
if ($selectedIblockId === 0 && !empty($catalogIblocks)) {
    $selectedIblockId = (int)array_key_first($catalogIblocks);
}

$search = trim((string)($_REQUEST['find'] ?? ''));
$scenarioFilter = (string)($_REQUEST['scenario'] ?? 'all');
$page = max(1, (int)($_REQUEST['page'] ?? 1));
$pageSize = 25;

$filter = ['ACTIVE' => 'Y'];
if ($selectedIblockId > 0) {
    $filter['IBLOCK_ID'] = $selectedIblockId;
}
if ($search !== '') {
    $filter['NAME'] = '%' . $search . '%';
}

// Сценарий «empty_only» — отбираем секции с пустым DESCRIPTION прямым SQL,
// иначе пагинация даёт «всплеск пустых» только в пределах одной страницы.
if ($scenarioFilter === 'empty_only' && $selectedIblockId > 0) {
    $emptyIds = [];
    $conn = \Bitrix\Main\Application::getConnection();
    $sqlEmpty = "SELECT ID FROM b_iblock_section WHERE IBLOCK_ID = " . (int)$selectedIblockId
        . " AND ACTIVE='Y'"
        . " AND (DESCRIPTION IS NULL OR DESCRIPTION = '')";
    $rsEmpty = $conn->query($sqlEmpty);
    while ($r = $rsEmpty->fetch()) {
        $emptyIds[] = (int)$r['ID'];
    }
    $filter['ID'] = $emptyIds ?: [-1];
}

$rs = \CIBlockSection::GetList(
    ['LEFT_MARGIN' => 'ASC'],
    $filter,
    true,
    ['ID', 'NAME', 'CODE', 'EXTERNAL_ID', 'IBLOCK_ID', 'IBLOCK_CODE', 'IBLOCK_EXTERNAL_ID', 'DESCRIPTION', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'ELEMENT_CNT', 'PICTURE', 'SECTION_PAGE_URL'],
    ['iNumPage' => $page, 'nPageSize' => $pageSize]
);

$items = [];
while ($row = $rs->Fetch()) {
    $items[] = $row;
}
$totalCount = (int)$rs->SelectedRowsCount();
$pageCount = max(1, (int)ceil($totalCount / $pageSize));

$assetCss = Options::getAssetUrl('/assets/admin.css');
$assetJs = Options::getAssetUrl('/assets/categories.js');
$cssMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . $assetCss) ?: time();
$jsMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . $assetJs) ?: time();
$APPLICATION->SetAdditionalCSS($assetCss . '?v=' . $cssMtime);
$APPLICATION->AddHeadScript($assetJs . '?v=' . $jsMtime);

$customPrompt = Options::getCustomPrompt();

function bsee_cat_img_src(int $fileId, int $w = 80, int $h = 80): string
{
    if ($fileId <= 0) return '';
    $resized = \CFile::ResizeImageGet($fileId, ['width' => $w, 'height' => $h], BX_RESIZE_IMAGE_PROPORTIONAL, true);
    return $resized['src'] ?? '';
}

function bsee_cat_edit_url(int $iblockId, int $sectionId): string
{
    $type = Options::getIblockTypeId($iblockId) ?: 'catalog';
    return "/bitrix/admin/iblock_section_edit.php?IBLOCK_ID={$iblockId}&type=" . urlencode($type) . "&ID={$sectionId}&lang=" . LANGUAGE_ID;
}
?>
<div class="bsee-app">

    <div class="bsee-header-bar">
        <div class="bsee-header-info">
            <div class="bsee-header-target">
                <span class="bsee-label">Сохранять в:</span>
                <b>Описание категории (b_iblock_section.DESCRIPTION)</b>
            </div>
            <a href="blocksee_aiseo_options.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Настройки модуля</a>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Дополнительный промпт</h4>
            <small>Используется тот же дополнительный промпт, что и для описаний товаров. Можно отредактировать на странице описаний товаров.</small>
        </div>
        <textarea id="bsee-custom-prompt" placeholder="Текущий промпт..." readonly><?= htmlspecialcharsbx($customPrompt) ?></textarea>
    </div>

    <form method="get" action="" class="bsee-toolbar">
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <div class="bsee-toolbar-group">
            <label class="bsee-field">
                <span>Инфоблок</span>
                <select name="IBLOCK_ID" onchange="this.form.submit()">
                    <?php foreach ($catalogIblocks as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $id === $selectedIblockId ? 'selected' : '' ?>><?= htmlspecialcharsbx($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="bsee-field">
                <span>Статус</span>
                <select name="scenario">
                    <option value="all" <?= $scenarioFilter === 'all' ? 'selected' : '' ?>>Все категории</option>
                    <option value="empty_only" <?= $scenarioFilter === 'empty_only' ? 'selected' : '' ?>>Только без описания</option>
                </select>
            </label>
            <label class="bsee-field bsee-field-search">
                <span>Поиск</span>
                <input type="text" name="find" value="<?= htmlspecialcharsbx($search) ?>" placeholder="Название категории...">
            </label>
            <button type="submit" class="bsee-btn">Применить</button>
        </div>
    </form>

    <div class="bsee-bulk-panel">
        <div class="bsee-bulk-left">
            <label class="bsee-check-all">
                <input type="checkbox" id="bsee-select-all"> Выбрать все на странице
            </label>
            <div class="bsee-bulk-actions" style="display: none;">
                Выбрано: <strong id="bsee-selected-count">0</strong>
                <button type="button" class="bsee-btn bsee-btn-primary" id="bsee-bulk-generate">Сгенерировать выбранные</button>
                <button type="button" class="bsee-btn" id="bsee-bulk-save">Сохранить изменения</button>
            </div>
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

    <table class="bsee-table">
        <colgroup>
            <col style="width:36px">
            <col style="width:64px">
            <col style="width:280px">
            <col>
            <col style="width:200px">
        </colgroup>
        <thead>
            <tr>
                <th></th>
                <th></th>
                <th>Категория</th>
                <th>Описание</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" class="bsee-empty">Нет категорий по заданному фильтру.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item):
                $hasDescription = trim(strip_tags((string)$item['DESCRIPTION'])) !== '';
                $sectionId = (int)$item['ID'];
                $editUrl = bsee_cat_edit_url((int)$item['IBLOCK_ID'], $sectionId);
                $thumb = bsee_cat_img_src((int)($item['PICTURE'] ?? 0));
                $depth = max(1, (int)$item['DEPTH_LEVEL']);
                $prefix = str_repeat('— ', $depth - 1);
                $cnt = (int)($item['ELEMENT_CNT'] ?? 0);
                $currentDesc = (string)$item['DESCRIPTION'];
                // SECTION_PAGE_URL приходит как сырой шаблон (#SITE_DIR#/catalog/#SECTION_CODE#/),
                // \CIBlockSection::GetList не подставляет плейсхолдеры. Резолвим через
                // CIBlock::ReplaceDetailUrl — он сам обрабатывает #SECTION_CODE#, #SECTION_CODE_PATH#,
                // #SITE_DIR#, #IBLOCK_CODE#, #EXTERNAL_ID# и т.п. (тип 'S' — секция).
                $urlTemplate = (string)($item['SECTION_PAGE_URL'] ?? '');
                $frontendUrl = $urlTemplate !== ''
                    ? \CIBlock::ReplaceDetailUrl($urlTemplate, $item, false, 'S')
                    : '';
                $frontendUrl = trim((string)$frontendUrl);
            ?>
                <tr data-section-id="<?= $sectionId ?>">
                    <td class="bsee-cell-check">
                        <input type="checkbox" class="bsee-item-check" value="<?= $sectionId ?>">
                    </td>
                    <td class="bsee-cell-thumb">
                        <?php if ($thumb): ?>
                            <img src="<?= htmlspecialcharsbx($thumb) ?>" alt="">
                        <?php else: ?>
                            <div class="bsee-thumb-empty">—</div>
                        <?php endif; ?>
                    </td>
                    <td class="bsee-cell-info">
                        <a class="bsee-item-name" href="<?= htmlspecialcharsbx($editUrl) ?>" target="_blank"><?= htmlspecialcharsbx($prefix . $item['NAME']) ?></a>
                        <div class="bsee-item-meta">
                            <span class="bsee-item-id">#<?= $sectionId ?></span>
                            <span class="bsee-item-sections">Товаров: <?= $cnt ?></span>
                        </div>
                        <div class="bsee-item-status <?= $hasDescription ? 'ok' : 'empty' ?>">
                            <?= $hasDescription ? '● Описание есть' : '○ Пусто' ?>
                        </div>
                    </td>
                    <td class="bsee-cell-desc">
                        <textarea class="bsee-desc-textarea" data-original="<?= htmlspecialcharsbx($currentDesc) ?>" placeholder="Описание категории появится здесь после генерации..."><?= htmlspecialcharsbx($currentDesc) ?></textarea>
                    </td>
                    <td class="bsee-cell-actions">
                        <button type="button" class="bsee-btn bsee-btn-small bsee-btn-primary bsee-generate-btn" data-id="<?= $sectionId ?>">Сгенерировать</button>
                        <button type="button" class="bsee-btn bsee-btn-small bsee-save-btn" data-id="<?= $sectionId ?>">Сохранить</button>
                        <?php if ($frontendUrl): ?>
                            <a class="bsee-btn bsee-btn-small bsee-btn-ghost" href="<?= htmlspecialcharsbx($frontendUrl) ?>" target="_blank" rel="noopener" title="Открыть категорию на сайте в новой вкладке">↗ На сайте</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pageCount > 1):
        $baseUrl = $APPLICATION->GetCurPage() . '?' . http_build_query([
            'lang' => LANGUAGE_ID,
            'IBLOCK_ID' => $selectedIblockId,
            'find' => $search,
            'scenario' => $scenarioFilter,
        ]);
        $makeUrl = function ($p) use ($baseUrl) { return htmlspecialcharsbx($baseUrl . '&page=' . $p); };

        $windowSize = 5;
        $start = max(1, $page - (int)floor($windowSize / 2));
        $end = min($pageCount, $start + $windowSize - 1);
        if ($end - $start + 1 < $windowSize) {
            $start = max(1, $end - $windowSize + 1);
        }
    ?>
        <div class="bsee-pagination">
            <?php if ($page > 1): ?>
                <a class="bsee-page bsee-page-nav" href="<?= $makeUrl($page - 1) ?>">← Назад</a>
            <?php else: ?>
                <span class="bsee-page bsee-page-nav disabled">← Назад</span>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++):
                if ($p === $page): ?>
                    <span class="bsee-page current"><?= $p ?></span>
                <?php else: ?>
                    <a class="bsee-page" href="<?= $makeUrl($p) ?>"><?= $p ?></a>
                <?php endif;
            endfor; ?>

            <?php if ($page < $pageCount): ?>
                <a class="bsee-page bsee-page-nav" href="<?= $makeUrl($page + 1) ?>">Далее →</a>
            <?php else: ?>
                <span class="bsee-page bsee-page-nav disabled">Далее →</span>
            <?php endif; ?>

            <span class="bsee-pagination-total">Стр. <?= $page ?> из <?= $pageCount ?> · Всего категорий: <?= $totalCount ?></span>
        </div>
    <?php endif; ?>

</div>

<script>
window.BlockseeAiseoConfig = {
    sessid: <?= \CUtil::PhpToJSObject(bitrix_sessid()) ?>,
    ajaxUrl: '/bitrix/services/main/ajax.php',
    controller: 'blocksee:aiseo.category',
    iblockId: <?= (int)$selectedIblockId ?>,
};
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
