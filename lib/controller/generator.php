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

    /**
     * Закрывает сессию для записи. Используется в долгих AJAX-actions (генерация
     * описаний, отзывов, восстановление из бэкапа) перед тяжёлым внешним вызовом.
     *
     * Без этого один воркер держит лок сессии Bitrix на 25-45 секунд, и ВСЕ
     * остальные запросы того же пользователя (фронт, админка, любой AJAX)
     * встают в очередь — пользователь видит зависание сайта, хотя сервер
     * технически работает. Это классическая проблема Bitrix session lock.
     *
     * После вызова в массиве $_SESSION можно ЧИТАТЬ, но НЕЛЬЗЯ изменять.
     * В наших долгих actions сессия не меняется — только проверка прав
     * через $USER->IsAdmin() в requireAdmin(), всё что нужно уже прочитано.
     */
    private function releaseSessionLock(): void
    {
        if (PHP_SESSION_ACTIVE === session_status()) {
            @session_write_close();
        }
    }

    public function generateAction(int $id): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        $this->releaseSessionLock();
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
        $this->releaseSessionLock();
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
     * Восстанавливает последнюю сохранённую копию описания из таблицы бэкапов.
     */
    public function restoreLatestAction(int $id): ?array
    {
        if (!$this->requireAdmin()) {
            return null;
        }
        if ($id <= 0) {
            $this->addError(new Error('Некорректный ID'));
            return null;
        }
        if (!Loader::includeModule('iblock')) {
            $this->addError(new Error('Модуль iblock недоступен'));
            return null;
        }
        $gen = new AiGenerator();
        $res = $gen->restoreLatest($id);
        if (empty($res['success'])) {
            $this->addError(new Error($res['error'] ?? 'Ошибка восстановления'));
            return null;
        }
        return [
            'restored' => true,
            'description' => (string)($res['restored'] ?? ''),
            'created_at' => (string)($res['created_at'] ?? ''),
        ];
    }

    /**
     * Возвращает информацию о наличии бэкапа для товара (для UI кнопки «Восстановить»).
     */
    public function hasBackupAction(int $id): ?array
    {
        if (!$this->requireAdmin()) return null;
        if ($id <= 0) return ['has' => false];
        $field = (\Blocksee\Aiseo\Options::getTargetField() === 'PREVIEW_TEXT') ? 'PREVIEW_TEXT' : 'DETAIL_TEXT';
        if (\Blocksee\Aiseo\Options::getTargetField() === 'PROPERTY') {
            $field = 'PROPERTY:' . \Blocksee\Aiseo\Options::getTargetPropertyCode();
        }
        $b = \Blocksee\Aiseo\BackupStorage::getLatest($id, $field);
        return [
            'has' => $b !== null,
            'created_at' => $b['created_at'] ?? null,
        ];
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
            $segments = array_values(array_filter(explode('/', trim($path, '/')), function ($s) { return $s !== ''; }));
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

        // ВНИМАНИЕ: предыдущая реализация имела Phase 2 — fallback по DETAIL_PAGE_URL LIKE.
        // Удалено: DETAIL_PAGE_URL это НЕ колонка таблицы b_iblock_element, а шаблон-строка
        // на инфоблоке (b_iblock.DETAIL_PAGE_URL). Bitrix вычисляет реальный URL на лету,
        // подставляя #ELEMENT_CODE# / #SECTION_CODE_PATH#. Прямой SQL `SELECT DETAIL_PAGE_URL`
        // падал с (1054) Unknown column для каталогов больше определённого размера, когда
        // часть URL не матчилась по CODE и Phase 2 запускалась.
        //
        // На практике URL карточки имеет вид /catalog/.../{element-code}/, и Phase 1 (match
        // по CODE = последний сегмент пути) корректно резолвит >99% реальных кейсов.
        // Если URL не нашёлся по CODE — скорее всего это URL раздела, а не товара,
        // или у товара символьный код не совпадает с URL-ом. В обоих случаях помечаем как
        // "not_found" и пользователь обрабатывает вручную.

        // Phase 3: build response
        $items = [];
        foreach ($rawList as $item) {
            $matched = null;
            if ($item['code'] !== '' && isset($foundByCode[$item['code']])) {
                $matched = $foundByCode[$item['code']];
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
