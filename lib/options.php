<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Config\Option;

class Options
{
    public const MODULE_ID = 'blocksee.aiseo';

    public const REVIEWS_SOURCE_AUTO = 'auto';
    public const REVIEWS_SOURCE_FORUM = 'forum';
    public const REVIEWS_SOURCE_BLOG = 'blog';
    public const REVIEWS_SOURCE_IBLOCK = 'iblock';
    public const REVIEWS_SOURCE_CUSTOM = 'custom';
    public const REVIEWS_BLOG_URL_DEFAULT = 'catalog_comments';

    public const REVIEWS_CUSTOM_DIRECTION_FORWARD = 'forward'; // E-property на товаре указывает на отзывы (Aspro Max)
    public const REVIEWS_CUSTOM_DIRECTION_REVERSE = 'reverse'; // E-property на отзыве указывает на товар (tsar-climat и т.п.)
    public const REVIEWS_CUSTOM_TARGET_DETAIL = 'DETAIL_TEXT';
    public const REVIEWS_CUSTOM_TARGET_PREVIEW = 'PREVIEW_TEXT';
    public const REVIEWS_CUSTOM_TARGET_PROPERTY_PREFIX = 'PROPERTY:';

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
    /**
     * С v1.6.0 quality-tier'ы убраны из UI и сервер игнорирует это поле — всегда
     * используется DS Pro + журналистский промпт. Метод возвращает 'high' константно
     * для обратной совместимости (в payload поле остаётся, чтобы не ломать схему).
     */
    public static function getQualityTier(): string
    {
        return 'high';
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

    /**
     * Возвращает URL-префикс модуля (/local/modules/blocksee.aiseo или
     * /bitrix/modules/blocksee.aiseo) в зависимости от того, куда модуль реально
     * установлен. Нужно для корректной отдачи CSS/JS на хостингах разных типов.
     *
     * Кешируется в статике — определяется один раз за процесс.
     */
    public static function getModuleUrlPrefix(): string
    {
        static $prefix = null;
        if ($prefix !== null) return $prefix;
        $docRoot = (string)$_SERVER['DOCUMENT_ROOT'];
        // Сначала проверяем local/ (приоритет для dev/локальных установок),
        // потом bitrix/modules/ (стандартное место Marketplace).
        if ($docRoot !== '' && file_exists($docRoot . '/local/modules/blocksee.aiseo/include.php')) {
            $prefix = '/local/modules/blocksee.aiseo';
        } else {
            $prefix = '/bitrix/modules/blocksee.aiseo';
        }
        return $prefix;
    }

    /**
     * Полный URL до файла внутри модуля (относительно DOCUMENT_ROOT).
     * Пример: Options::getAssetUrl('/assets/admin.css') →
     *   '/local/modules/blocksee.aiseo/assets/admin.css' либо
     *   '/bitrix/modules/blocksee.aiseo/assets/admin.css'
     */
    public static function getAssetUrl(string $relPath): string
    {
        $rel = '/' . ltrim($relPath, '/');
        return self::getModuleUrlPrefix() . $rel;
    }

    public static function buildElementEditUrl(int $iblockId, int $elementId, string $lang = 'ru'): string
    {
        $type = self::getIblockTypeId($iblockId) ?: 'catalog';
        return "/bitrix/admin/iblock_element_edit.php?IBLOCK_ID={$iblockId}&type=" . urlencode($type) . "&ID={$elementId}&lang=" . urlencode($lang);
    }

    public static function getReviewsSource(): string
    {
        $val = (string)self::get('reviews_source', self::REVIEWS_SOURCE_AUTO);
        $allowed = [
            self::REVIEWS_SOURCE_FORUM,
            self::REVIEWS_SOURCE_BLOG,
            self::REVIEWS_SOURCE_IBLOCK,
            self::REVIEWS_SOURCE_CUSTOM,
        ];
        return in_array($val, $allowed, true) ? $val : self::REVIEWS_SOURCE_AUTO;
    }

    /* ==================== Custom reviews backend (v1.10.0+) ==================== */

    public static function getReviewsCustomIblockId(): int
    {
        return (int)self::get('reviews_custom_iblock', 0);
    }

    /**
     * Направление связи товар↔отзыв:
     *   'forward' — E-свойство НА ТОВАРЕ хранит ID отзыва(ов). Так у Aspro Max
     *               (PRODUCT_REVIEWS) — multiple свойство.
     *   'reverse' — E-свойство НА ОТЗЫВЕ хранит ID товара. Так у самописных схем
     *               (tsar-climat: свойство PRODUCT в инфоблоке отзывов).
     */
    public static function getReviewsCustomLinkDirection(): string
    {
        $val = (string)self::get('reviews_custom_link_direction', self::REVIEWS_CUSTOM_DIRECTION_REVERSE);
        $allowed = [self::REVIEWS_CUSTOM_DIRECTION_FORWARD, self::REVIEWS_CUSTOM_DIRECTION_REVERSE];
        return in_array($val, $allowed, true) ? $val : self::REVIEWS_CUSTOM_DIRECTION_REVERSE;
    }

    public static function getReviewsCustomLinkProp(): string
    {
        return trim((string)self::get('reviews_custom_link_prop', ''));
    }

    public static function getReviewsCustomRatingProp(): string
    {
        return trim((string)self::get('reviews_custom_rating_prop', ''));
    }

    public static function getReviewsCustomAuthorProp(): string
    {
        return trim((string)self::get('reviews_custom_author_prop', ''));
    }

    /**
     * Куда пишется тело отзыва: 'DETAIL_TEXT' / 'PREVIEW_TEXT' / 'PROPERTY:CODE'.
     * По умолчанию DETAIL_TEXT (универсальный вариант под большинство тем).
     */
    public static function getReviewsCustomContentTarget(): string
    {
        $val = trim((string)self::get('reviews_custom_content_target', self::REVIEWS_CUSTOM_TARGET_DETAIL));
        return $val !== '' ? $val : self::REVIEWS_CUSTOM_TARGET_DETAIL;
    }

    /**
     * 'Y' — новый отзыв создаётся с ACTIVE='Y' (сразу виден на сайте).
     * 'N' — ACTIVE='N' (отправляется на модерацию). По умолчанию Y, потому
     *        что AI-сгенерированные отзывы делает сам админ — модерация излишня.
     */
    public static function getReviewsCustomActiveDefault(): string
    {
        return self::get('reviews_custom_active_default', 'Y') === 'N' ? 'N' : 'Y';
    }

    /**
     * Раздельная генерация «достоинства»/«недостатки» как структурированных полей.
     * Когда включено: AI просят вернуть `{author_name, content, plusses, minuses, rating}`
     * вместо обычного `{author_name, content, rating}`. CustomBackend пишет
     * plusses/minuses в свойства инфоблока с подходящими кодами (PLUSSES/PROS,
     * MINUSES/CONS). Минусы AI делает мизерными — «не заметил», «ничего не
     * могу сказать», или косвенное наблюдение, без претензий.
     */
    public static function getReviewsCustomSplitProsCons(): bool
    {
        return self::get('reviews_custom_split_pros_cons', 'N') === 'Y';
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
        if ($configured === self::REVIEWS_SOURCE_CUSTOM) {
            // custom backend: проверяем что хотя бы iblock задан. Без этого
            // backend упадёт на первом запросе — лучше fallback на пусто и
            // явно отрисуем предупреждение в UI.
            return self::getReviewsCustomIblockId() > 0 ? self::REVIEWS_SOURCE_CUSTOM : '';
        }
        if ($configured === self::REVIEWS_SOURCE_IBLOCK) {
            // iblock backend всегда доступен пока активен модуль iblock —
            // структуру он сам детектит на конкретном товарном инфоблоке.
            return self::REVIEWS_SOURCE_IBLOCK;
        }
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
        // auto: приоритет blog > forum. Iblock backend в auto не выбираем —
        // он специфичен под Aspro Max и подобные сборки, требует явного
        // выбора в настройках, чтобы не сюрпризить других клиентов.
        if (\Bitrix\Main\ModuleManager::isModuleInstalled('blog')) {
            return self::REVIEWS_SOURCE_BLOG;
        }
        if (\Bitrix\Main\ModuleManager::isModuleInstalled('forum')) {
            return self::REVIEWS_SOURCE_FORUM;
        }
        return '';
    }
}
