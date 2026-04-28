<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Blocksee\Aiseo\Options;
use Blocksee\Aiseo\ApiClient;

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Access denied');
}

Loader::includeModule('blocksee.aiseo');
Loader::includeModule('iblock');

$MODULE_ID = 'blocksee.aiseo';
$APPLICATION->SetTitle('Настройки: БЛОКСИ: ИИ SEO');

// Handle save
$saved = false;
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if (isset($_POST['save'])) {
        // api_endpoint больше не редактируется через UI; оставляем что было.
        Options::set('target_field', (string)($_POST['target_field'] ?? 'DETAIL_TEXT'));
        Options::set('target_property_code', trim((string)($_POST['target_property_code'] ?? '')));
        Options::set('iblock_id', (int)($_POST['iblock_id'] ?? 0));
        Options::set('custom_prompt', (string)($_POST['custom_prompt'] ?? ''));
        Options::set('temperature', (string)($_POST['temperature'] ?? '0.7'));
        Options::set('max_tokens', (string)($_POST['max_tokens'] ?? '3000'));
        Options::set('creative_mode', isset($_POST['creative_mode']) ? 'Y' : 'N');
        // quality_tier больше не настраивается через UI — всегда «high» (журналистский
        // промпт + DS Pro). Опция в БД остаётся для совместимости, но из формы не читается.

        // Reviews section
        $newSource = (string)($_POST['reviews_source'] ?? Options::REVIEWS_SOURCE_AUTO);
        if (!in_array($newSource, [Options::REVIEWS_SOURCE_AUTO, Options::REVIEWS_SOURCE_FORUM, Options::REVIEWS_SOURCE_BLOG], true)) {
            $newSource = Options::REVIEWS_SOURCE_AUTO;
        }
        Options::set('reviews_source', $newSource);
        Options::set('reviews_blog_url', trim((string)($_POST['reviews_blog_url'] ?? 'catalog_comments')) ?: 'catalog_comments');
        Options::set('reviews_forum_id', (int)($_POST['reviews_forum_id'] ?? 0));
        Options::set('reviews_per_product', max(1, min(50, (int)($_POST['reviews_per_product'] ?? 3))));
        Options::set('reviews_min_words', max(10, (int)($_POST['reviews_min_words'] ?? 20)));
        Options::set('reviews_max_words', max(10, (int)($_POST['reviews_max_words'] ?? 60)));
        Options::set('reviews_default_rating', max(1, min(5, (int)($_POST['reviews_default_rating'] ?? 5))));
        Options::set('reviews_custom_prompt', (string)($_POST['reviews_custom_prompt'] ?? ''));
        Options::set('reviews_auto_approve', isset($_POST['reviews_auto_approve']) ? 'Y' : 'N');
        Options::set('reviews_date_range_enabled', isset($_POST['reviews_date_range_enabled']) ? 'Y' : 'N');
        Options::set('reviews_date_from', (string)($_POST['reviews_date_from'] ?? date('Y-m-d', strtotime('-2 years'))));
        Options::set('reviews_date_to', (string)($_POST['reviews_date_to'] ?? date('Y-m-d')));
        $saved = true;
    }
    if (isset($_POST['test_api'])) {
        $client = new ApiClient();
        $testResult = $client->ping();
    }
}

$endpoint = Options::getApiEndpoint();
$targetField = Options::getTargetField();
$targetPropertyCode = Options::getTargetPropertyCode();
$iblockId = Options::getIblockId();
$customPrompt = Options::getCustomPrompt();
$settings = Options::getGenerationSettings();

// Reviews state
$reviewsSource = Options::getReviewsSource();
$reviewsResolved = Options::resolveReviewsSource();
$reviewsBlogUrl = Options::getReviewsBlogUrl();
$reviewsBlogId = Options::getReviewsBlogId();
$blogModuleAvailable = \Bitrix\Main\ModuleManager::isModuleInstalled('blog');
$forumModuleAvailable = \Bitrix\Main\ModuleManager::isModuleInstalled('forum');
$reviewsForumId = Options::getReviewsForumId();
$reviewsPerProduct = Options::getReviewsPerProduct();
$reviewsSettings = Options::getReviewsSettings();
$reviewsAutoApprove = Options::getReviewsAutoApprove();
$reviewsDateRangeEnabled = Options::getReviewsDateRangeEnabled();
$reviewsDateFrom = Options::getReviewsDateFrom();
$reviewsDateTo = Options::getReviewsDateTo();

// Catalog iblocks dropdown
$catalogIblocks = Options::getCatalogIblocks();

