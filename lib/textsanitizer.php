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
}
