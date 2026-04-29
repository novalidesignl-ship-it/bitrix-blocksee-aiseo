<?php

namespace Blocksee\Aiseo\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Blocksee\Aiseo\ReviewsGenerator;
use Blocksee\Aiseo\Options;

class Reviews extends Controller
{
    protected function getDefaultPreFilters(): array
    {
        return [
            new ActionFilter\Authentication(),
            new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST, ActionFilter\HttpMethod::METHOD_GET]),
            new ActionFilter\Csrf(),
        ];
    }

    private function requireAdmin(): bool
    {
        global $USER;
        if (!$USER || !$USER->IsAdmin()) {
            $this->addError(new Error('Недостаточно прав', 'access_denied'));
            return false;
        }
        return true;
    }

    public function generateAndSaveAction(int $id, int $count = 0): ?array
    {
        if (!$this->requireAdmin()) return null;
        if ($id <= 0) {
            $this->addError(new Error('Некорректный ID'));
            return null;
        }
        $gen = new ReviewsGenerator();
        $res = $gen->generateAndSaveForElement($id, $count);
        if (empty($res['success'])) {
            $this->addError(new Error($res['error'] ?? 'Ошибка'));
            return null;
        }
        return [
            'saved' => $res['saved'],
            'topic_id' => $res['topic_id'],
            'total' => $gen->countReviewsForElement($id),
        ];
    }

    public function generateAction(int $id, int $count = 0): ?array
    {
        if (!$this->requireAdmin()) return null;
        if ($id <= 0) {
            $this->addError(new Error('Некорректный ID'));
            return null;
        }
        $gen = new ReviewsGenerator();
        $res = $gen->generateForElement($id, $count);
        if (empty($res['success'])) {
            $this->addError(new Error($res['error'] ?? 'Ошибка'));
            return null;
        }
        return ['reviews' => $res['reviews']];
    }

    public function listAction(int $id, int $limit = 20): ?array
    {
        if (!$this->requireAdmin()) return null;
        $gen = new ReviewsGenerator();
        return [
            'items' => $gen->listReviewsForElement($id, $limit),
            'total' => $gen->countReviewsForElement($id),
        ];
    }

    public function deleteAction(int $messageId): ?array
    {
        if (!$this->requireAdmin()) return null;
        $gen = new ReviewsGenerator();
        $ok = $gen->deleteReview($messageId);
        if (!$ok) {
            $this->addError(new Error('Не удалось удалить сообщение'));
            return null;
        }
        return ['deleted' => true];
    }

    public function updateAction(int $messageId, string $author, string $content, int $rating): ?array
    {
        if (!$this->requireAdmin()) return null;
        if ($messageId <= 0) {
            $this->addError(new Error('Некорректный ID'));
            return null;
        }
        $gen = new ReviewsGenerator();
        $res = $gen->updateReview($messageId, $author, $content, $rating);
        if (empty($res['success'])) {
            $this->addError(new Error($res['error'] ?? 'Ошибка сохранения'));
            return null;
        }
        return ['updated' => true];
    }

    public function savePromptAction(string $prompt): ?array
    {
        if (!$this->requireAdmin()) return null;
        Options::set('reviews_custom_prompt', $prompt);
        return ['saved' => true];
    }

    /**
     * Возвращает количество существующих отзывов для каждого ID из списка.
     * Используется страницей «Генерация отзывов по ссылкам» для UI-фильтра
     * «только товары без отзывов». Принимает comma-separated список ID,
     * возвращает массив {id: count}.
     */
    public function getReviewCountsAction(string $ids): ?array
    {
        if (!$this->requireAdmin()) return null;
        if (!Loader::includeModule('iblock')) {
            $this->addError(new Error('Модуль iblock недоступен'));
            return null;
        }
        $rawIds = array_values(array_filter(array_map('intval', explode(',', $ids)), function ($i) { return $i > 0; }));
        if (empty($rawIds)) {
            return ['counts' => []];
        }
        $rawIds = array_values(array_unique($rawIds));

        // Группируем элементы по IBLOCK_ID одним запросом, потом считаем отзывы
        // массово через countsForElements (один JOIN-запрос на инфоблок).
        // Для 750 товаров получаем 1-3 SQL запроса вместо 750 — критично для AJAX
        // в админке (был баг: один long-running воркер блокировал админку).
        $byIblock = [];
        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['ID' => $rawIds],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );
        while ($row = $rs->Fetch()) {
            $iblockId = (int)$row['IBLOCK_ID'];
            if (!isset($byIblock[$iblockId])) $byIblock[$iblockId] = [];
            $byIblock[$iblockId][] = (int)$row['ID'];
        }

        $gen = new \Blocksee\Aiseo\ReviewsGenerator();
        $counts = [];
        foreach ($byIblock as $iblockId => $idsInIblock) {
            $partial = $gen->countsForElements($idsInIblock, $iblockId);
            foreach ($partial as $id => $cnt) {
                $counts[(string)$id] = (int)$cnt;
            }
        }
        // Заполняем нулями для элементов которые backend не вернул (никаких отзывов)
        foreach ($rawIds as $id) {
            if (!isset($counts[(string)$id])) {
                $counts[(string)$id] = 0;
            }
        }
        return ['counts' => $counts];
    }

    /**
     * Cursor pagination over products for bulk review generation.
     * scenario: all_products | skip_with_reviews
     */
    public function listNextChunkAction(
        int $iblockId = 0,
        string $scenario = 'all_products',
        string $search = '',
        int $afterId = 0,
        int $limit = 200,
        string $includeTotal = 'N',
        string $sectionIds = ''
    ): ?array {
        if (!$this->requireAdmin()) return null;
        if (!Loader::includeModule('iblock')) {
            $this->addError(new Error('Модуль iblock недоступен'));
            return null;
        }
        if ($iblockId <= 0) {
            $iblockId = Options::getIblockId();
        }
        if ($iblockId <= 0) {
            $this->addError(new Error('Инфоблок не задан'));
            return null;
        }

        $sectionIdsArr = array_values(array_filter(array_map('intval', explode(',', $sectionIds))));
        $limit = max(10, min(1000, $limit));

        $baseFilter = ['ACTIVE' => 'Y', 'IBLOCK_ID' => $iblockId];
        if ($search !== '') {
            $baseFilter['NAME'] = '%' . $search . '%';
        }
        if (!empty($sectionIdsArr)) {
            $baseFilter['SECTION_ID'] = $sectionIdsArr;
            $baseFilter['INCLUDE_SUBSECTIONS'] = 'Y';
        }

        $productsWithReviews = [];
        if ($scenario === 'skip_with_reviews') {
            $gen = new ReviewsGenerator();
            $productsWithReviews = $gen->findElementsWithReviews($iblockId);
        }

        $totalCount = null;
        if ($includeTotal === 'Y') {
            $total = (int)\CIBlockElement::GetList([], $baseFilter, [], false, ['ID']);
            if ($scenario === 'skip_with_reviews') {
                $total = max(0, $total - count($productsWithReviews));
            }
            $totalCount = $total;
        }

        $chunkFilter = $baseFilter;
        if ($afterId > 0) {
            $chunkFilter['>ID'] = $afterId;
        }

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $chunkFilter,
            false,
            ['nTopCount' => $limit],
            ['ID']
        );
        $ids = [];
        $lastId = $afterId;
        $fetched = 0;
        while ($row = $rs->Fetch()) {
            $fetched++;
            $lastId = (int)$row['ID'];
            if ($scenario === 'skip_with_reviews' && isset($productsWithReviews[$lastId])) {
                continue;
            }
            $ids[] = $lastId;
        }

        $result = [
            'ids' => $ids,
            'lastId' => $lastId,
            'hasMore' => ($fetched === $limit),
        ];
        if ($totalCount !== null) $result['totalCount'] = $totalCount;
        return $result;
    }
}
