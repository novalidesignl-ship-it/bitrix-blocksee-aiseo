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
$hasCatalog = Loader::includeModule('catalog');

$APPLICATION->SetTitle(Loc::getMessage('BLOCKSEE_AISEO_LIST_TITLE') ?: 'AI-генерация описаний товаров');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$catalogIblocks = Options::getCatalogIblocks();

$iblockTypeMap = [];
if (!empty($catalogIblocks)) {
    $rsT = \CIBlock::GetList([], ['ID' => array_keys($catalogIblocks)]);
    while ($t = $rsT->Fetch()) {
        $iblockTypeMap[(int)$t['ID']] = (string)$t['IBLOCK_TYPE_ID'];
    }
}

$selectedIblockId = (int)($_REQUEST['IBLOCK_ID'] ?? Options::getIblockId());
if ($selectedIblockId === 0 && !empty($catalogIblocks)) {
    $selectedIblockId = (int)array_key_first($catalogIblocks);
}

$search = trim((string)($_REQUEST['find'] ?? ''));
$scenarioFilter = (string)($_REQUEST['scenario'] ?? 'all');
$selectedSectionId = (int)($_REQUEST['SECTION_ID'] ?? 0);
$page = max(1, (int)($_REQUEST['page'] ?? 1));
$pageSize = 25;

$filter = ['ACTIVE' => 'Y'];
if ($selectedIblockId > 0) {
    $filter['IBLOCK_ID'] = $selectedIblockId;
}
if ($search !== '') {
    $filter['NAME'] = '%' . $search . '%';
}
if ($selectedSectionId > 0) {
    $filter['SECTION_ID'] = $selectedSectionId;
    $filter['INCLUDE_SUBSECTIONS'] = 'Y';
}

// Сценарий «empty_only» — ограничиваем выборку списком ID товаров,
// у которых ОБА поля DETAIL_TEXT и PREVIEW_TEXT строго пустые/NULL.
// Без этой явной фильтрации пагинация показывает только случайных пустых,
// попавших в текущую страницу (ровно столько, сколько их оказалось среди 25
// первых по NAME ASC) — баг, на который пожаловался пользователь.
if ($scenarioFilter === 'empty_only' && $selectedIblockId > 0) {
    $emptyIds = [];
    $conn = \Bitrix\Main\Application::getConnection();
    $sqlEmpty = "SELECT ID FROM b_iblock_element WHERE IBLOCK_ID = " . (int)$selectedIblockId
        . " AND ACTIVE='Y'"
        . " AND (DETAIL_TEXT IS NULL OR DETAIL_TEXT = '')"
        . " AND (PREVIEW_TEXT IS NULL OR PREVIEW_TEXT = '')";
    $rsEmpty = $conn->query($sqlEmpty);
    while ($r = $rsEmpty->fetch()) {
        $emptyIds[] = (int)$r['ID'];
    }
    // Пустой результат: подставляем -1, чтобы CIBlockElement::GetList вернул 0 строк
    // (с пустым массивом он бы проигнорировал условие и вернул всё).
    $filter['ID'] = $emptyIds ?: [-1];
}

$rs = \CIBlockElement::GetList(
    ['NAME' => 'ASC'],
    $filter,
    false,
    ['iNumPage' => $page, 'nPageSize' => $pageSize],
    ['ID', 'NAME', 'IBLOCK_ID', 'DETAIL_TEXT', 'PREVIEW_TEXT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'CATALOG_GROUP_1']
);

$items = [];
while ($row = $rs->Fetch()) {
    $items[] = $row;
}
$totalCount = (int)$rs->SelectedRowsCount();
$pageCount = max(1, (int)ceil($totalCount / $pageSize));

$cssMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/local/modules/blocksee.aiseo/assets/admin.css') ?: time();
$jsMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/local/modules/blocksee.aiseo/assets/admin.js') ?: time();
$APPLICATION->SetAdditionalCSS('/local/modules/blocksee.aiseo/assets/admin.css?v=' . $cssMtime);
$APPLICATION->AddHeadScript('/local/modules/blocksee.aiseo/assets/admin.js?v=' . $jsMtime);

// Sections for toolbar + modal
$sections = [];
if ($selectedIblockId > 0) {
    $rsSections = \CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['IBLOCK_ID' => $selectedIblockId, 'ACTIVE' => 'Y', 'CNT_ACTIVE' => 'Y', 'ELEMENT_SUBSECTIONS' => 'Y'],
        true,
        ['ID', 'NAME', 'DEPTH_LEVEL', 'ELEMENT_CNT'],
        false
    );
    while ($s = $rsSections->Fetch()) {
        $sections[] = $s;
    }
}

