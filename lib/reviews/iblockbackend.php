<?php

namespace Blocksee\Aiseo\Reviews;

use Bitrix\Main\Loader;
use Blocksee\Aiseo\TextSanitizer;

/**
 * Backend для решений, которые хранят отзывы в собственном инфоблоке
 * (Aspro Max, и аналогичные сборки): отзыв = элемент инфоблока отзывов,
 * связь с товаром = свойство-привязка тип E на товарном инфоблоке.
 *
 * Структура определяется автоматически — никаких хардкод-ID. Backend ищет:
 *   1. На товарном инфоблоке свойство-связь с кодом PRODUCT_REVIEWS /
 *      LINK_REVIEWS / REVIEWS (в порядке убывания приоритета). У него в
 *      LINK_IBLOCK_ID — ID инфоблока отзывов.
 *   2. В инфоблоке отзывов свойство RATING (или STARS / RATING_VALUE).
 *
 * Структура отзыва-элемента: NAME = автор, DETAIL_TEXT = текст,
 * PROPERTY_<RATING_CODE> = оценка.
 */
class IblockBackend implements Backend
{
    /** Кандидаты на код свойства-привязки на товарном инфоблоке. */
    private const LINK_PROP_CANDIDATES = [
        'PRODUCT_REVIEWS',
        'LINK_REVIEWS',
        'REVIEWS',
        'LINK_PRODUCT_REVIEWS',
    ];
    /** Кандидаты на код свойства-оценки внутри инфоблока отзывов. */
    private const RATING_PROP_CANDIDATES = [
        'RATING',
        'RATING_VALUE',
        'STARS',
    ];
    /** Кандидаты на код свойства «ФИО автора» внутри инфоблока отзывов. */
    private const AUTHOR_PROP_CANDIDATES = [
        'TITLE',          // Aspro Max: "ФИО кто оставил отзыв"
        'AUTHOR',
        'AUTHOR_NAME',
        'FIO',
        'NAME',           // встречается у некоторых сборок как property
    ];

    /** @var array<int,?array{link_prop_code:string,reviews_iblock:int,rating_prop_code:string,author_prop_code:string}> */
    private static $structureCache = [];

    public function isAvailable(): bool
    {
        return Loader::includeModule('iblock');
    }

    /**
     * Возвращает структуру для указанного товарного инфоблока.
     * null = backend на этом инфоблоке не работает.
     *
     * @return array{link_prop_code:string,reviews_iblock:int,rating_prop_code:string,author_prop_code:string}|null
     */
    public function detectStructure(int $catalogIblockId): ?array
    {
        if (isset(self::$structureCache[$catalogIblockId])) {
            return self::$structureCache[$catalogIblockId];
        }
        if (!Loader::includeModule('iblock')) {
            return self::$structureCache[$catalogIblockId] = null;
        }

        $reviewsIblockId = 0;
        $linkCode = '';
        foreach (self::LINK_PROP_CANDIDATES as $code) {
            $row = \Bitrix\Iblock\PropertyTable::getList([
                'select' => ['ID', 'CODE', 'LINK_IBLOCK_ID'],
                'filter' => [
                    '=IBLOCK_ID' => $catalogIblockId,
                    '=CODE' => $code,
                    '=PROPERTY_TYPE' => 'E',
                    '=ACTIVE' => 'Y',
                ],
                'limit' => 1,
            ])->fetch();
            if ($row && (int)$row['LINK_IBLOCK_ID'] > 0) {
                $reviewsIblockId = (int)$row['LINK_IBLOCK_ID'];
                $linkCode = (string)$row['CODE'];
                break;
            }
        }

        if (!$reviewsIblockId) {
            return self::$structureCache[$catalogIblockId] = null;
        }

        // Берём все свойства инфоблока отзывов одним запросом, потом матчим
        // под кандидатов в нужном порядке. Это надёжнее раздельных запросов
        // по filter:CODE — Bitrix фильтрует через IN, а нам нужен приоритет.
        $allProps = [];
        $rsP = \Bitrix\Iblock\PropertyTable::getList([
            'select' => ['CODE', 'PROPERTY_TYPE'],
            'filter' => ['=IBLOCK_ID' => $reviewsIblockId, '=ACTIVE' => 'Y'],
        ]);
        while ($p = $rsP->fetch()) {
            $allProps[$p['CODE']] = $p['PROPERTY_TYPE'];
        }

        $ratingCode = 'RATING';
        foreach (self::RATING_PROP_CANDIDATES as $cand) {
            if (isset($allProps[$cand])) { $ratingCode = $cand; break; }
        }
        $authorCode = '';
        foreach (self::AUTHOR_PROP_CANDIDATES as $cand) {
            // Берём только string-свойство ('S'), чтобы не попасть на NAME-имя
            // элемента (его формально в b_iblock_property нет, но на всякий случай).
            if (isset($allProps[$cand]) && $allProps[$cand] === 'S') { $authorCode = $cand; break; }
        }

        return self::$structureCache[$catalogIblockId] = [
            'link_prop_code' => $linkCode,
            'reviews_iblock' => $reviewsIblockId,
            'rating_prop_code' => $ratingCode,
            'author_prop_code' => $authorCode,
        ];
    }

