<?php

namespace Blocksee\Aiseo\Reviews;

use Bitrix\Main\Loader;
use Blocksee\Aiseo\Options;
use Blocksee\Aiseo\TextSanitizer;

/**
 * Backend для произвольных самописных схем отзывов на Битриксе.
 * В отличие от IblockBackend (auto-detect под Aspro Max паттерн) — здесь все
 * параметры берутся из настроек модуля. Это покрывает кейсы, где:
 *   - связь товар↔отзыв идёт «обратной» E-property (на отзыве указан товар) —
 *     типичная схема для самописных тем магазина (tsar-climat, и т.п.);
 *   - коды свойств не совпадают с кандидатами IblockBackend (RATING/AUTHOR/...);
 *   - тело отзыва пишется не в DETAIL_TEXT, а, например, в PREVIEW_TEXT
 *     или в отдельное S-property.
 *
 * Конфигурация (в Options):
 *   - reviews_custom_iblock           — ID инфоблока отзывов
 *   - reviews_custom_link_direction   — 'forward'|'reverse'
 *   - reviews_custom_link_prop        — код E-свойства связи
 *   - reviews_custom_rating_prop      — код N-свойства оценки (опц.)
 *   - reviews_custom_author_prop      — код S-свойства автора (опц.; fallback NAME)
 *   - reviews_custom_content_target   — 'DETAIL_TEXT'|'PREVIEW_TEXT'|'PROPERTY:CODE'
 *   - reviews_custom_active_default   — 'Y'|'N'
 */
class CustomBackend implements Backend
{
    public function isAvailable(): bool
    {
        if (!Loader::includeModule('iblock')) return false;
        return Options::getReviewsCustomIblockId() > 0;
    }

    /**
     * Custom backend ничего не создаёт автоматически — структура задана
     * настройками. Возвращает success=true, если конфигурация валидна.
     */
    public function setupForIblock(int $iblockId): array
    {
        $cfg = $this->config();
        if (!$cfg['ok']) {
            return ['success' => false, 'error' => $cfg['error']];
        }
        return [
            'success' => true,
            'reviews_iblock' => $cfg['iblock'],
            'link_direction' => $cfg['direction'],
            'link_prop_code' => $cfg['link_prop'],
            'rating_prop_code' => $cfg['rating_prop'],
            'author_prop_code' => $cfg['author_prop'],
            'content_target' => $cfg['content_target'],
        ];
    }

    public function count(int $elementId): int
    {
        $cfg = $this->config();
        if (!$cfg['ok']) return 0;
        return count($this->getReviewIdsForProduct($elementId, $cfg));
    }

