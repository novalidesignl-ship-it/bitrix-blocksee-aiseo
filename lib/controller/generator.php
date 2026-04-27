<?php

namespace Blocksee\Aiseo\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Blocksee\Aiseo\Generator as AiGenerator;
use Blocksee\Aiseo\Options;

class Generator extends Controller
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

    public function generateAction(int $id): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        if ($id <= 0) {
            $this->addError(new Error('Некорректный ID'));
            return null;
        }
        $gen = new AiGenerator();
        $result = $gen->generateForElement($id);
        if (empty($result['success'])) {
            $this->addError(new Error($result['error'] ?? 'Ошибка генерации'));
            return null;
        }
        return ['description' => $result['description']];
    }

    public function saveAction(int $id, string $description): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        if ($id <= 0) {
            $this->addError(new Error('Некорректный ID'));
            return null;
        }
        $gen = new AiGenerator();
        $result = $gen->saveDescription($id, $description);
        if (empty($result['success'])) {
            $this->addError(new Error($result['error'] ?? 'Ошибка сохранения'));
            return null;
        }
        return ['saved' => true];
    }

    public function generateAndSaveAction(int $id): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        $gen = new AiGenerator();
        $res = $gen->generateForElement($id);
        if (empty($res['success'])) {
            $this->addError(new Error($res['error'] ?? 'Ошибка генерации'));
            return null;
        }
        $save = $gen->saveDescription($id, $res['description']);
        if (empty($save['success'])) {
            $this->addError(new Error($save['error'] ?? 'Ошибка сохранения'));
            return null;
        }
        return ['description' => $res['description'], 'saved' => true];
    }

    /**
     * Резолвит список URL карточек товаров в массив элементов.
     * Парсит каждый URL → берёт последний сегмент path как символьный код товара →
     * ищет элемент в инфоблоках-каталогах по CODE. Если не найдено по CODE,
     * fallback — поиск по DETAIL_PAGE_URL (LIKE %path%).
     *
     * Возвращает массив {url, code, id, name, iblock_id, type, edit_url, status, error}.
     */
    public function resolveUrlsAction(string $urls): ?array
    {
        if (!$this->requireAdmin()) return null;
        if (!Loader::includeModule('iblock')) {
            $this->addError(new Error('Модуль iblock недоступен'));
            return null;
        }

        $lines = preg_split('/[\r\n]+/u', $urls);
        // url => { code, original }
        $byCode = [];
        $byPath = [];
        $rawList = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $path = parse_url($line, PHP_URL_PATH);
            if (!$path) {
                // Может быть просто символьный код, без http://
                $path = '/' . trim($line, '/') . '/';
            }
            $path = '/' . trim($path, '/') . '/';
            $segments = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
            $code = $segments ? end($segments) : '';
            $rawList[] = ['url' => $line, 'path' => $path, 'code' => $code];
            if ($code !== '') {
                $byCode[$code] = true;
            }
            if ($path !== '/') {
                $byPath[$path] = true;
            }
        }
        if (!$rawList) {
            return ['items' => []];
        }

        $iblockIds = Options::getCatalogIblockIds();
        if (!$iblockIds) {
            $this->addError(new Error('Каталог-инфоблоки не найдены'));
            return null;
        }

        // Phase 1: resolve by CODE (быстро, точно)
        $foundByCode = [];
        if ($byCode) {
            $rs = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $iblockIds, 'CODE' => array_keys($byCode), 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID', 'NAME', 'CODE', 'IBLOCK_ID']
            );
            while ($row = $rs->Fetch()) {
                // Берём первый встреченный элемент с этим CODE (на случай дублей в SKU/инфоблоках)
                if (!isset($foundByCode[$row['CODE']])) {
                    $foundByCode[$row['CODE']] = $row;
                }
            }
        }

        // Phase 2: для не-найденных пробуем по DETAIL_PAGE_URL (LIKE по path).
        // Это медленнее, но ловит случаи с подкаталогами / ЧПУ-нестандартом.
        $unresolved = [];
        foreach ($rawList as $item) {
            if ($item['code'] !== '' && isset($foundByCode[$item['code']])) continue;
            if ($item['path'] !== '/') $unresolved[$item['path']] = true;
        }
        $foundByPath = [];
        if ($unresolved) {
            $conn = \Bitrix\Main\Application::getConnection();
            // ID списка инфоблоков для безопасного IN
            $ibIn = implode(',', array_map('intval', $iblockIds));
            $placeholders = [];
            foreach (array_keys($unresolved) as $p) {
                // экранируем % и _ внутри path, чтобы не было ложных LIKE-совпадений
                $escaped = $conn->getSqlHelper()->forSql(addcslashes($p, '%_\\'));
                $placeholders[$p] = "DETAIL_PAGE_URL LIKE '%{$escaped}'";
            }
            // Генерируем большое OR — до ~100 LIKE безопасно. Для очень длинных списков чанкуем.
            $chunks = array_chunk(array_keys($unresolved), 100, true);
            foreach ($chunks as $chunk) {
                $ors = [];
                foreach ($chunk as $p) {
                    $ors[] = $placeholders[$p];
                }
                $sql = "SELECT ID, NAME, CODE, IBLOCK_ID, DETAIL_PAGE_URL FROM b_iblock_element
                        WHERE IBLOCK_ID IN ({$ibIn}) AND ACTIVE='Y' AND (" . implode(' OR ', $ors) . ")";
                $rsP = $conn->query($sql);
                while ($row = $rsP->fetch()) {
                    foreach ($chunk as $p) {
                        if (mb_substr($row['DETAIL_PAGE_URL'], -mb_strlen($p)) === $p) {
                            if (!isset($foundByPath[$p])) {
                                $foundByPath[$p] = $row;
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Phase 3: build response
        $items = [];
        foreach ($rawList as $item) {
            $matched = null;
            if ($item['code'] !== '' && isset($foundByCode[$item['code']])) {
                $matched = $foundByCode[$item['code']];
            } elseif (isset($foundByPath[$item['path']])) {
                $matched = $foundByPath[$item['path']];
            }

            if ($matched) {
                $items[] = [
                    'url' => $item['url'],
                    'code' => (string)$matched['CODE'],
                    'id' => (int)$matched['ID'],
                    'name' => (string)$matched['NAME'],
                    'iblock_id' => (int)$matched['IBLOCK_ID'],
                    'edit_url' => Options::buildElementEditUrl((int)$matched['IBLOCK_ID'], (int)$matched['ID']),
                    'status' => 'found',
                ];
            } else {
                $items[] = [
                    'url' => $item['url'],
                    'code' => $item['code'],
                    'id' => 0,
                    'status' => 'not_found',
                ];
            }
        }

        return ['items' => $items];
    }

    public function listAction(int $iblockId = 0, int $offset = 0, int $limit = 50, string $search = '', string $scenario = 'all'): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        if (!Loader::includeModule('iblock')) {
            $this->addError(new Error('Модуль iblock недоступен'));
            return null;
        }

        if ($iblockId <= 0) {
            $iblockId = Options::getIblockId();
        }

        $filter = ['ACTIVE' => 'Y'];
        if ($iblockId > 0) {
            $filter['IBLOCK_ID'] = $iblockId;
        } else {
            $ids = Options::getCatalogIblockIds();
            if ($ids) {
                $filter['IBLOCK_ID'] = $ids;
            }
        }
        if ($search !== '') {
            $filter['NAME'] = '%' . $search . '%';
        }

        $rs = \CIBlockElement::GetList(
            ['NAME' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => $limit + $offset + 1],
            ['ID', 'NAME', 'IBLOCK_ID', 'DETAIL_TEXT', 'PREVIEW_TEXT', 'DETAIL_PAGE_URL']
        );

        $rs->NavStart($limit, false, intdiv($offset, max($limit, 1)) + 1);
        $total = (int)$rs->SelectedRowsCount();

        $items = [];
        while ($row = $rs->GetNext(true, false)) {
            $hasDesc = trim(strip_tags((string)$row['DETAIL_TEXT'])) !== ''
                || trim(strip_tags((string)$row['PREVIEW_TEXT'])) !== '';
            if ($scenario === 'empty_only' && $hasDesc) {
                continue;
            }
            $items[] = [
                'id' => (int)$row['ID'],
                'name' => (string)$row['NAME'],
                'iblock_id' => (int)$row['IBLOCK_ID'],
                'has_description' => $hasDesc,
                'current_description' => (string)($row['DETAIL_TEXT'] ?: $row['PREVIEW_TEXT']),
                'edit_url' => "/bitrix/admin/iblock_element_edit.php?IBLOCK_ID={$row['IBLOCK_ID']}&type=" . urlencode(Options::getIblockTypeId((int)$row['IBLOCK_ID']) ?: 'catalog') . "&ID={$row['ID']}&lang=ru",
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    public function listNextChunkAction(
        int $iblockId = 0,
        string $scenario = 'all',
        string $search = '',
        int $afterId = 0,
        int $limit = 200,
        string $includeTotal = 'N',
        string $sectionIds = ''
    ): ?array {
        $sectionIdsArr = array_values(array_filter(array_map('intval', explode(',', $sectionIds))));
        if (!$this->requireAdmin()) {
            return null;
        }
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

        $limit = max(10, min(1000, $limit));

        $baseFilter = [
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => $iblockId,
        ];
        if ($search !== '') {
            $baseFilter['NAME'] = '%' . $search . '%';
        }
        if (!empty($sectionIdsArr)) {
            $baseFilter['SECTION_ID'] = $sectionIdsArr;
            $baseFilter['INCLUDE_SUBSECTIONS'] = 'Y';
        }

        $totalCount = null;
        if ($includeTotal === 'Y') {
            if ($scenario === 'empty_only') {
                $totalCount = $this->countEmptyElements($iblockId, $search, $sectionIdsArr);
            } else {
                $totalCount = (int)\CIBlockElement::GetList(
                    [],
                    $baseFilter,
                    [],
                    false,
                    ['ID']
                );
            }
        }

        // Fetch next chunk by ID > afterId
        $chunkFilter = $baseFilter;
        if ($afterId > 0) {
            $chunkFilter['>ID'] = $afterId;
        }

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $chunkFilter,
            false,
            ['nTopCount' => $limit],
            ['ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']
        );

        $ids = [];
        $lastId = $afterId;
        $fetched = 0;
        while ($row = $rs->Fetch()) {
            $fetched++;
            $lastId = (int)$row['ID'];
            if ($scenario === 'empty_only') {
                $hasDesc = trim(strip_tags((string)$row['DETAIL_TEXT'])) !== ''
                    || trim(strip_tags((string)$row['PREVIEW_TEXT'])) !== '';
                if ($hasDesc) {
                    continue;
                }
            }
            $ids[] = (int)$row['ID'];
        }

        $hasMore = ($fetched === $limit);

        $result = [
            'ids' => $ids,
            'lastId' => $lastId,
            'hasMore' => $hasMore,
        ];
        if ($totalCount !== null) {
            $result['totalCount'] = $totalCount;
        }
        return $result;
    }

    private function countEmptyElements(int $iblockId, string $search, array $sectionIds = []): int
    {
        $filter = ['ACTIVE' => 'Y', 'IBLOCK_ID' => $iblockId];
        if ($search !== '') {
            $filter['NAME'] = '%' . $search . '%';
        }
        if (!empty($sectionIds)) {
            $filter['SECTION_ID'] = $sectionIds;
            $filter['INCLUDE_SUBSECTIONS'] = 'Y';
        }
        // Iterate once to count empties — done only at start of a bulk job
        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']
        );
        $count = 0;
        while ($row = $rs->Fetch()) {
            $hasDesc = trim(strip_tags((string)$row['DETAIL_TEXT'])) !== ''
                || trim(strip_tags((string)$row['PREVIEW_TEXT'])) !== '';
            if (!$hasDesc) $count++;
        }
        return $count;
    }

    public function pingAction(): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        $client = new \Blocksee\Aiseo\ApiClient();
        return $client->ping();
    }

    public function savePromptAction(string $prompt): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        Options::set('custom_prompt', $prompt);
        return ['saved' => true];
    }
}