// Forums dropdown (if forum module present)
$forums = [];
if (Loader::includeModule('forum')) {
    $rsF = \CForumNew::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
    while ($f = $rsF->Fetch()) {
        $forums[(int)$f['ID']] = $f['NAME'] . ' [' . $f['ID'] . ']';
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
$APPLICATION->SetAdditionalCSS('/local/modules/blocksee.aiseo/assets/admin.css');
?>
<?php if ($saved): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message">Настройки сохранены.</div>
    </div>
<?php endif; ?>

<?php if ($testResult !== null): ?>
    <div class="adm-info-message-wrap <?= !empty($testResult['success']) ? 'adm-info-message-green' : 'adm-info-message-red' ?>">
        <div class="adm-info-message">
            <?php if (!empty($testResult['success'])): ?>
                API доступен. HTTP статус: <?= (int)$testResult['status'] ?>
            <?php else: ?>
                Ошибка соединения: <?= htmlspecialcharsbx($testResult['error'] ?? '') ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="" class="blocksee-aiseo-options-form">
    <?= bitrix_sessid_post() ?>

    <div class="adm-detail-content-wrap">
        <div class="adm-detail-content">
            <fieldset>
                <legend>API</legend>
                <p>
                    <small>Соединение с AI-сервисом настраивается автоматически. Проверьте, что домен сайта добавлен в белый список у вендора.</small>
                </p>
                <p>
                    <button type="submit" name="test_api" value="1" class="adm-btn">Проверить соединение</button>
                </p>
            </fieldset>

            <fieldset>
                <legend>Сохранение результата</legend>
                <p>
                    <label>Инфоблок каталога по умолчанию:<br>
                        <select name="iblock_id">
                            <option value="0">(выбирать на странице списка)</option>
                            <?php foreach ($catalogIblocks as $id => $name): ?>
                                <option value="<?= $id ?>" <?= $id === $iblockId ? 'selected' : '' ?>><?= htmlspecialcharsbx($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label>Куда записывать описание:<br>
                        <select name="target_field">
                            <option value="DETAIL_TEXT" <?= $targetField === 'DETAIL_TEXT' ? 'selected' : '' ?>>Подробное описание (DETAIL_TEXT)</option>
                            <option value="PREVIEW_TEXT" <?= $targetField === 'PREVIEW_TEXT' ? 'selected' : '' ?>>Краткое описание (PREVIEW_TEXT)</option>
                            <option value="BOTH" <?= $targetField === 'BOTH' ? 'selected' : '' ?>>Оба (+ первый абзац как PREVIEW_TEXT)</option>
                            <option value="PROPERTY" <?= $targetField === 'PROPERTY' ? 'selected' : '' ?>>Пользовательское свойство</option>
                        </select>
                    </label>
                </p>
                <p>
                    <label>Код свойства (если выбрано "Пользовательское свойство"):<br>
                        <input type="text" name="target_property_code" value="<?= htmlspecialcharsbx($targetPropertyCode) ?>" size="30">
                    </label>
                </p>
            </fieldset>

            <fieldset>
                <legend>Отзывы к товарам</legend>
                <p>
                    <label>Источник хранения отзывов:<br>
                        <select name="reviews_source">
                            <option value="<?= Options::REVIEWS_SOURCE_AUTO ?>" <?= $reviewsSource === Options::REVIEWS_SOURCE_AUTO ? 'selected' : '' ?>>
                                Автоматически (рекомендуется)<?php if ($reviewsResolved): ?> — сейчас: <?= htmlspecialcharsbx($reviewsResolved) ?><?php endif; ?>
                            </option>
                            <option value="<?= Options::REVIEWS_SOURCE_BLOG ?>" <?= $reviewsSource === Options::REVIEWS_SOURCE_BLOG ? 'selected' : '' ?> <?= !$blogModuleAvailable ? 'disabled' : '' ?>>
                                Blog — комментарии (Aspro Premier и совместимые)<?= !$blogModuleAvailable ? ' — модуль blog не установлен' : '' ?>
                            </option>
                            <option value="<?= Options::REVIEWS_SOURCE_FORUM ?>" <?= $reviewsSource === Options::REVIEWS_SOURCE_FORUM ? 'selected' : '' ?> <?= !$forumModuleAvailable ? 'disabled' : '' ?>>
                                Forum — топики (стандарт Битрикса)<?= !$forumModuleAvailable ? ' — модуль forum не установлен' : '' ?>
                            </option>
                        </select>
                    </label>
                    <br><small>
                        Auto: предпочитаем blog (как у Aspro), форум — как fallback.
                        Если на сайте используется компонент <code>bitrix:catalog.comments</code> с <code>BLOG_USE='Y'</code> — выбирайте blog.
                    </small>
                </p>
                <p>
                    <label>URL блога-контейнера (для режима blog):<br>
                        <input type="text" name="reviews_blog_url" value="<?= htmlspecialcharsbx($reviewsBlogUrl) ?>" size="40" placeholder="catalog_comments">
                    </label>
                    <br><small>
                        Должно совпадать с параметром BLOG_URL компонента <code>catalog.comments</code>.
                        В Aspro Premier по умолчанию — <code>catalog_comments</code>.
                        <?php if ($reviewsBlogId > 0): ?>Текущий ID блога: <b><?= $reviewsBlogId ?></b>.<?php endif; ?>
                    </small>
                </p>
                <p>
                    <label>Форум для отзывов (для режима forum):<br>
                        <select name="reviews_forum_id" <?= !$forumModuleAvailable ? 'disabled' : '' ?>>
                            <option value="0">(не выбран)</option>
                            <?php foreach ($forums as $fid => $fname): ?>
                                <option value="<?= $fid ?>" <?= $fid === $reviewsForumId ? 'selected' : '' ?>><?= htmlspecialcharsbx($fname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <br><small>Модуль создаёт форум «Отзывы товаров (AI)» при установке (если установлен модуль forum).</small>
                </p>
                <p>
                    <label>Количество отзывов на товар по умолчанию:<br>
                        <input type="number" min="1" max="50" name="reviews_per_product" value="<?= (int)$reviewsPerProduct ?>">
                    </label>
                </p>
                <p>
                    <label>Минимум слов в отзыве:
                        <input type="number" min="10" max="100" name="reviews_min_words" value="<?= (int)$reviewsSettings['min_words'] ?>">
                    </label>
                    &nbsp;
                    <label>Максимум слов:
                        <input type="number" min="10" max="200" name="reviews_max_words" value="<?= (int)$reviewsSettings['max_words'] ?>">
                    </label>
                </p>
                <p>
                    <label>Средний рейтинг (1-5):<br>
                        <input type="number" min="1" max="5" name="reviews_default_rating" value="<?= (int)$reviewsSettings['rating'] ?>">
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="reviews_auto_approve" value="Y" <?= $reviewsAutoApprove ? 'checked' : '' ?>>
                        Автоматически одобрять сгенерированные отзывы
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="reviews_date_range_enabled" value="Y" <?= $reviewsDateRangeEnabled ? 'checked' : '' ?>>
                        Случайные даты публикации в диапазоне
                    </label>
                </p>
                <p>
                    <label>С:
                        <input type="date" name="reviews_date_from" value="<?= htmlspecialcharsbx($reviewsDateFrom) ?>">
                    </label>
                    &nbsp;
                    <label>по:
                        <input type="date" name="reviews_date_to" value="<?= htmlspecialcharsbx($reviewsDateTo) ?>">
                    </label>
                </p>
                <p>
                    <label>Дополнительный промпт для отзывов:<br>
                        <textarea name="reviews_custom_prompt" rows="5" cols="80"><?= htmlspecialcharsbx((string)$reviewsSettings['custom_prompt']) ?></textarea>
                    </label>
                    <br><small>Подсказка модели: стиль, детали, какие слова использовать или избегать.</small>
                </p>
            </fieldset>

            <fieldset>
                <legend>Параметры генерации</legend>
                <p>
                    <label>Temperature (0.0–2.0):<br>
                        <input type="number" step="0.1" min="0" max="2" name="temperature" value="<?= htmlspecialcharsbx((string)$settings['temperature']) ?>">
                    </label>
                </p>
                <p>
                    <label>Max tokens:<br>
                        <input type="number" min="100" max="8192" name="max_tokens" value="<?= htmlspecialcharsbx((string)$settings['max_tokens']) ?>">
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="creative_mode" value="Y" <?= $settings['creative_mode'] ? 'checked' : '' ?>>
                        Креативный режим
                    </label>
                </p>
                <p>
                    <label>Кастомный промпт (пусто = стандартный на сервере):<br>
                        <textarea name="custom_prompt" rows="8" cols="80"><?= htmlspecialcharsbx($customPrompt) ?></textarea>
                    </label>
                </p>
            </fieldset>
        </div>

        <div class="adm-detail-content-btns-wrap">
            <div class="adm-detail-content-btns">
                <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
            </div>
        </div>
    </div>
</form>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
