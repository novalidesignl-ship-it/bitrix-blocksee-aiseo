<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\IblockTable;

class Generator
{
    private ApiClient $apiClient;

    public function __construct(?ApiClient $apiClient = null)
    {
        Loader::includeModule('iblock');
        if (Loader::includeModule('catalog')) {
            // catalog optional — used for price lookup
        }
        $this->apiClient = $apiClient ?? new ApiClient();
    }

    /**
     * Generate description for one element, return result payload (does not save).
     *
     * @return array{success:bool, description?:string, error?:string}
     */
    public function generateForElement(int $elementId): array
    {
        $productData = $this->collectProductData($elementId);
        if ($productData === null) {
            return ['success' => false, 'error' => 'Элемент не найден'];
        }

        return $this->apiClient->generateProductDescription(
            $productData,
            Options::getCustomPrompt(),
            Options::getGenerationSettings()
        );
    }

    /**
     * Save description into the configured target field of the element.
     */
    public function saveDescription(int $elementId, string $description): array
    {
        $description = TextSanitizer::stripEmoji(trim($description));
        if ($description === '') {
            return ['success' => false, 'error' => 'Описание пустое'];
        }

        $targetField = Options::getTargetField();
        $iblockElement = new \CIBlockElement();

        if ($targetField === 'PROPERTY') {
            $propCode = Options::getTargetPropertyCode();
            if ($propCode === '') {
                return ['success' => false, 'error' => 'Код свойства не задан в настройках'];
            }
            $element = \CIBlockElement::GetByID($elementId)->Fetch();
            if (!$element) {
                return ['success' => false, 'error' => 'Элемент не найден'];
            }
            \CIBlockElement::SetPropertyValuesEx($elementId, $element['IBLOCK_ID'], [$propCode => $description]);
        } else {
            $fields = [];
            if ($targetField === 'DETAIL_TEXT' || $targetField === 'BOTH') {
                $fields['DETAIL_TEXT'] = $description;
                $fields['DETAIL_TEXT_TYPE'] = 'html';
            }
            if ($targetField === 'PREVIEW_TEXT' || $targetField === 'BOTH') {
                $preview = $this->extractFirstParagraph($description);
                $fields['PREVIEW_TEXT'] = $preview;
                $fields['PREVIEW_TEXT_TYPE'] = 'html';
            }
            if (empty($fields)) {
                $fields['DETAIL_TEXT'] = $description;
                $fields['DETAIL_TEXT_TYPE'] = 'html';
            }
            $ok = $iblockElement->Update($elementId, $fields);
            if (!$ok) {
                return ['success' => false, 'error' => $iblockElement->LAST_ERROR ?: 'Ошибка сохранения'];
            }
        }

        return ['success' => true];
    }

    /**
     * Gather product fields, properties, price, categories.
     *
     * @return array<string,mixed>|null
     */
    public function collectProductData(int $elementId): ?array
    {
        $element = ElementTable::getList([
            'filter' => ['=ID' => $elementId],
            'select' => ['ID', 'NAME', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'IBLOCK_ID', 'XML_ID', 'CODE'],
            'limit' => 1,
        ])->fetch();

        if (!$element) {
            return null;
        }

        $data = [
            'id' => (int)$element['ID'],
            'name' => (string)$element['NAME'],
            'short_description' => (string)$element['PREVIEW_TEXT'],
            'current_description' => (string)$element['DETAIL_TEXT'],
            'sku' => (string)($element['XML_ID'] ?: $element['CODE']),
            'categories' => $this->getElementSections((int)$element['ID'], (int)$element['IBLOCK_ID']),
            'attributes' => $this->getElementProperties((int)$element['ID'], (int)$element['IBLOCK_ID']),
        ];

        [$data['price'], $data['sale_price'], $data['regular_price']] = $this->getElementPrice((int)$element['ID']);

        return $data;
    }

    /**
     * @return string[]
     */
    private function getElementSections(int $elementId, int $iblockId): array
    {
        $names = [];
        $rs = \CIBlockElement::GetElementGroups($elementId, true, ['ID', 'NAME']);
        while ($row = $rs->Fetch()) {
            $names[] = (string)$row['NAME'];
        }
        return $names;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function getElementProperties(int $elementId, int $iblockId): array
    {
        $attributes = [];
        $rs = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ACTIVE' => 'Y']);
        while ($prop = $rs->Fetch()) {
            if (empty($prop['NAME']) || $prop['VALUE'] === null || $prop['VALUE'] === '' || $prop['VALUE'] === false) {
                continue;
            }
            $propName = (string)$prop['NAME'];
            $value = $this->formatPropertyValue($prop);
            if ($value === '' || $value === null) {
                continue;
            }
            if (!isset($attributes[$propName])) {
                $attributes[$propName] = [];
            }
            if (is_array($value)) {
                $attributes[$propName] = array_merge($attributes[$propName], $value);
            } else {
                $attributes[$propName][] = $value;
            }
        }
        // dedup & trim
        foreach ($attributes as $name => $values) {
            $attributes[$name] = array_values(array_filter(array_unique(array_map('strval', $values)), 'strlen'));
            if (empty($attributes[$name])) {
                unset($attributes[$name]);
            }
        }
        return $attributes;
    }

    /**
     * @return string|array<int,string>|null
     */
    private function formatPropertyValue(array $prop)
    {
        $type = $prop['PROPERTY_TYPE'] ?? 'S';
        $value = $prop['VALUE'];

        if ($type === 'L') {
            return (string)($prop['VALUE_ENUM'] ?? $value);
        }
        if ($type === 'E') {
            $sub = \CIBlockElement::GetByID((int)$value)->Fetch();
            return $sub ? (string)$sub['NAME'] : null;
        }
        if ($type === 'G') {
            $sub = \CIBlockSection::GetByID((int)$value)->Fetch();
            return $sub ? (string)$sub['NAME'] : null;
        }
        if ($type === 'F') {
            return null;
        }
        if (is_array($value)) {
            return array_map('strval', $value);
        }
        return (string)$value;
    }

    /**
     * @return array{0: float, 1: float|null, 2: float|null} [price, sale, regular]
     */
    private function getElementPrice(int $elementId): array
    {
        if (!Loader::includeModule('catalog')) {
            return [0.0, null, null];
        }
        $price = \CPrice::GetBasePrice($elementId);
        if (!$price) {
            return [0.0, null, null];
        }
        $main = (float)$price['PRICE'];
        $discount = isset($price['DISCOUNT_PRICE']) ? (float)$price['DISCOUNT_PRICE'] : null;
        return [$main, $discount, $main];
    }

    private function extractFirstParagraph(string $html): string
    {
        $plain = trim(strip_tags($html));
        if ($plain === '') {
            return '';
        }
        $parts = preg_split('/\r\n|\r|\n/u', $plain);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                return $part;
            }
        }
        return $plain;
    }

    public function getElementCurrentDescription(int $elementId): string
    {
        $el = ElementTable::getList([
            'filter' => ['=ID' => $elementId],
            'select' => ['DETAIL_TEXT', 'PREVIEW_TEXT'],
            'limit' => 1,
        ])->fetch();
        if (!$el) {
            return '';
        }
        $target = Options::getTargetField();
        if ($target === 'PREVIEW_TEXT') {
            return (string)$el['PREVIEW_TEXT'];
        }
        return (string)($el['DETAIL_TEXT'] ?: $el['PREVIEW_TEXT']);
    }
}
