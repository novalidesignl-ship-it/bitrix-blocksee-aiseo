<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Blocksee\Aiseo\Options;
use Blocksee\Aiseo\ReviewsGenerator;

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

if (!Loader::includeModule('blocksee.aiseo')) {
    ShowError('Модуль blocksee.aiseo не подключён');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

$reviewsBackend = \Blocksee\Aiseo\Reviews\Factory::create();
if ($reviewsBackend === null) {
    ShowError('Источник отзывов не настроен. Установите модуль blog (для Aspro/каталог-комментариев) или forum, либо задайте источник в настройках модуля.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

Loc::loadMessages(__FILE__);
Loader::includeModule('iblock');

$APPLICATION->SetTitle('БЛОКСИ: ИИ SEO — Отзывы товаров');

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

$rs = \CIBlockElement::GetList(
    ['NAME' => 'ASC'],
    $filter,
    false,
    ['iNumPage' => $page, 'nPageSize' => $pageSize],
    ['ID', 'NAME', 'IBLOCK_ID', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
);
$items = [];
while ($row = $rs->Fetch()) {
    $items[] = $row;
}
$totalCount = (int)$rs->SelectedRowsCount();
$pageCount = max(1, (int)ceil($totalCount / $pageSize));

// Review counts per product on current page — через backend (forum или blog).
$reviewCounts = [];
if (!empty($items)) {
    $ids = array_map('intval', array_column($items, 'ID'));
    $reviewCounts = $reviewsBackend->countsForElements($ids, $selectedIblockId);
}
if ($scenarioFilter === 'without_reviews') {
    $items = array_values(array_filter($items, fn($r) => ($reviewCounts[(int)$r['ID']] ?? 0) === 0));
} elseif ($scenarioFilter === 'with_reviews') {
    $items = array_values(array_filter($items, fn($r) => ($reviewCounts[(int)$r['ID']] ?? 0) > 0));
}

$cssMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/local/modules/blocksee.aiseo/assets/admin.css') ?: time();
$jsMtime = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/local/modules/blocksee.aiseo/assets/reviews.js') ?: time();
$APPLICATION->SetAdditionalCSS('/local/modules/blocksee.aiseo/assets/admin.css?v=' . $cssMtime);
$APPLICATION->AddHeadScript('/local/modules/blocksee.aiseo/assets/reviews.js?v=' . $jsMtime);

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

$reviewsPerProduct = Options::getReviewsPerProduct();
$reviewsSettings = Options::getReviewsSettings();
$customPrompt = $reviewsSettings['custom_prompt'];

function bsee_img_src_rev(int $fileId, int $w = 64, int $h = 64): string
{
    if ($fileId <= 0) return '';
    $resized = \CFile::ResizeImageGet($fileId, ['width' => $w, 'height' => $h], BX_RESIZE_IMAGE_PROPORTIONAL, true);
    return $resized['src'] ?? '';
}

function bsee_stars(int $rating): string
{
    $rating = max(0, min(5, $rating));
    $full = str_repeat('★', $rating);
    $empty = str_repeat('☆', 5 - $rating);
    return '<span class="bsee-stars">' . $full . '<span class="bsee-stars-empty">' . $empty . '</span></span>';
}
?>
<div class="bsee-app">

    <div class="bsee-header-bar">
        <div class="bsee-header-info">
            <div class="bsee-header-target">
                <span class="bsee-label">Источник отзывов:</span>
                <?php
                    $src = Options::resolveReviewsSource();
                    if ($src === Options::REVIEWS_SOURCE_BLOG) {
                        $blogId = Options::getReviewsBlogId();
                        echo '<b>Блог «' . htmlspecialcharsbx(Options::getReviewsBlogUrl()) . '»'
                           . ($blogId > 0 ? ' · ID ' . $blogId : '') . '</b>';
                    } elseif ($src === Options::REVIEWS_SOURCE_FORUM) {
                        $forumId = Options::getReviewsForumId();
                        echo '<b>Форум' . ($forumId > 0 ? ' ID ' . $forumId : ' (не задан)') . '</b>';
                    } else {
                        echo '<b style="color:#dc2626;">не настроен</b>';
                    }
                ?>
            </div>
            <a href="blocksee_aiseo_reviews_urls.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Генерация по ссылкам →</a>
            <a href="blocksee_aiseo_options.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">Настройки модуля</a>
            <a href="blocksee_aiseo_list.php?lang=<?= LANGUAGE_ID ?>" class="bsee-btn bsee-btn-ghost">К описаниям</a>
        </div>
    </div>

    <div class="bsee-prompt-card">
        <div class="bsee-prompt-head">
            <h4>Дополнительный промпт для отзывов</h4>
            <small>Подсказка модели по стилю и тону отзывов. Пусто — стандартный промпт сервера.</small>
        </div>
        <textarea id="bsee-custom-prompt" placeholder="Например: разнообразные авторы 25-55 лет, рассказывают о конкретных сценариях использования, смешанные оценки от 4 до 5 звёзд..."><?= htmlspecialcharsbx($customPrompt) ?></textarea>
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
                    <option value="without_reviews" <?= $scenarioFilter === 'without_reviews' ? 'selected' : '' ?>>Без отзывов</option>
                    <option value="with_reviews" <?= $scenarioFilter === 'with_reviews' ? 'selected' : '' ?>>С отзывами</option>
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
            <span class="bsee-muted">Для генерации на одной странице используйте кнопки у товаров, для всего каталога или категории — «Массовая генерация».</span>
        </div>
        <div class="bsee-bulk-right">
            <button type="button" class="bsee-btn bsee-btn-accent" id="bsee-open-scenario">
                Массовая генерация отзывов →
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
            <col style="width:64px">
            <col style="width:320px">
            <col style="width:120px">
            <col>
            <col style="width:220px">
        </colgroup>
        <thead>
            <tr>
                <th></th>
                <th>Товар</th>
                <th>Отзывов</th>
                <th>Последние отзывы</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" class="bsee-empty">Нет товаров по заданному фильтру.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item):
                $elementId = (int)$item['ID'];
                $cnt = (int)($reviewCounts[$elementId] ?? 0);
                $iblockTypeId = $iblockTypeMap[(int)$item['IBLOCK_ID']] ?? 'catalog';
                $editUrl = "/bitrix/admin/iblock_element_edit.php?IBLOCK_ID={$item['IBLOCK_ID']}&type=" . urlencode($iblockTypeId) . "&ID={$elementId}&lang=" . LANGUAGE_ID;
                $thumb = bsee_img_src_rev((int)($item['PREVIEW_PICTURE'] ?: $item['DETAIL_PICTURE']));
            ?>
                <tr data-element-id="<?= $elementId ?>">
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
                        </div>
                    </td>
                    <td class="bsee-cell-count">
                        <span class="bsee-review-badge <?= $cnt > 0 ? 'has' : 'none' ?>" data-count="<?= $cnt ?>">
                            <?= $cnt > 0 ? $cnt : '—' ?>
                        </span>
                    </td>
                    <td class="bsee-cell-preview">
                        <div class="bsee-review-preview" data-element-id="<?= $elementId ?>">
                            <?php if ($cnt === 0): ?>
                                <span class="bsee-muted">Отзывов ещё нет</span>
                            <?php else: ?>
                                <span class="bsee-muted">Нажмите «Посмотреть», чтобы раскрыть</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="bsee-cell-actions">
                        <button type="button" class="bsee-btn bsee-btn-small bsee-btn-primary bsee-gen-review-btn" data-id="<?= $elementId ?>">+ 1 отзыв</button>
                        <button type="button" class="bsee-btn bsee-btn-small bsee-view-reviews-btn" data-id="<?= $elementId ?>">Посмотреть</button>
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
        $makeUrl = fn($p) => htmlspecialcharsbx($baseUrl . '&page=' . $p);
        $windowSize = 5;
        $start = max(1, $page - (int)floor($windowSize / 2));
        $end = min($pageCount, $start + $windowSize - 1);
        if ($end - $start + 1 < $windowSize) $start = max(1, $end - $windowSize + 1);
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
                <h3>Массовая генерация отзывов</h3>
                <button type="button" class="bsee-modal-close">&times;</button>
            </div>

            <div class="bsee-modal-body">
                <div class="bsee-scenario-block">
                    <div class="bsee-scenario-block-head">
                        <h4>1. Количество отзывов на товар</h4>
                    </div>
                    <p>
                        <input type="number" id="bsee-rev-count" min="1" max="20" value="<?= (int)$reviewsPerProduct ?>" class="bsee-rev-count-input">
                        <small class="bsee-muted">От 1 до 20. Каждому товару будет добавлено ровно столько новых отзывов.</small>
                    </p>
                </div>

                <div class="bsee-scenario-block">
                    <div class="bsee-scenario-block-head">
                        <h4>2. Выберите категории</h4>
                        <div class="bsee-scenario-block-actions">
                            <button type="button" class="bsee-btn bsee-btn-ghost bsee-btn-small" id="bsee-sections-select-all">Выбрать все</button>
                            <button type="button" class="bsee-btn bsee-btn-ghost bsee-btn-small" id="bsee-sections-clear">Очистить</button>
                        </div>
                    </div>
                    <small class="bsee-muted">Пусто = все товары. Выбор родительской категории включает все вложенные.</small>

                    <?php if (!empty($sections)): ?>
                        <div class="bsee-sections-search-wrap">
                            <input type="text" id="bsee-sections-search" class="bsee-sections-search" placeholder="Поиск по категориям...">
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
                        <h4>3. Выберите сценарий</h4>
                    </div>
                    <div class="bsee-scenario-options">
                        <div class="bsee-scenario-option" data-scenario="skip_with_reviews">
                            <div class="bsee-scenario-title">Только товары без отзывов</div>
                            <div class="bsee-scenario-desc">Пропускает товары, у которых уже есть отзывы. Безопасный режим.</div>
                            <div class="bsee-scenario-badge">Безопасно</div>
                        </div>
                        <div class="bsee-scenario-option" data-scenario="all_products">
                            <div class="bsee-scenario-title">Добавить всем</div>
                            <div class="bsee-scenario-desc">Добавляет новые отзывы ко всем товарам, подходящим под фильтр, даже если там уже есть отзывы.</div>
                            <div class="bsee-scenario-badge warn">Добавить к имеющимся</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="bsee-reviews-viewer" class="bsee-modal" style="display:none;">
        <div class="bsee-modal-box">
            <div class="bsee-modal-head">
                <h3 id="bsee-reviews-viewer-title">Отзывы товара</h3>
                <button type="button" class="bsee-modal-close">&times;</button>
            </div>
            <div class="bsee-modal-body" id="bsee-reviews-viewer-body">
                <div class="bsee-muted">Загружаем отзывы...</div>
            </div>
        </div>
    </div>

</div>

<script>
window.BlockseeAiseoReviewsConfig = {
    sessid: <?= \CUtil::PhpToJSObject(bitrix_sessid()) ?>,
    ajaxUrl: '/bitrix/services/main/ajax.php',
    controller: 'blocksee:aiseo.reviews',
    iblockId: <?= (int)$selectedIblockId ?>,
    sectionId: <?= (int)$selectedSectionId ?>,
    scenarioFilter: <?= \CUtil::PhpToJSObject($scenarioFilter) ?>,
    defaultCount: <?= (int)$reviewsPerProduct ?>
};
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
