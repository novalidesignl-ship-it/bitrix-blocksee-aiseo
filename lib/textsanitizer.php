<?php

namespace Blocksee\Aiseo;

class TextSanitizer
{
    /**
     * Remove emoji, pictographs, regional indicators, variation selectors and ZWJ
     * from generated text. Whitespace left behind is collapsed.
     */
    public static function stripEmoji(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Supplementary Multilingual Plane pictographs, emoticons, symbols-pictographs
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text);
        // Miscellaneous Technical (⌛, ⏰, ⏳, ⏩ and friends)
        $text = preg_replace('/[\x{2300}-\x{23FF}]/u', '', $text);
        // Geometric Shapes (▪ ▫ ◾ ◽ ⬛ etc.)
        $text = preg_replace('/[\x{25A0}-\x{25FF}]/u', '', $text);
        // Miscellaneous Symbols + Dingbats (✨ ✅ ❤ ⭐... the 2600-27BF range)
        $text = preg_replace('/[\x{2600}-\x{27BF}]/u', '', $text);
        // Miscellaneous Symbols and Arrows (includes ⭐ U+2B50, ⬆ ⬇ ⬛ ⬜)
        $text = preg_replace('/[\x{2B00}-\x{2BFF}]/u', '', $text);
        // Regional Indicator Symbols (used in flag emoji)
        $text = preg_replace('/[\x{1F1E6}-\x{1F1FF}]/u', '', $text);
        // Variation selectors, ZWJ, keycap combining, line/paragraph separators
        $text = preg_replace('/[\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{2028}\x{2029}]/u', '', $text);
        // Invisible formatting / BOM / zero-width
        $text = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]/u', '', $text);

        // Cleanup residual whitespace produced by stripping
        $text = preg_replace('/(<li[^>]*>)\s+/u', '$1', $text);
        $text = preg_replace('/\s+(<\/li>)/u', '$1', $text);
        $text = preg_replace('/(<p[^>]*>)\s+/u', '$1', $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text);

        return $text;
    }

    /**
     * Оборачивает «голые» абзацы текста в <p>...</p>. Идемпотентно: если контент
     * уже содержит <p>-теги, возвращается без изменений.
     *
     * Используется для пост-обработки AI-ответов, где модель часто возвращает
     * вид «<h2>...</h2>\n\nабзац\n\nабзац» — без оборачивания обычного текста
     * в параграфы. На фронте Aspro Premier (и многих других шаблонах) такой
     * текст склеивается в одну строку, потому что переносы строк не превращаются
     * в визуальные разрывы. Эта функция нормализует вывод.
     *
     * Алгоритм:
     *   1. Нормализуем переносы (\r\n → \n, <br> → \n).
     *   2. Разбиваем текст на блоки по «\n\n» (пустая строка = граница абзаца).
     *   3. Блоки, которые сами являются HTML-блочным элементом (h1-h6, ul, ol,
     *      table, blockquote, figure, pre, p, div), оставляем как есть.
     *   4. Остальные блоки заворачиваем в <p>...</p>, одиночные \n внутри блока
     *      превращаются в <br> (мягкий перенос строки).
     */
    public static function wrapParagraphs(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Если уже есть <p> — считаем что вёрстка структурированная, не трогаем
        if (preg_match('/<p[\s>]/i', $html)) {
            return $html;
        }

        // Нормализуем переносы и убираем мусорные <br>, чтобы они не мешали разбивке
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Делим на блоки по пустой строке. Блочные теги типа <h2>...</h2> часто
        // приходят на отдельной строке, и пустой строки до/после них может не
        // быть — поэтому дополнительно вырезаем их в свои блоки.
        $html = preg_replace(
            '~(<(?:h[1-6]|ul|ol|table|blockquote|figure|pre|div)\b[^>]*>.*?</(?:h[1-6]|ul|ol|table|blockquote|figure|pre|div)>)~is',
            "\n\n$1\n\n",
            $html
        );

        $blocks = preg_split('/\n\s*\n/', $html);
        $out = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            // Блок — самостоятельный блочный HTML-тег
            if (preg_match('~^<(h[1-6]|ul|ol|table|blockquote|figure|pre|div|p)\b~i', $block)) {
                $out[] = $block;
                continue;
            }
            // Обычный текстовый блок — обернуть в <p>, одиночные \n → <br>
            $block = preg_replace('/\n+/', '<br>', $block);
            $out[] = '<p>' . $block . '</p>';
        }

        return implode("\n", $out);
    }
}