    public function setupForIblock(int $iblockId): array
    {
        $struct = $this->detectStructure($iblockId);
        if (!$struct) {
            return [
                'success' => false,
                'error' => 'Не найдено свойство-связь с инфоблоком отзывов на товарном инфоблоке #' . $iblockId
                    . '. Ожидается свойство типа "Привязка к элементам" с кодом PRODUCT_REVIEWS / LINK_REVIEWS / REVIEWS.',
            ];
        }
        return ['success' => true] + $struct;
    }

    public function count(int $elementId): int
    {
        if (!Loader::includeModule('iblock')) return 0;
        $iblockId = $this->resolveIblockId($elementId);
        if (!$iblockId) return 0;
        $struct = $this->detectStructure($iblockId);
        if (!$struct) return 0;

        $linked = $this->getLinkedReviewIds($elementId, $struct['link_prop_code']);
        return count($linked);
    }

    public function countsForElements(array $elementIds, int $iblockId): array
    {
        if (!$elementIds || !Loader::includeModule('iblock')) return [];
        $struct = $this->detectStructure($iblockId);
        if (!$struct) return [];

        $code = $struct['link_prop_code'];
        $out = [];
        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $elementIds],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'PROPERTY_' . $code]
        );
        while ($row = $rs->Fetch()) {
            $eid = (int)$row['ID'];
            if (!isset($out[$eid])) $out[$eid] = 0;
            if (!empty($row['PROPERTY_' . $code . '_VALUE'])) {
                $out[$eid]++;
            }
        }
        // Если у товара несколько отзывов — multiple property вернёт несколько строк,
        // и счётчик инкрементируется на каждой. Это уже даёт корректный count.
        return $out;
    }

    public function listForElement(int $elementId, int $limit = 50): array
    {
        if (!Loader::includeModule('iblock')) return [];
        $iblockId = $this->resolveIblockId($elementId);
        if (!$iblockId) return [];
        $struct = $this->detectStructure($iblockId);
        if (!$struct) return [];

        $reviewIds = $this->getLinkedReviewIds($elementId, $struct['link_prop_code']);
        if (!$reviewIds) return [];

        $reviewIds = array_slice($reviewIds, 0, max(1, $limit));
        $ratingCode = $struct['rating_prop_code'];
        $authorCode = $struct['author_prop_code'];
        $select = ['ID', 'NAME', 'DETAIL_TEXT', 'DATE_CREATE', 'PROPERTY_' . $ratingCode];
        if ($authorCode !== '') {
            $select[] = 'PROPERTY_' . $authorCode;
        }
        $rs = \CIBlockElement::GetList(
            ['DATE_CREATE' => 'DESC'],
            ['IBLOCK_ID' => $struct['reviews_iblock'], 'ID' => $reviewIds],
            false,
            false,
            $select
        );
        $out = [];
        while ($r = $rs->Fetch()) {
            // Имя — из свойства (Aspro Max-style), иначе из NAME элемента
            $author = '';
            if ($authorCode !== '' && !empty($r['PROPERTY_' . $authorCode . '_VALUE'])) {
                $author = (string)$r['PROPERTY_' . $authorCode . '_VALUE'];
            } else {
                $author = (string)$r['NAME'];
            }
            $out[] = [
                'id' => (int)$r['ID'],
                'author_name' => $author,
                'content' => (string)$r['DETAIL_TEXT'],
                'rating' => (int)($r['PROPERTY_' . $ratingCode . '_VALUE'] ?? 0),
                'date' => (string)$r['DATE_CREATE'],
            ];
        }
        return $out;
    }

    public function saveForElement(int $elementId, array $reviews, array $opts): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['success' => false, 'error' => 'iblock module unavailable'];
        }
        $iblockId = $this->resolveIblockId($elementId);
        if (!$iblockId) {
            return ['success' => false, 'error' => 'Element not found'];
        }
        $struct = $this->detectStructure($iblockId);
        if (!$struct) {
            return [
                'success' => false,
                'error' => 'Aspro Max-структура не обнаружена на инфоблоке #' . $iblockId,
            ];
        }

        $reviewsIblock = $struct['reviews_iblock'];
        $linkCode = $struct['link_prop_code'];
        $ratingCode = $struct['rating_prop_code'];
        $authorCode = $struct['author_prop_code'];
        $autoApprove = !empty($opts['auto_approve']);

        $createdIds = [];
        $cie = new \CIBlockElement();
        foreach ($reviews as $r) {
            $author = TextSanitizer::stripEmoji(trim((string)($r['author_name'] ?? '')));
            $content = TextSanitizer::stripEmoji(trim((string)($r['content'] ?? '')));
            $rating = max(1, min(5, (int)($r['rating'] ?? 5)));
            if ($author === '' || $content === '') continue;

            // PROPERTY_VALUES: имя автора кладём в свойство-кандидат (TITLE/AUTHOR/...),
            // если оно есть в инфоблоке. NAME элемента дублируем именем — оно нужно
            // для админки (список элементов покажет ФИО), но шаблон Aspro Max
            // отображает автора именно из свойства.
            $propValues = [$ratingCode => $rating];
            if ($authorCode !== '') {
                $propValues[$authorCode] = $author;
            }

            $newId = $cie->Add([
                'IBLOCK_ID' => $reviewsIblock,
                'NAME' => $author,
                'ACTIVE' => $autoApprove ? 'Y' : 'N',
                'DETAIL_TEXT' => $content,
                'DETAIL_TEXT_TYPE' => 'text',
                'PROPERTY_VALUES' => $propValues,
            ]);
            if ($newId) {
                $createdIds[] = (int)$newId;
            }
        }

        if (!$createdIds) {
            return ['success' => false, 'error' => 'Ни один отзыв не сохранён (возможно, пустые тексты)'];
        }

        // Дополняем multiple-свойство товара. \CIBlockElement::SetPropertyValuesEx
        // c флагом 'add' — суммирует, не затирает существующие значения.
        \CIBlockElement::SetPropertyValuesEx(
            $elementId,
            $iblockId,
            [$linkCode => array_merge($this->getLinkedReviewIds($elementId, $linkCode), $createdIds)]
        );

        return [
            'success' => true,
            'saved' => count($createdIds),
            'container_id' => $reviewsIblock,
        ];
    }

    public function deleteOne(int $messageId): bool
    {
        if (!Loader::includeModule('iblock')) return false;
        // У нас messageId — это ID элемента в инфоблоке отзывов. Свойство товара
        // подтянет multiple — связь обновится автоматически? Нет. Bitrix не
        // чистит ссылки multiple-свойства при удалении target-элемента, так что
        // на товаре в свойстве PRODUCT_REVIEWS останется ID удалённого. Это
        // некритично — GetList по ID не вернёт удалённый, шаблон не отрисует.
        return (bool)\CIBlockElement::Delete($messageId);
    }

    public function updateOne(int $messageId, string $author, string $content, int $rating): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['success' => false, 'error' => 'iblock module unavailable'];
        }
        $row = \CIBlockElement::GetByID($messageId)->Fetch();
        if (!$row) return ['success' => false, 'error' => 'Отзыв не найден'];
        $iblockId = (int)$row['IBLOCK_ID'];

        $author = TextSanitizer::stripEmoji(trim($author));
        $content = TextSanitizer::stripEmoji(trim($content));
        $rating = max(1, min(5, $rating));

        $cie = new \CIBlockElement();
        $ok = $cie->Update($messageId, [
            'NAME' => $author,
            'DETAIL_TEXT' => $content,
            'DETAIL_TEXT_TYPE' => 'text',
        ]);
        if (!$ok) {
            return ['success' => false, 'error' => $cie->LAST_ERROR ?: 'Ошибка обновления'];
        }
        // Свойства rating + author подбираем отдельным запросом по инфоблоку
        // отзывов (мы не знаем catalogIblockId здесь, используем IBLOCK_ID отзыва).
        $ratingCode = 'RATING';
        $authorCode = '';
        $rsP = \Bitrix\Iblock\PropertyTable::getList([
            'select' => ['CODE', 'PROPERTY_TYPE'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y'],
        ]);
        $allProps = [];
        while ($p = $rsP->fetch()) $allProps[$p['CODE']] = $p['PROPERTY_TYPE'];
        foreach (self::RATING_PROP_CANDIDATES as $cand) {
            if (isset($allProps[$cand])) { $ratingCode = $cand; break; }
        }
        foreach (self::AUTHOR_PROP_CANDIDATES as $cand) {
            if (isset($allProps[$cand]) && $allProps[$cand] === 'S') { $authorCode = $cand; break; }
        }
        $propValues = [$ratingCode => $rating];
        if ($authorCode !== '') {
            $propValues[$authorCode] = $author;
        }
        \CIBlockElement::SetPropertyValuesEx($messageId, $iblockId, $propValues);
        return ['success' => true];
    }

    public function findElementsWithReviews(int $iblockId): array
    {
        if (!Loader::includeModule('iblock')) return [];
        $struct = $this->detectStructure($iblockId);
        if (!$struct) return [];

        $code = $struct['link_prop_code'];
        $out = [];
        // !PROPERTY_<CODE> => false ↔ свойство НЕ пустое (Битрикс-конвенция).
        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '!PROPERTY_' . $code => false],
            false,
            false,
            ['ID', 'PROPERTY_' . $code]
        );
        while ($row = $rs->Fetch()) {
            $eid = (int)$row['ID'];
            if (!isset($out[$eid])) $out[$eid] = 0;
            if (!empty($row['PROPERTY_' . $code . '_VALUE'])) {
                $out[$eid]++;
            }
        }
        return $out;
    }

    /**
     * Текущие ID-связи товара в multiple-свойстве. Возвращает массив ID
     * элементов инфоблока отзывов.
     *
     * @return int[]
     */
    private function getLinkedReviewIds(int $elementId, string $linkCode): array
    {
        $iblockId = $this->resolveIblockId($elementId);
        if (!$iblockId) return [];
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
}
