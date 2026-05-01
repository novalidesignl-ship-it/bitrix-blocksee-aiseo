<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

/**
 * Генератор описаний для категорий каталога (b_iblock_section.DESCRIPTION).
 *
 * Использует action `generate_category_description` на стороне lk.blocksee.ru API,
 * собирает контекст секции (название, родитель, кол-во товаров, подкатегории,
 * примеры товаров, общие атрибуты) и записывает результат в DESCRIPTION секции.
 *
 * Не имеет own classfield — все методы static-style работают через короткоживущий
 * экземпляр, чтобы не конфликтовать с обычным Generator.
 */
class CategoryGenerator
{
    /** @var string */
    private $endpoint;

    public function __construct()
    {
        Loader::includeModule('iblock');
        $this->endpoint = Options::getApiEndpoint();
    }

    /**
     * Собирает данные секции для отправки в API.
     *
     * @return array<string,mixed>|null
     */
    public function collectSectionData($sectionId)
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) return null;

        $section = \CIBlockSection::GetByID($sectionId)->Fetch();
        if (!$section) return null;

        $data = [
            'id' => (int)$section['ID'],
            'name' => (string)$section['NAME'],
            'iblock_id' => (int)$section['IBLOCK_ID'],
            'count' => 0,
            'subcategories' => [],
            'products' => [],
            'attributes' => [],
        ];

        // Родительская категория
        if (!empty($section['IBLOCK_SECTION_ID'])) {
            $parent = \CIBlockSection::GetByID((int)$section['IBLOCK_SECTION_ID'])->Fetch();
            if ($parent) {
                $data['parent_name'] = (string)$parent['NAME'];
            }
        }

        // Подкатегории
        $rsSub = \CIBlockSection::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $data['iblock_id'], 'SECTION_ID' => $sectionId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME']
        );
        while ($s = $rsSub->Fetch()) {
            $data['subcategories'][] = (string)$s['NAME'];
        }

        // Кол-во товаров (с подсекциями)
        $cntRs = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $data['iblock_id'],
                'SECTION_ID' => $sectionId,
                'INCLUDE_SUBSECTIONS' => 'Y',
                'ACTIVE' => 'Y',
            ],
            ['IBLOCK_ID']
        );
        $data['count'] = (int)$cntRs->SelectedRowsCount();

        // Примеры товаров (5 штук)
        $rsProd = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            [
                'IBLOCK_ID' => $data['iblock_id'],
                'SECTION_ID' => $sectionId,
                'INCLUDE_SUBSECTIONS' => 'Y',
                'ACTIVE' => 'Y',
            ],
            false,
            ['nTopCount' => 5],
            ['ID', 'NAME', 'CATALOG_PRICE_1', 'CATALOG_CURRENCY_1']
        );
        while ($p = $rsProd->Fetch()) {
            $entry = ['name' => (string)$p['NAME']];
            if (!empty($p['CATALOG_PRICE_1'])) {
                $entry['price'] = (float)$p['CATALOG_PRICE_1'];
            }
            $data['products'][] = $entry;
        }

        // Общие атрибуты — выберем 3-5 самых популярных свойств с агрегированными значениями.
        // Для простоты в MVP не агрегируем — берём свойства первых 10 товаров и собираем
        // распределение значений.
        $rsForProps = \CIBlockElement::GetList(
            ['SORT' => 'ASC'],
            [
                'IBLOCK_ID' => $data['iblock_id'],
                'SECTION_ID' => $sectionId,
                'INCLUDE_SUBSECTIONS' => 'Y',
                'ACTIVE' => 'Y',
            ],
            false,
            ['nTopCount' => 10],
            ['ID']
        );
        $sampleIds = [];
        while ($r = $rsForProps->Fetch()) {
            $sampleIds[] = (int)$r['ID'];
        }
        if ($sampleIds) {
            $attrs = [];
            foreach ($sampleIds as $eid) {
                $rsP = \CIBlockElement::GetProperty($data['iblock_id'], $eid, ['sort' => 'asc'], ['ACTIVE' => 'Y']);
                while ($p = $rsP->Fetch()) {
                    if (empty($p['NAME']) || $p['VALUE'] === null || $p['VALUE'] === '' || $p['VALUE'] === false) continue;
                    $name = (string)$p['NAME'];
                    $val = is_array($p['VALUE']) ? implode(' ', $p['VALUE']) : (string)$p['VALUE'];
                    if ($val === '') continue;
                    if (!isset($attrs[$name])) $attrs[$name] = [];
                    $attrs[$name][$val] = true;
                }
            }
            // Берём свойства, у которых 2+ разных значения, — это атрибуты с реальной вариативностью
            $useful = [];
            foreach ($attrs as $name => $vals) {
                $vals = array_keys($vals);
                if (count($vals) >= 2) {
                    $useful[$name] = $vals;
                }
                if (count($useful) >= 5) break;
            }
            $data['attributes'] = $useful;
        }

        return $data;
    }

    /**
     * Делает запрос к AI API, возвращает description либо ошибку.
     *
     * @return array{success:bool, description?:string, error?:string}
     */
    public function generateForSection($sectionId)
    {
        $data = $this->collectSectionData($sectionId);
        if (!$data) {
            return ['success' => false, 'error' => 'Категория не найдена'];
        }

        $payload = [
            'action' => 'generate_category_description',
            'category_data' => $data,
            'custom_prompt' => Options::getCustomPrompt(),
            'settings' => Options::getGenerationSettings(),
        ];

        $client = new HttpClient([
            'socketTimeout' => 90,
            'streamTimeout' => 90,
            'waitResponse' => true,
        ]);
        $client->setHeader('Content-Type', 'application/json; charset=utf-8');
        $client->setHeader('Referer', $this->getSiteUrl());

        try {
            $body = Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'JSON encode failed: ' . $e->getMessage()];
        }

        $response = $client->post($this->endpoint, $body);
        if ($response === false) {
            $errors = $client->getError();
            $msg = $errors ? implode('; ', $errors) : 'Unknown transport error';
            return ['success' => false, 'error' => 'Transport error: ' . $msg];
        }

        $status = $client->getStatus();
        if ($status !== 200) {
            return ['success' => false, 'error' => "HTTP $status: " . substr($response, 0, 500)];
        }

        try {
            $parsed = Json::decode($response);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Invalid JSON from API: ' . $e->getMessage()];
        }

        if (empty($parsed['success'])) {
            return ['success' => false, 'error' => $parsed['error'] ?? 'API returned error'];
        }
        $description = $parsed['data']['description'] ?? '';
        $description = trim((string)$description);
        if ($description === '') {
            return ['success' => false, 'error' => 'API returned empty description'];
        }
        $description = TextSanitizer::stripEmoji($description);

        return ['success' => true, 'description' => $description];
    }

    /**
     * Сохраняет описание в b_iblock_section.DESCRIPTION.
     *
     * @return array{success:bool, error?:string}
     */
    public function saveDescription($sectionId, $description)
    {
        $sectionId = (int)$sectionId;
        $description = TextSanitizer::stripEmoji(trim((string)$description));
        if ($sectionId <= 0) return ['success' => false, 'error' => 'Некорректный ID'];
        if ($description === '') return ['success' => false, 'error' => 'Описание пустое'];

        $sec = new \CIBlockSection();
        $ok = $sec->Update($sectionId, [
            'DESCRIPTION' => $description,
            'DESCRIPTION_TYPE' => 'html',
        ]);
        if (!$ok) {
            return ['success' => false, 'error' => $sec->LAST_ERROR ?: 'Ошибка сохранения'];
        }
        return ['success' => true];
    }

    private function getSiteUrl()
    {
        $proto = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $proto . '://' . $host . '/';
    }
}
