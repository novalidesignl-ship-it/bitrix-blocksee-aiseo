<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Config\Option;

class Options
{
    public const MODULE_ID = 'blocksee.aiseo';

    public static function get(string $key, $default = '')
    {
        return Option::get(self::MODULE_ID, $key, $default);
    }

    public static function set(string $key, $value): void
    {
        Option::set(self::MODULE_ID, $key, (string)$value);
    }

    public static function getApiEndpoint(): string
    {
        $endpoint = trim((string)self::get('api_endpoint', ''));
        return $endpoint !== '' ? $endpoint : self::defaultEndpoint();
    }

    /**
     * Default vendor endpoint is kept base64-encoded to avoid casual disclosure
     * when source trees are scanned or shared.
     */
    private static function defaultEndpoint(): string
    {
        return base64_decode('aHR0cHM6Ly9say5ibG9ja3NlZS5ydS9hcGkucGhw');
    }

    public static function getTargetField(): string
    {
        return (string)self::get('target_field', 'DETAIL_TEXT');
    }

    public static function getTargetPropertyCode(): string
    {
        return (string)self::get('target_property_code', '');
    }

    public static function getIblockId(): int
    {
        return (int)self::get('iblock_id', 0);
    }

    public static function getCustomPrompt(): string
    {
        return (string)self::get('custom_prompt', '');
    }

    public static function getGenerationSettings(): array
    {
        return [
            'temperature' => (float)self::get('temperature', '0.7'),
            'max_tokens' => (int)self::get('max_tokens', '3000'),
            'creative_mode' => self::get('creative_mode', 'N') === 'Y',
        ];
    }

    public static function getReviewsForumId(): int
    {
        return (int)self::get('reviews_forum_id', 0);
    }

    public static function getReviewsPerProduct(): int
    {
        return max(1, min(50, (int)self::get('reviews_per_product', '3')));
    }

    public static function getReviewsSettings(): array
    {
        return [
            'min_words' => max(10, (int)self::get('reviews_min_words', '20')),
            'max_words' => max(10, (int)self::get('reviews_max_words', '60')),
            'rating' => max(1, min(5, (int)self::get('reviews_default_rating', '5'))),
            'custom_prompt' => (string)self::get('reviews_custom_prompt', ''),
            'temperature' => (float)self::get('temperature', '0.7'),
            'creative_mode' => self::get('creative_mode', 'N') === 'Y',
        ];
    }

    public static function getReviewsAutoApprove(): bool
    {
        return self::get('reviews_auto_approve', 'Y') === 'Y';
    }

    public static function getReviewsDateRangeEnabled(): bool
    {
        return self::get('reviews_date_range_enabled', 'Y') === 'Y';
    }

    public static function getReviewsDateFrom(): string
    {
        return (string)self::get('reviews_date_from', date('Y-m-d', strtotime('-2 years')));
    }

    public static function getReviewsDateTo(): string
    {
        return (string)self::get('reviews_date_to', date('Y-m-d'));
    }

    /**
     * Find catalog-like infoblocks across stock Bitrix, Aspro (Premier, Next,
     * Allcorp, Maximum, Priority…) and any custom themes. Returns an ordered
     * associative array: [IBLOCK_ID => "Name [ID]"].
     *
     * Resolution order (first non-empty wins):
     *   1. Infoblocks registered in `b_catalog_iblock` (catalog module) —
     *      the authoritative source for storefront catalogs.
     *   2. Infoblocks with `TYPE = 'catalog'` — stock Bitrix convention.
     *   3. Infoblocks whose TYPE contains "catalog" (case-insensitive) —
     *      matches Aspro solutions and custom naming.
     *   4. Any active infoblock — last-resort fallback.
     */
    public static function getCatalogIblocks(): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return [];
        }

        $out = [];

        // Step 1 — registered catalog iblocks
        if (\Bitrix\Main\Loader::includeModule('catalog') && class_exists('\Bitrix\Catalog\CatalogIblockTable')) {
            try {
                $ids = [];
                $rs = \Bitrix\Catalog\CatalogIblockTable::getList(['select' => ['IBLOCK_ID']]);
                while ($r = $rs->fetch()) {
                    $ids[(int)$r['IBLOCK_ID']] = true;
                }
                if ($ids) {
                    $rsIb = \CIBlock::GetList(['SORT' => 'ASC'], ['ID' => array_keys($ids), 'ACTIVE' => 'Y']);
                    while ($ib = $rsIb->Fetch()) {
                        $out[(int)$ib['ID']] = $ib['NAME'] . ' [' . $ib['ID'] . ']';
                    }
                }
            } catch (\Throwable $t) {
                // fall through to next resolver
            }
        }
        if ($out) return $out;

        // Step 2 — stock TYPE=catalog
        $rs = \CIBlock::GetList(['SORT' => 'ASC'], ['TYPE' => 'catalog', 'ACTIVE' => 'Y']);
        while ($ib = $rs->Fetch()) {
            $out[(int)$ib['ID']] = $ib['NAME'] . ' [' . $ib['ID'] . ']';
        }
        if ($out) return $out;

        // Step 3 — TYPE contains "catalog" (Aspro, Intec, Sotbit, custom…)
        $rs = \CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
        while ($ib = $rs->Fetch()) {
            $type = mb_strtolower((string)$ib['IBLOCK_TYPE_ID']);
            if ($type !== '' && (
                $type === 'catalog'
                || mb_strpos($type, 'catalog') !== false
                || mb_strpos($type, 'каталог') !== false
                || in_array($type, ['shop', 'store', 'goods', 'products', 'eshop'], true)
            )) {
                $out[(int)$ib['ID']] = $ib['NAME'] . ' [' . $ib['ID'] . '] · ' . $ib['IBLOCK_TYPE_ID'];
            }
        }
        if ($out) return $out;

        // Step 4 — any active infoblock (last-resort so admin sees something)
        $rs = \CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
        while ($ib = $rs->Fetch()) {
            $out[(int)$ib['ID']] = $ib['NAME'] . ' [' . $ib['ID'] . '] · ' . $ib['IBLOCK_TYPE_ID'];
        }
        return $out;
    }

    /**
     * Resolve IBLOCK_TYPE_ID for a given iblock (cached statically per request).
     * Needed to build correct links to /bitrix/admin/iblock_element_edit.php on
     * solutions with non-standard types (Aspro Premier/Next/Allcorp, Intec, etc.).
     */
    public static function getIblockTypeId(int $iblockId): string
    {
        static $cache = [];
        if ($iblockId <= 0) return 'catalog';
        if (array_key_exists($iblockId, $cache)) return $cache[$iblockId];
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return $cache[$iblockId] = 'catalog';
        }
        $row = \CIBlock::GetByID($iblockId)->Fetch();
        return $cache[$iblockId] = ($row && !empty($row['IBLOCK_TYPE_ID']))
            ? (string)$row['IBLOCK_TYPE_ID']
            : 'catalog';
    }

    /**
     * Build a link to the iblock element edit page in admin, with correct type= param.
     */
    public static function buildElementEditUrl(int $iblockId, int $elementId, string $lang = 'ru'): string
    {
        $type = rawurlencode(self::getIblockTypeId($iblockId));
        return "/bitrix/admin/iblock_element_edit.php?IBLOCK_ID={$iblockId}&type={$type}&ID={$elementId}&lang={$lang}";
    }
}
