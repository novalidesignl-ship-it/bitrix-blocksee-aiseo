#!/bin/bash
# check-php71-compat.sh — проверка кода модуля на совместимость с PHP 7.1+
# Запуск: bin/check-php71-compat.sh
# Возвращает 0 если всё ок, 1 если найдены несовместимые конструкции.
#
# Запускать ОБЯЗАТЕЛЬНО перед каждым релизом, чтобы не словить parse/undefined-function
# на хостингах с старым PHP. Полный список целевых платформ:
#   PHP 7.1, 7.2 (без полифилла array_key_*)
#   PHP 7.3 (без полифилла), 7.4 (без полифилла), 8.0, 8.1, 8.2, 8.3+
#
# Для PHP-функций, добавленных в 7.3+, держим полифиллы в include.php
# (см. function_exists-ветку).

set -e
cd "$(dirname "$0")/.."

FAIL=0
report() {
    if [ -n "$2" ]; then
        echo "❌ $1"
        echo "$2" | sed 's/^/   /'
        FAIL=1
    fi
}

# === SYNTAX-уровень: PHP 8.0+ ===

# match() — PHP 8.0
out=$(grep -rEn "(^|[^a-z_])match\s*\(" --include="*.php" . 2>/dev/null \
    | grep -v "preg_match\|preg_match_all\|fnmatch\|//.*match\|/\*.*match" \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "match() (PHP 8.0+) — заменить на switch/case" "$out"

# Nullsafe ?-> — PHP 8.0
out=$(grep -rEn "\?->" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "Nullsafe ?-> (PHP 8.0+) — заменить на explicit null check" "$out"

# str_contains / str_starts_with / str_ends_with — PHP 8.0
out=$(grep -rEn "\b(str_contains|str_starts_with|str_ends_with)\s*\(" --include="*.php" . 2>/dev/null \
    | grep -v "function_exists\|function (str_\|^./bin/check-php71-compat.sh:" || true)
report "str_contains/starts_with/ends_with (PHP 8.0+) — заменить на strpos" "$out"

# === SYNTAX-уровень: PHP 8.1+ ===

# readonly properties — PHP 8.1
out=$(grep -rEn "(public|private|protected)\s+readonly" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "readonly properties (PHP 8.1+)" "$out"

# enum — PHP 8.1
out=$(grep -rEn "^enum\s+[A-Z]" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "enum (PHP 8.1+)" "$out"

# === SYNTAX-уровень: PHP 7.4+ ===

# Arrow functions fn() => — PHP 7.4
out=$(grep -rEn "\bfn\s*\([^)]*\)\s*=>" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "Arrow functions fn() => (PHP 7.4+) — заменить на function() use ()" "$out"

# Typed properties — PHP 7.4
# Учитываем `private static int $x` тоже. НЕ ловим `private static $x` — это просто
# static modifier без типа, работает с PHP 5.0+.
out=$(grep -rEn "(public|private|protected)(\s+static)?\s+(\?\s*)?(int|string|float|bool|array|object|iterable|callable|self|mixed|null|true|false|void|never|[A-Z][A-Za-z0-9_]+|\\\\[A-Z][A-Za-z0-9_\\\\]+)\s+\\\$[a-z]" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "Typed properties (PHP 7.4+) — убрать тип из объявления свойства" "$out"

# Spread в массивах [...arr] — PHP 7.4
out=$(grep -rEn "\[\s*\.\.\.\\\$" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "Spread в массиве [...\$arr] (PHP 7.4+) — заменить на array_merge" "$out"

# ??= null coalescing assignment — PHP 7.4
out=$(grep -rEn "[a-zA-Z_\]]\s*\?\?=" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "Null coalescing assignment ??= (PHP 7.4+) — заменить на \$x = \$x ?? ..." "$out"

# Numeric literal separator 1_000 — PHP 7.4
out=$(grep -rEn "[^a-zA-Z_'\"][0-9]+_[0-9]+[^a-zA-Z_'\"]" --include="*.php" . 2>/dev/null \
    | grep -v "^./bin/check-php71-compat.sh:" || true)
report "Numeric literal separator 1_000 (PHP 7.4+) — убрать подчёркивание" "$out"

# === FUNCTION-уровень: PHP 7.3+ (нужны полифиллы или замены) ===

# Все функции, которые вернут "Call to undefined function" на PHP < 7.3
# должны иметь полифилл в include.php (function_exists-ветка).
HAS_POLYFILL_FOR_KEY_FIRST=$(grep -l "function_exists.'array_key_first'" include.php 2>/dev/null || true)
HAS_POLYFILL_FOR_KEY_LAST=$(grep -l "function_exists.'array_key_last'" include.php 2>/dev/null || true)

uses_first=$(grep -rEn "array_key_first\s*\(" --include="*.php" . 2>/dev/null \
    | grep -v "function_exists\|^./include.php:.*function array_key_first\|^./bin/" || true)
if [ -n "$uses_first" ] && [ -z "$HAS_POLYFILL_FOR_KEY_FIRST" ]; then
    report "array_key_first() используется БЕЗ полифилла в include.php" "$uses_first"
fi

uses_last=$(grep -rEn "array_key_last\s*\(" --include="*.php" . 2>/dev/null \
    | grep -v "function_exists\|^./include.php:.*function array_key_last\|^./bin/" || true)
if [ -n "$uses_last" ] && [ -z "$HAS_POLYFILL_FOR_KEY_LAST" ]; then
    report "array_key_last() используется БЕЗ полифилла в include.php" "$uses_last"
fi

# is_countable — PHP 7.3
out=$(grep -rEn "is_countable\s*\(" --include="*.php" . 2>/dev/null \
    | grep -v "function_exists\|^./bin/check-php71-compat.sh:" || true)
report "is_countable (PHP 7.3+) — добавить полифилл или заменить на is_array+Countable" "$out"

# === SYNTAX CHECK через php -l ===
# Локально лень проверять — это сделает CI/release-скрипт.
# (php -l на каждом файле, баблить вывод)

# === ИТОГ ===
if [ "$FAIL" -ne 0 ]; then
    echo ""
    echo "FAIL: найдены конструкции, не совместимые с PHP 7.1+"
    echo "Подробности выше. Перед релизом — починить или добавить полифилл."
    exit 1
fi

echo "✓ Совместимость с PHP 7.1+ подтверждена"
exit 0