$targetField = Options::getTargetField();
// switch вместо match() для совместимости с PHP 7.4 (часть хостингов до сих пор на 7.4).
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

function bsee_img_src(int $fileId, int $w = 80, int $h = 80): string
{
    if ($fileId <= 0) return '';
    $resized = \CFile::ResizeImageGet($fileId, ['width' => $w, 'height' => $h], BX_RESIZE_IMAGE_PROPORTIONAL, true);
    return $resized['src'] ?? '';
}

function bsee_get_sections(int $elementId): string
{
    $names = [];
    $rs = \CIBlockElement::GetElementGroups($elementId, true, ['ID', 'NAME']);
    while ($r = $rs->Fetch()) {
        $names[] = (string)$r['NAME'];
    }
    return implode(' · ', array_slice($names, 0, 2));
}
?>
<div class="bsee-app">

    <div class="bsee-header-bar">
        <div class="bsee-header-info">
            <div class="bsee-header-target">
                <span class="bsee-label">Сохранять в:</span>
                <b><?= htmlspecialcharsbx($targetLabel) ?></b>
            </div>
            <a href="blocksee_aiseo_list_urls.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Генерация по ссылкам →</a>
            <a href="blocksee_aiseo_options.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Настройки модуля</a>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Дополнительный промпт для описаний товаров</h4>
            <small>Добавляется к базовому промпту на стороне API. Пусто — используется стандартный промпт сервера.</small>
        </div>
        <textarea id="bsee-custom-prompt" placeholder="Например: тон речи — экспертный, фокус на материалах и преимуществах, добавлять призыв к действию в финале..."><?= htmlspecialcharsbx($customPrompt) ?></textarea>
        <div class="bsee-prompt-footer">
            <button type="button" class="bsee-btn bsee-btn-primary" id="bsee-save-prompt">Сохранить промпт</button>
            <span id="bsee-prompt-status" class="bsee-muted"></span>
        </div>
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
                <span>Категория</span>
                <select name="SECTION_ID">
                    <option value="0">Все категории</option>
                    <?php foreach ($sections as $s):
                        $depth = max(1, (int)$s['DEPTH_LEVEL']);
                        $prefix = str_repeat('— ', $depth - 1);
                    ?>
                        <option value="<?= (int)$s['ID'] ?>" <?= (int)$s['ID'] === $selectedSectionId ? 'selected' : '' ?>>
                            <?= htmlspecialcharsbx($prefix . $s['NAME']) ?> (<?= (int)$s['ELEMENT_CNT'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="bsee-field">
                <span>Статус</span>
                <select name="scenario">
                    <option value="all" <?= $scenarioFilter === 'all' ? 'selected' : '' ?>>Все товары</option>
                    <option value="empty_only" <?= $scenarioFilter === 'empty_only' ? 'selected' : '' ?>>Только без описания</option>
                </select>
            </label>
            <label class="bsee-field bsee-field-search">
                <span>Поиск</span>
                <input type="text" name="find" value="<?= htmlspecialcharsbx($search) ?>" placeholder="Название товара...">
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
                <button type="button" class="bsee-btn bsee-btn-ghost" id="bsee-bulk-restore" title="Откатить выделенные товары к предыдущей версии описания">↶ Откатить выделенные</button>
            </div>
        </div>
        <div class="bsee-bulk-right">
            <button type="button" class="bsee-btn bsee-btn-accent" id="bsee-open-scenario">
                Массовая автоматическая генерация →
            </button>
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
            <col style="width:260px">
            <col>
            <col style="width:200px">
        </colgroup>
        <thead>
            <tr>
                <th></th>
                <th></th>
                <th>Товар</th>
                <th>Описание</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" class="bsee-empty">Нет товаров по заданному фильтру.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item):
                $hasDescription = trim(strip_tags((string)$item['DETAIL_TEXT'])) !== ''
                    || trim(strip_tags((string)$item['PREVIEW_TEXT'])) !== '';
                $elementId = (int)$item['ID'];
                $iblockTypeId = $iblockTypeMap[(int)$item['IBLOCK_ID']] ?? 'catalog';
                $editUrl = "/bitrix/admin/iblock_element_edit.php?IBLOCK_ID={$item['IBLOCK_ID']}&type=" . urlencode($iblockTypeId) . "&ID={$elementId}&lang=" . LANGUAGE_ID;
                $thumb = bsee_img_src((int)($item['PREVIEW_PICTURE'] ?: $item['DETAIL_PICTURE']));
                $price = $hasCatalog ? (float)($item['CATALOG_PRICE_1'] ?? 0) : 0;
                $currency = (string)($item['CATALOG_CURRENCY_1'] ?? 'RUB');
                $itemSections = bsee_get_sections($elementId);
                $currentDesc = $targetField === 'PREVIEW_TEXT'
                    ? (string)$item['PREVIEW_TEXT']
                    : (string)($item['DETAIL_TEXT'] ?: $item['PREVIEW_TEXT']);
            ?>
                <tr data-element-id="<?= $elementId ?>">
                    <td class="bsee-cell-check">
                        <input type="checkbox" class="bsee-item-check" value="<?= $elementId ?>">
                    </td>
                    <td class="bsee-cell-thumb">
                        <?php if ($thumb): ?>
                            <img src="<?= htmlspecialcharsbx($thumb) ?>" alt="">
                        <?php else: ?>
                            <div class="bsee-thumb-empty">—</div>
                        <?php endif; ?>
                    </td>
                    <td class="bsee-cell-info">
                        <a class="bsee-item-name" href="<?= htmlspecialcharsbx($editUrl) ?>" target="_blank"><?= htmlspecialcharsbx($item['NAME']) ?></a>
                        <div class="bsee-item-meta">
                            <span class="bsee-item-id">#<?= $elementId ?></span>
                            <?php if ($itemSections): ?>
                                <span class="bsee-item-sections"><?= htmlspecialcharsbx($itemSections) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($price > 0): ?>
                            <div class="bsee-item-price"><?= number_format($price, 2, ',', ' ') ?> <?= htmlspecialcharsbx($currency) ?></div>
                        <?php endif; ?>
                        <div class="bsee-item-status <?= $hasDescription ? 'ok' : 'empty' ?>">
                            <?= $hasDescription ? '● Описание есть' : '○ Пусто' ?>
                        </div>
                    </td>
                    <td class="bsee-cell-desc">
                        <textarea class="bsee-desc-textarea" data-original="<?= htmlspecialcharsbx($currentDesc) ?>" placeholder="Описание товара появится здесь после генерации..."><?= htmlspecialcharsbx($currentDesc) ?></textarea>
                    </td>
                    <td class="bsee-cell-actions">
                        <button type="button" class="bsee-btn bsee-btn-small bsee-btn-primary bsee-generate-btn" data-id="<?= $elementId ?>">Сгенерировать</button>
                        <button type="button" class="bsee-btn bsee-btn-small bsee-save-btn" data-id="<?= $elementId ?>">Сохранить</button>
                        <button type="button" class="bsee-btn bsee-btn-small bsee-btn-ghost bsee-restore-btn" data-id="<?= $elementId ?>" title="Откатить к предыдущей версии">↶ Откатить</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pageCount > 1):
        $baseUrl = $APPLICATION->GetCurPage() . '?' . http_build_query([
            'lang' => LANGUAGE_ID,
            'IBLOCK_ID' => $selectedIblockId,
            'SECTION_ID' => $selectedSectionId,
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

            <span class="bsee-pagination-total">Стр. <?= $page ?> из <?= $pageCount ?> · Всего товаров: <?= $totalCount ?></span>
        </div>
    <?php endif; ?>

    <div id="bsee-scenario-modal" class="bsee-modal" style="display:none;">
        <div class="bsee-modal-box">
            <div class="bsee-modal-head">
                <h3>Массовая автоматическая генерация</h3>
                <button type="button" class="bsee-modal-close">&times;</button>
            </div>

            <div class="bsee-modal-body">
                <div class="bsee-scenario-block">
                    <div class="bsee-scenario-block-head">
                        <h4>1. Выберите категории</h4>
                        <div class="bsee-scenario-block-actions">
                            <button type="button" class="bsee-btn bsee-btn-ghost bsee-btn-small" id="bsee-sections-select-all">Выбрать все</button>
                            <button type="button" class="bsee-btn bsee-btn-ghost bsee-btn-small" id="bsee-sections-clear">Очистить</button>
                        </div>
                    </div>
                    <small class="bsee-muted">Пусто = обрабатываются все товары. Выбор родительской категории включает все вложенные.</small>

                    <?php if (!empty($sections)): ?>
                        <div class="bsee-sections-search-wrap">
                            <input type="text" id="bsee-sections-search" class="bsee-sections-search" placeholder="🔎 Поиск по категориям...">
                        </div>
                    <?php endif; ?>

                    <div class="bsee-sections-tree">
                        <?php if (empty($sections)): ?>
                            <div class="bsee-muted" style="padding: 8px 0;">В инфоблоке нет категорий — будут обработаны все товары.</div>
                        <?php else: ?>
                            <?php foreach ($sections as $s):
                                $depth = max(1, (int)$s['DEPTH_LEVEL']);
                                $cnt = (int)$s['ELEMENT_CNT'];
                            ?>
                                <label class="bsee-section-item" style="padding-left: <?= ($depth - 1) * 20 + 4 ?>px;" data-name="<?= htmlspecialcharsbx(mb_strtolower($s['NAME'])) ?>">
                                    <input type="checkbox" class="bsee-section-check" value="<?= (int)$s['ID'] ?>">
                                    <span class="bsee-section-name"><?= htmlspecialcharsbx($s['NAME']) ?></span>
                                    <span class="bsee-section-cnt"><?= $cnt ?></span>
                                </label>
                            <?php endforeach; ?>
                            <div id="bsee-sections-empty" class="bsee-muted" style="padding: 10px 12px; display:none;">Ничего не найдено.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bsee-scenario-block">
                    <div class="bsee-scenario-block-head">
                        <h4>2. Выберите сценарий</h4>
                    </div>
                    <div class="bsee-scenario-options">
                        <div class="bsee-scenario-option" data-scenario="empty_only">
                            <div class="bsee-scenario-title">Заполнить только пустые</div>
                            <div class="bsee-scenario-desc">Генерирует описания только для товаров, у которых описание отсутствует. Существующие не трогает.</div>
                            <div class="bsee-scenario-badge">Безопасно</div>
                        </div>
                        <div class="bsee-scenario-option" data-scenario="overwrite_all">
                            <div class="bsee-scenario-title">Перезаписать все</div>
                            <div class="bsee-scenario-desc">Генерирует и перезаписывает описания для ВСЕХ товаров, подходящих под фильтр.</div>
                            <div class="bsee-scenario-badge warn">Перезапись</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
window.BlockseeAiseoConfig = {
    sessid: <?= \CUtil::PhpToJSObject(bitrix_sessid()) ?>,
    ajaxUrl: '/bitrix/services/main/ajax.php',
    controller: 'blocksee:aiseo.generator',
    iblockId: <?= (int)$selectedIblockId ?>,
    sectionId: <?= (int)$selectedSectionId ?>,
    scenarioFilter: <?= \CUtil::PhpToJSObject($scenarioFilter) ?>,
};
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