    public function countsForElements(array $elementIds, int $iblockId): array
    {
        if (!$elementIds) return [];
        $cfg = $this->config();
        if (!$cfg['ok']) return [];

        $out = [];
        foreach ($elementIds as $eid) $out[(int)$eid] = 0;

        if ($cfg['direction'] === Options::REVIEWS_CUSTOM_DIRECTION_REVERSE) {
            // Один запрос: все элементы инфоблока отзывов с PROPERTY_<link>
            // равным любому из товаров. Группируем сами.
            $rs = \CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => $cfg['iblock'],
                    'PROPERTY_' . $cfg['link_prop'] => $elementIds,
                ],
                false,
                false,
                ['ID', 'PROPERTY_' . $cfg['link_prop']]
            );
            while ($r = $rs->Fetch()) {
                $eid = (int)($r['PROPERTY_' . $cfg['link_prop'] . '_VALUE'] ?? 0);
                if ($eid > 0 && isset($out[$eid])) $out[$eid]++;
            }
        } else {
            // forward: на товаре multiple-property с ID отзывов.
            $rs = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'ID' => $elementIds],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'PROPERTY_' . $cfg['link_prop']]
            );
            while ($row = $rs->Fetch()) {
                $eid = (int)$row['ID'];
                if (!isset($out[$eid])) $out[$eid] = 0;
                if (!empty($row['PROPERTY_' . $cfg['link_prop'] . '_VALUE'])) {
                    $out[$eid]++;
                }
            }
        }
        return $out;
    }

    public function listForElement(int $elementId, int $limit = 50): array
    {
        $cfg = $this->config();
        if (!$cfg['ok']) return [];

        $reviewIds = $this->getReviewIdsForProduct($elementId, $cfg);
        if (!$reviewIds) return [];
        $reviewIds = array_slice($reviewIds, 0, max(1, $limit));

        $select = ['ID', 'NAME', 'DETAIL_TEXT', 'PREVIEW_TEXT', 'DATE_CREATE'];
        if ($cfg['rating_prop'] !== '') $select[] = 'PROPERTY_' . $cfg['rating_prop'];
        if ($cfg['author_prop'] !== '') $select[] = 'PROPERTY_' . $cfg['author_prop'];

        // Если контент лежит в S-property — добираем его тоже
        $contentPropCode = '';
        if (strncmp($cfg['content_target'], Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX, strlen(Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX)) === 0) {
            $contentPropCode = substr($cfg['content_target'], strlen(Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX));
            if ($contentPropCode !== '') $select[] = 'PROPERTY_' . $contentPropCode;
        }

        $rs = \CIBlockElement::GetList(
            ['DATE_CREATE' => 'DESC'],
            ['IBLOCK_ID' => $cfg['iblock'], 'ID' => $reviewIds],
            false,
            false,
            $select
        );
        $out = [];
        while ($r = $rs->Fetch()) {
            $author = '';
            if ($cfg['author_prop'] !== '' && !empty($r['PROPERTY_' . $cfg['author_prop'] . '_VALUE'])) {
                $author = (string)$r['PROPERTY_' . $cfg['author_prop'] . '_VALUE'];
            } else {
                $author = (string)$r['NAME'];
            }
            // Контент достаём из выбранного поля
            if ($contentPropCode !== '') {
                $content = (string)($r['PROPERTY_' . $contentPropCode . '_VALUE'] ?? '');
            } elseif ($cfg['content_target'] === Options::REVIEWS_CUSTOM_TARGET_PREVIEW) {
                $content = (string)$r['PREVIEW_TEXT'];
            } else {
                $content = (string)$r['DETAIL_TEXT'];
            }
            $rating = 0;
            if ($cfg['rating_prop'] !== '' && isset($r['PROPERTY_' . $cfg['rating_prop'] . '_VALUE'])) {
                $rating = (int)$r['PROPERTY_' . $cfg['rating_prop'] . '_VALUE'];
            }
            $out[] = [
                'id' => (int)$r['ID'],
                'author' => $author,
                'content' => $content,
                'rating' => $rating,
                'date' => (string)$r['DATE_CREATE'],
                'approved' => true,
                'ai' => true,
            ];
        }
        return $out;
    }

    public function saveForElement(int $elementId, array $reviews, array $opts): array
    {
        $cfg = $this->config();
        if (!$cfg['ok']) {
            return ['success' => false, 'error' => $cfg['error']];
        }

        // auto_approve из вызова перебивает дефолт настроек только если явно
        // передан. Если опция не пришла — берём из настроек модуля.
        $autoApprove = isset($opts['auto_approve'])
            ? !empty($opts['auto_approve'])
            : ($cfg['active_default'] === 'Y');

        // Дата публикации: если в настройках включён random-range — каждому
        // отзыву случайный таймстамп в окне (как у ForumBackend); иначе now().
        // ACTIVE_FROM почти всегда required в кастомных инфоблоках отзывов
        // (в b_iblock_fields IS_REQUIRED='Y'), без него Add() отклоняется.
        $dateFrom = (int)($opts['date_from'] ?? 0);
        $dateTo = (int)($opts['date_to'] ?? 0);
        $useDateRange = $dateFrom > 0 && $dateTo > 0;
        if ($useDateRange && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $contentPropCode = '';
        $contentTarget = $cfg['content_target'];
        if (strncmp($contentTarget, Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX, strlen(Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX)) === 0) {
            $contentPropCode = substr($contentTarget, strlen(Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX));
        }

        // Required-свойства инфоблока отзывов: те, у которых IS_REQUIRED='Y'.
        // Битрикс их валидирует и без них Add() возвращает false без явной ошибки.
        // Соберём один раз, потом авто-заполним заглушками то, что AI не отдал.
        $requiredProps = $this->fetchRequiredProps($cfg['iblock']);

        // Авто-определение кодов свойств для plusses/minuses в схеме инфоблока.
        // Когда включён split-режим, AI возвращает поля 'plusses' / 'minuses' в
        // каждом отзыве, и мы пишем их в свойства с подходящим кодом.
        $semProps = $this->findSemanticProsConsProps($cfg['iblock']);

        $createdIds = [];
        $errors = [];
        $cie = new \CIBlockElement();
        foreach ($reviews as $r) {
            $author = TextSanitizer::stripEmoji(trim((string)($r['author_name'] ?? '')));
            $content = TextSanitizer::stripEmoji(trim((string)($r['content'] ?? '')));
            // Оценка может быть float (4.5-5.0 диапазон). Округляем до 0.1.
            $rating = round(max(1.0, min(5.0, (float)($r['rating'] ?? 5))), 1);
            if ($author === '' || $content === '') continue;

            $ts = $useDateRange ? random_int($dateFrom, $dateTo) : time();
            $fields = [
                'IBLOCK_ID' => $cfg['iblock'],
                'NAME' => $author,
                'ACTIVE' => $autoApprove ? 'Y' : 'N',
                'ACTIVE_FROM' => \ConvertTimeStamp($ts, 'FULL'),
            ];

            // Контент — в выбранное место
            if ($contentPropCode !== '') {
                // Тело идёт в строковое свойство; PREVIEW_TEXT/DETAIL_TEXT не задаём
                $fields['PREVIEW_TEXT'] = '';
            } elseif ($contentTarget === Options::REVIEWS_CUSTOM_TARGET_PREVIEW) {
                $fields['PREVIEW_TEXT'] = $content;
                $fields['PREVIEW_TEXT_TYPE'] = 'text';
            } else {
                $fields['DETAIL_TEXT'] = $content;
                $fields['DETAIL_TEXT_TYPE'] = 'text';
            }

            // Properties: сначала из настроек, потом split-режим, потом заглушки
            $propValues = [];
            if ($cfg['rating_prop'] !== '') $propValues[$cfg['rating_prop']] = $rating;
            if ($cfg['author_prop'] !== '') $propValues[$cfg['author_prop']] = $author;
            if ($contentPropCode !== '')   $propValues[$contentPropCode] = $content;
            if ($cfg['direction'] === Options::REVIEWS_CUSTOM_DIRECTION_REVERSE) {
                // на отзыве свойство-связь хранит ID товара
                $propValues[$cfg['link_prop']] = $elementId;
            }

            // Split-режим: AI вернул plusses/minuses отдельно — пишем их в
            // соответствующие свойства инфоблока. Это перебивает заглушки «—»
            // от fillRequiredStubs.
            $plusses = isset($r['plusses']) ? TextSanitizer::stripEmoji(trim((string)$r['plusses'])) : '';
            $minuses = isset($r['minuses']) ? TextSanitizer::stripEmoji(trim((string)$r['minuses'])) : '';
            if ($plusses !== '' && $semProps['pros'] !== '') {
                $propValues[$semProps['pros']] = $plusses;
            }
            if ($minuses !== '' && $semProps['cons'] !== '') {
                $propValues[$semProps['cons']] = $minuses;
            }

            // Авто-заглушки для required-свойств, которые мы ещё не заполнили
            $this->fillRequiredStubs($propValues, $requiredProps, $author, $content, $rating);

            if ($propValues) {
                $fields['PROPERTY_VALUES'] = $propValues;
            }

            $newId = $cie->Add($fields);
            if ($newId) {
                $createdIds[] = (int)$newId;
            } elseif (!empty($cie->LAST_ERROR) && count($errors) < 3) {
                // Логируем первые 3 уникальные ошибки — для диагностики, чтобы юзер
                // не бился вслепую с «Ни один не сохранён».
                $errors[] = trim((string)$cie->LAST_ERROR);
            }
        }

        if (!$createdIds) {
            $errMsg = 'Ни один отзыв не сохранён';
            if ($errors) {
                $errMsg .= ': ' . implode(' | ', array_unique($errors));
            } else {
                $errMsg .= ' (возможно, пустые тексты)';
            }
            return ['success' => false, 'error' => $errMsg];
        }

        // Forward direction: дополняем multiple-свойство товара.
        if ($cfg['direction'] === Options::REVIEWS_CUSTOM_DIRECTION_FORWARD) {
            $iblockId = $this->resolveIblockId($elementId);
            if ($iblockId > 0) {
                $existing = $this->getForwardLinkedReviewIds($elementId, $iblockId, $cfg['link_prop']);
                \CIBlockElement::SetPropertyValuesEx(
                    $elementId,
                    $iblockId,
                    [$cfg['link_prop'] => array_merge($existing, $createdIds)]
                );
            }
        }

        return [
            'success' => true,
            'saved' => count($createdIds),
            'container_id' => $cfg['iblock'],
        ];
    }

    public function deleteOne(int $messageId): bool
    {
        if (!Loader::includeModule('iblock')) return false;
        // Note: forward-режим не чистит multiple-свойство товара (как у IblockBackend).
        // GetList по ID не вернёт удалённый — шаблон сайта не отрисует. ОК.
        return (bool)\CIBlockElement::Delete($messageId);
    }

    public function updateOne(int $messageId, string $author, string $content, int $rating): array
    {
        $cfg = $this->config();
        if (!$cfg['ok']) {
            return ['success' => false, 'error' => $cfg['error']];
        }
        $row = \CIBlockElement::GetByID($messageId)->Fetch();
        if (!$row) return ['success' => false, 'error' => 'Отзыв не найден'];
        $iblockId = (int)$row['IBLOCK_ID'];
        if ($iblockId !== $cfg['iblock']) {
            return ['success' => false, 'error' => 'Отзыв принадлежит другому инфоблоку'];
        }

        $author = TextSanitizer::stripEmoji(trim($author));
        $content = TextSanitizer::stripEmoji(trim($content));
        $rating = max(1, min(5, $rating));

        $contentPropCode = '';
        $contentTarget = $cfg['content_target'];
        if (strncmp($contentTarget, Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX, strlen(Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX)) === 0) {
            $contentPropCode = substr($contentTarget, strlen(Options::REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX));
        }

        $fields = ['NAME' => $author];
        if ($contentPropCode !== '') {
            // тело лежит в свойстве — в PREVIEW/DETAIL не пишем
        } elseif ($contentTarget === Options::REVIEWS_CUSTOM_TARGET_PREVIEW) {
            $fields['PREVIEW_TEXT'] = $content;
            $fields['PREVIEW_TEXT_TYPE'] = 'text';
        } else {
            $fields['DETAIL_TEXT'] = $content;
            $fields['DETAIL_TEXT_TYPE'] = 'text';
        }

        $cie = new \CIBlockElement();
        if (!$cie->Update($messageId, $fields)) {
            return ['success' => false, 'error' => $cie->LAST_ERROR ?: 'Ошибка обновления'];
        }

        $propValues = [];
        if ($cfg['rating_prop'] !== '') $propValues[$cfg['rating_prop']] = $rating;
        if ($cfg['author_prop'] !== '') $propValues[$cfg['author_prop']] = $author;
        if ($contentPropCode !== '')    $propValues[$contentPropCode] = $content;
        if ($propValues) {
            \CIBlockElement::SetPropertyValuesEx($messageId, $iblockId, $propValues);
        }
        return ['success' => true];
    }

    public function findElementsWithReviews(int $iblockId): array
    {
        $cfg = $this->config();
        if (!$cfg['ok']) return [];

        $out = [];
        if ($cfg['direction'] === Options::REVIEWS_CUSTOM_DIRECTION_REVERSE) {
            // Группируем элементы инфоблока отзывов по PROPERTY_<link> (ID товара).
            $rs = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $cfg['iblock']],
                false,
                false,
                ['ID', 'PROPERTY_' . $cfg['link_prop']]
            );
            while ($r = $rs->Fetch()) {
                $eid = (int)($r['PROPERTY_' . $cfg['link_prop'] . '_VALUE'] ?? 0);
                if ($eid <= 0) continue;
                if (!isset($out[$eid])) $out[$eid] = 0;
                $out[$eid]++;
            }
        } else {
            $rs = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, '!PROPERTY_' . $cfg['link_prop'] => false],
                false,
                false,
                ['ID', 'PROPERTY_' . $cfg['link_prop']]
            );
            while ($row = $rs->Fetch()) {
                $eid = (int)$row['ID'];
                if (!isset($out[$eid])) $out[$eid] = 0;
                if (!empty($row['PROPERTY_' . $cfg['link_prop'] . '_VALUE'])) {
                    $out[$eid]++;
                }
            }
        }
        return $out;
    }

    /**
     * Конфигурация custom-backend'а с валидацией.
     *
     * @return array{ok:true, iblock:int, direction:string, link_prop:string, rating_prop:string, author_prop:string, content_target:string, active_default:string}|array{ok:false, error:string}
     */
    private function config(): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['ok' => false, 'error' => 'iblock module unavailable'];
        }
        $iblock = Options::getReviewsCustomIblockId();
        if ($iblock <= 0) {
            return ['ok' => false, 'error' => 'Не задан инфоблок отзывов в настройках модуля'];
        }
        $linkProp = Options::getReviewsCustomLinkProp();
        if ($linkProp === '') {
            return ['ok' => false, 'error' => 'Не задан код свойства связи в настройках модуля'];
        }
        return [
            'ok' => true,
            'iblock' => $iblock,
            'direction' => Options::getReviewsCustomLinkDirection(),
            'link_prop' => $linkProp,
            'rating_prop' => Options::getReviewsCustomRatingProp(),
            'author_prop' => Options::getReviewsCustomAuthorProp(),
            'content_target' => Options::getReviewsCustomContentTarget(),
            'active_default' => Options::getReviewsCustomActiveDefault(),
        ];
    }

    /**
     * @return int[] — ID отзывов, привязанных к товару $elementId.
     */
    private function getReviewIdsForProduct(int $elementId, array $cfg): array
    {
        if ($cfg['direction'] === Options::REVIEWS_CUSTOM_DIRECTION_REVERSE) {
            $rs = \CIBlockElement::GetList(
                ['DATE_CREATE' => 'DESC'],
                [
                    'IBLOCK_ID' => $cfg['iblock'],
                    'PROPERTY_' . $cfg['link_prop'] => $elementId,
                ],
                false,
                false,
                ['ID']
            );
            $out = [];
            while ($r = $rs->Fetch()) $out[] = (int)$r['ID'];
            return $out;
        }
        // forward
        $iblockId = $this->resolveIblockId($elementId);
        if (!$iblockId) return [];
        return $this->getForwardLinkedReviewIds($elementId, $iblockId, $cfg['link_prop']);
    }

    /**
     * Forward-режим: вытаскиваем ID отзывов из multiple E-property товара.
     *
     * @return int[]
     */
    private function getForwardLinkedReviewIds(int $elementId, int $iblockId, string $linkCode): array
    {
        $rs = \CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => $linkCode, 'ACTIVE' => 'Y']);
        $out = [];
        while ($p = $rs->Fetch()) {
            $v = (int)($p['VALUE'] ?? 0);
            if ($v > 0) $out[] = $v;
        }
        return $out;
    }

    private function resolveIblockId(int $elementId): int
    {
        static $cache = [];
        if (isset($cache[$elementId])) return $cache[$elementId];
        $row = \CIBlockElement::GetByID($elementId)->Fetch();
        return $cache[$elementId] = $row ? (int)$row['IBLOCK_ID'] : 0;
    }

    /**
     * Находит коды свойств для «достоинств» и «недостатков» в инфоблоке отзывов
     * по списку часто встречающихся имён. Возвращает ['pros' => CODE, 'cons' => CODE]
     * (пустые строки если кандидат не найден).
     *
     * Используется в split-режиме (когда AI возвращает plusses/minuses отдельно).
     * Кандидаты — те же что в fillRequiredStubs(), но проверяются по ВСЕМ свойствам
     * инфоблока, не только required: пользователь может иметь PLUSSES/MINUSES без
     * IS_REQUIRED, и нам всё равно нужно их заполнить.
     *
     * @return array{pros:string, cons:string}
     */
    private function findSemanticProsConsProps(int $iblockId): array
    {
        static $cache = [];
        if (isset($cache[$iblockId])) return $cache[$iblockId];
        $out = ['pros' => '', 'cons' => ''];
        if (!Loader::includeModule('iblock')) {
            return $cache[$iblockId] = $out;
        }
        $rs = \Bitrix\Iblock\PropertyTable::getList([
            'select' => ['CODE', 'PROPERTY_TYPE'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y'],
        ]);
        $allCodes = [];
        while ($p = $rs->fetch()) {
            // Пишем plusses/minuses только в S-свойства; для других типов писать
            // строку нельзя (N-проперти текст не примет, E — это связь с элементом).
            if ((string)$p['PROPERTY_TYPE'] === 'S') {
                $allCodes[] = strtoupper((string)$p['CODE']);
            }
        }
        $prosCandidates = ['PLUSSES', 'PLUSES', 'PROS', 'PLUS', 'ADVANTAGES', 'GOOD'];
        $consCandidates = ['MINUSES', 'CONS', 'MINUS', 'DISADVANTAGES', 'BAD'];
        foreach ($prosCandidates as $cand) {
            if (in_array($cand, $allCodes, true)) { $out['pros'] = $cand; break; }
        }
        foreach ($consCandidates as $cand) {
            if (in_array($cand, $allCodes, true)) { $out['cons'] = $cand; break; }
        }
        return $cache[$iblockId] = $out;
    }

    /**
     * Кеширует и возвращает required-свойства инфоблока отзывов.
     * Без этих свойств CIBlockElement::Add() возвращает false с LAST_ERROR
     * вида «Заполните обязательные поля».
     *
     * @return array<string,string> [CODE => PROPERTY_TYPE]
     */
    private function fetchRequiredProps(int $iblockId): array
    {
        static $cache = [];
        if (isset($cache[$iblockId])) return $cache[$iblockId];
        $out = [];
        if (!Loader::includeModule('iblock')) {
            return $cache[$iblockId] = $out;
        }
        $rs = \Bitrix\Iblock\PropertyTable::getList([
            'select' => ['CODE', 'PROPERTY_TYPE'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y', '=IS_REQUIRED' => 'Y'],
        ]);
        while ($p = $rs->fetch()) {
            $code = (string)$p['CODE'];
            if ($code !== '') {
                $out[$code] = (string)$p['PROPERTY_TYPE'];
            }
        }
        return $cache[$iblockId] = $out;
    }

    /**
     * Авто-заполнение обязательных свойств заглушками. Семантические шаблоны
     * по коду свойства (AUTHOR/PLUSSES/MINUSES/LONG/...) — иначе фиксированный «—».
     * E-свойства не трогаем (их нельзя заполнить произвольно).
     *
     * @param array<string,mixed> $propValues  ссылка — модифицируется на месте
     * @param array<string,string> $requiredProps [CODE => TYPE]
     */
    private function fillRequiredStubs(array &$propValues, array $requiredProps, string $author, string $content, int $rating): void
    {
        foreach ($requiredProps as $code => $type) {
            if (isset($propValues[$code])) continue; // уже заполнено
            if ($type === 'E' || $type === 'F') continue; // E (привязка) / F (файл) — не наши, пропускаем
            $upper = strtoupper($code);
            if ($type === 'N') {
                // Числовое: для RATING-кандидатов кладём оценку, иначе 0
                if (in_array($upper, ['RATING', 'STARS', 'RATING_VALUE', 'RATE', 'SCORE'], true)) {
                    $propValues[$code] = $rating;
                } else {
                    $propValues[$code] = 0;
                }
                continue;
            }
            // S (строка) или подобное — семантическая заглушка по коду
            if (in_array($upper, ['AUTHOR', 'AUTHOR_NAME', 'FIO', 'TITLE', 'NAME', 'USER', 'USER_NAME'], true)) {
                $propValues[$code] = $author;
            } elseif (in_array($upper, ['LONG', 'EXPERIENCE', 'PERIOD', 'USAGE_TIME', 'USE_TIME', 'LONG_TIME'], true)) {
                $propValues[$code] = 'Несколько месяцев';
            } elseif (in_array($upper, ['PLUSSES', 'PLUSES', 'PROS', 'PLUS', 'ADVANTAGES', 'GOOD'], true)) {
                $propValues[$code] = '—';
            } elseif (in_array($upper, ['MINUSES', 'CONS', 'MINUS', 'DISADVANTAGES', 'BAD'], true)) {
                $propValues[$code] = '—';
            } elseif (in_array($upper, ['COMMENT', 'CONTENT', 'TEXT', 'BODY', 'MESSAGE', 'REVIEW'], true)) {
                $propValues[$code] = $content;
            } else {
                // Generic: единичный тире-плейсхолдер. Пустая строка не пройдёт
                // IS_REQUIRED-валидацию Битрикса.
                $propValues[$code] = '—';
            }
        }
    }
}
