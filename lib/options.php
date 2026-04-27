<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Config\Option;

class Options
{
    public const MODULE_ID = 'blocksee.aiseo';

    public const REVIEWS_SOURCE_AUTO = 'auto';
    public const REVIEWS_SOURCE_FORUM = 'forum';
    public const REVIEWS_SOURCE_BLOG = 'blog';
    public const REVIEWS_BLOG_URL_DEFAULT = 'catalog_comments';

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
        $endpoint = trim((string)self::get('api_endpoint', 'https://lk.blocksee.ru/api.php'));
        return $endpoint !== '' ? $endpoint : 'https://lk.blocksee.ru/api.php';
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
            'quality' => self::getQualityTier(),
        ];
    }

    /**
     * Качество генерации: 'standard' (по умолчанию, GPT-роутер на стороне API)
     * или 'high' (Claude Sonnet или другая премиум-модель — зависит от роутера API).
     * Параметр прокидывается в payload как `settings.quality`. Если API его не
     * поддерживает — ничего не ломается, просто игнорируется.
     */
    public static function getQualityTier(): string
    {
        $val = (string)self::get('quality_tier', 'standard');
        return $val === 'high' ? 'high' : 'standard';
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
            'quality' => self::getQualityTier(),
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
     * Список инфоблоков-каталогов из модуля catalog (без SKU).
     * Фолбек: все активные инфоблоки, если catalog недоступен или каталоги не зарегистрированы.
     * @return array<int, string> [IBLOCK_ID => "Название [ID]"]
     */
    public static function getCatalogIblocks(): array
    {
        $iblocks = [];
        if (\Bitrix\Main\Loader::includeModule('iblock')) {
            $ids = [];
            if (\Bitrix\Main\Loader::includeModule('catalog')) {
                $rs = \Bitrix\Catalog\CatalogIblockTable::getList([
                    'select' => ['IBLOCK_ID'],
                    'filter' => ['=PRODUCT_IBLOCK_ID' => 0],
                ]);
                while ($r = $rs->fetch()) {
                    $ids[] = (int)$r['IBLOCK_ID'];
                }
            }
            $filter = ['ACTIVE' => 'Y'];
            if ($ids) {
                $filter['ID'] = $ids;
            }
            $rsIb = \CIBlock::GetList(['SORT' => 'ASC'], $filter);
            while ($ib = $rsIb->Fetch()) {
                $iblocks[(int)$ib['ID']] = $ib['NAME'] . ' [' . $ib['ID'] . ']';
            }
        }
        return $iblocks;
    }

    /** @return int[] */
    public static function getCatalogIblockIds(): array
    {
        return array_keys(self::getCatalogIblocks());
    }

    public static function getIblockTypeId(int $iblockId): string
    {
        static $cache = [];
        if (!isset($cache[$iblockId])) {
            $cache[$iblockId] = '';
            if (\Bitrix\Main\Loader::includeModule('iblock')) {
                $row = \CIBlock::GetByID($iblockId)->Fetch();
                if ($row) {
                    $cache[$iblockId] = (string)$row['IBLOCK_TYPE_ID'];
                }
            }
        }
        return $cache[$iblockId];
    }

    public static function getReviewsSource(): string
    {
        $val = (string)self::get('reviews_source', self::REVIEWS_SOURCE_AUTO);
        return in_array($val, [self::REVIEWS_SOURCE_FORUM, self::REVIEWS_SOURCE_BLOG], true)
            ? $val
            : self::REVIEWS_SOURCE_AUTO;
    }

    public static function getReviewsBlogUrl(): string
    {
        $url = trim((string)self::get('reviews_blog_url', self::REVIEWS_BLOG_URL_DEFAULT));
        return $url !== '' ? $url : self::REVIEWS_BLOG_URL_DEFAULT;
    }

    public static function getReviewsBlogId(): int
    {
        return (int)self::get('reviews_blog_id', 0);
    }

    /**
     * Возвращает реально используемый источник: 'forum' | 'blog' | '' (нет).
     * Если выбран auto: blog при наличии блога (или модуля blog), иначе forum.
     */
    public static function resolveReviewsSource(): string
    {
        $configured = self::getReviewsSource();
        if ($configured === self::REVIEWS_SOURCE_FORUM) {
            return \Bitrix\Main\ModuleManager::isModuleInstalled('forum')
                ? self::REVIEWS_SOURCE_FORUM
                : '';
        }
        if ($configured === self::REVIEWS_SOURCE_BLOG) {
            return \Bitrix\Main\ModuleManager::isModuleInstalled('blog')
                ? self::REVIEWS_SOURCE_BLOG
                : '';
        }
        // auto
        if (\Bitrix\Main\ModuleManager::isModuleInstalled('blog')) {
            return self::REVIEWS_SOURCE_BLOG;
        }
        if (\Bitrix\Main\ModuleManager::isModuleInstalled('forum')) {
            return self::REVIEWS_SOURCE_FORUM;
        }
        return '';
    }
}
