<?php

namespace Blocksee\Aiseo\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Blocksee\Aiseo\CategoryGenerator;
use Blocksee\Aiseo\Options;

/**
 * Контроллер для генерации описаний категорий каталога.
 *
 * Параллельная структура с Generator (для товаров): generate / save /
 * generateAndSave / list / listNextChunk. UI/UX повторяет admin/list.php,
 * но операирует на b_iblock_section вместо b_iblock_element.
 */
class Category extends Controller
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
     * Аналог Generator::releaseSessionLock — отпускаем сессию перед долгим
     * внешним вызовом, иначе один воркер держит лок Bitrix-сессии и весь
     * фронт у того же админа встаёт в очередь на 25-45 секунд.
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
        $gen = new CategoryGenerator();
        $result = $gen->generateForSection($id);
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
        $gen = new CategoryGenerator();
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
        $gen = new CategoryGenerator();
        $res = $gen->generateForSection($id);
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
     * Возвращает следующую пачку ID секций по курсору `>ID = afterId`,
     * с опциональным фильтром «empty_only» (DESCRIPTION пустой).
     */
    public function listNextChunkAction(
        int $iblockId = 0,
        string $scenario = 'all',
        string $search = '',
        int $afterId = 0,
        int $limit = 200,
        string $includeTotal = 'N'
    ): ?array {
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
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
        ];
        if ($search !== '') {
            $baseFilter['NAME'] = '%' . $search . '%';
        }

        $totalCount = null;
        if ($includeTotal === 'Y') {
            if ($scenario === 'empty_only') {
                $totalCount = $this->countEmptySections($iblockId, $search);
            } else {
                $totalCount = (int)\CIBlockSection::GetCount($baseFilter);
            }
        }

        $chunkFilter = $baseFilter;
        if ($afterId > 0) {
            $chunkFilter['>ID'] = $afterId;
        }

        $rs = \CIBlockSection::GetList(
            ['ID' => 'ASC'],
            $chunkFilter,
            false,
            ['ID', 'DESCRIPTION'],
            ['nTopCount' => $limit]
        );

        $ids = [];
        $lastId = $afterId;
        $fetched = 0;
        while ($row = $rs->Fetch()) {
            $fetched++;
            $lastId = (int)$row['ID'];
            if ($scenario === 'empty_only') {
                $hasDesc = trim(strip_tags((string)$row['DESCRIPTION'])) !== '';
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

    private function countEmptySections(int $iblockId, string $search): int
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'];
        if ($search !== '') {
            $filter['NAME'] = '%' . $search . '%';
        }
        $rs = \CIBlockSection::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['ID', 'DESCRIPTION']
        );
        $count = 0;
        while ($row = $rs->Fetch()) {
            $hasDesc = trim(strip_tags((string)$row['DESCRIPTION'])) !== '';
            if (!$hasDesc) $count++;
        }
        return $count;
    }
}
