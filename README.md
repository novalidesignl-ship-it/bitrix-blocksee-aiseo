# БЛОКСИ: ИИ SEO

Модуль 1С-Битрикс — **ИИ-генерация описаний товаров и отзывов**.

- Поддержка стандартного каталога Битрикс и решений на Аспро (использует модуль `forum` и UF-поля, которые подхватывают нативные шаблоны).
- Единая панель управления: описания + отзывы + настройки.

## Возможности

**Описания товаров**
- Генерация и перегенерация описаний в `DETAIL_TEXT` / `PREVIEW_TEXT` / оба / пользовательское свойство.
- Кастомный промпт, `temperature`, `max_tokens`, creative mode.
- Inline-правка, bulk-генерация по категории/сценарию (*Заполнить пустые* / *Перезаписать все*) с курсорной пагинацией для больших каталогов (тестировалось на 500+ SKU).

**Отзывы товаров**
- Генерация N отзывов на товар через форум-модуль Битрикса (`CForumTopic` + `CForumMessage`).
- Имена авторов, рейтинг 1–5 (хранится в `UF_RATING`), рандомные даты из настраиваемого диапазона.
- Топик на товар с `XML_ID = iblock_{IBLOCK_ID}_{ELEMENT_ID}` — совместимо с типовым каталогом и Аспро.
- Просмотр / редактирование / удаление отзывов в модалке. Точечно по +1 или bulk по всему каталогу/категории.

**Безопасность контента**
- Автоматическая чистка эмодзи (включая `⭐`, `✨`, `❤`, SMP pictographs, flag indicators, variation selectors).
- Схлопывание дублирующих пробелов, чистка невидимых символов и BOM.

## Требования

- 1С-Битрикс ≥ 24.x (тестировано на 26.150).
- Модуль `forum` (стандартный, идёт в комплекте Битрикс).
- PHP ≥ 8.1.
- Домен проекта должен быть добавлен в белый список у вендора API (API проверяет `Referer`).

## Установка

### Вариант A. Через Git (рекомендуется)

```bash
cd /path/to/bitrix-project

# вариант 1: как submodule (если хотите версионирование в основном проекте)
git submodule add git@github.com:novalidesignl-ship-it/bitrix-blocksee-aiseo.git local/modules/blocksee.aiseo

# вариант 2: как обычный клон
git clone git@github.com:novalidesignl-ship-it/bitrix-blocksee-aiseo.git local/modules/blocksee.aiseo
```

Затем в админке Битрикс:

1. **Настройки → Настройки продукта → Модули** (`/bitrix/admin/partner_modules.php`).
2. Найти «БЛОКСИ: ИИ SEO» в списке установленных/неустановленных модулей.
3. Нажать **Установить**.

При установке модуль автоматически:

- Создаёт форум `Отзывы товаров (AI)` и сохраняет его ID в опциях модуля.
- Регистрирует `UF_RATING` (integer 1–5) и `UF_AI_GENERATED` (integer 0/1) на сущности `FORUM_MESSAGE`.
- Копирует admin-stubs в `/bitrix/admin/` (`blocksee_aiseo_list.php`, `blocksee_aiseo_reviews.php`, `blocksee_aiseo_options.php`).
- Устанавливает значения настроек по умолчанию (temperature 0.7, max_tokens 3000 и т.д.).

### Вариант B. Через Composer

В `composer.json` проекта:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:novalidesignl-ship-it/bitrix-blocksee-aiseo.git"
        }
    ],
    "require": {
        "cinar/bitrix-blocksee-aiseo": "^1.0"
    }
}
```

Затем:

```bash
composer require cinar/bitrix-blocksee-aiseo
# Symlink/копия в local/modules/blocksee.aiseo не создастся автоматически —
# добавьте скрипт deploy или используйте Вариант A.
```

> Для чистой Composer-установки прямо в `local/modules/` можно подключить
> [composer/installers](https://github.com/composer/installers) и расширить маппинг,
> но проще держать через `git clone` / `submodule`.

### Вариант C. Ручная установка из архива (для не-разработчиков)

1. Откройте [страницу последнего релиза](https://github.com/novalidesignl-ship-it/bitrix-blocksee-aiseo/releases/latest).
2. В разделе **Assets** скачайте файл вида `blocksee.aiseo-vX.Y.Z.zip` (готовый архив, не `Source code (zip)` — в нём уже правильное имя папки).
3. Распакуйте архив прямо в папку `local/modules/` вашего сайта Битрикс — получится путь `local/modules/blocksee.aiseo/`.
4. В админке: **Настройки → Настройки продукта → Модули** — найдите **«БЛОКСИ: ИИ SEO»** в списке и нажмите **Установить**.

## Конфигурация

После установки: **Сервисы → БЛОКСИ: ИИ SEO → Настройки**.

### Основные параметры

| Параметр | По умолчанию | Описание |
|---|---|---|
| API endpoint | настраивается автоматически | URL вендора зашит в коде модуля. |
| Инфоблок каталога | — | Default iblock для страницы описаний и отзывов. |
| Куда записывать описание | `DETAIL_TEXT` | Или `PREVIEW_TEXT`, оба, или пользовательское свойство. |
| Дополнительный промпт | пусто | Добавляется к базовому системному промпту API. |
| Temperature | `0.7` | Креативность модели (0–2). |
| Max tokens | `3000` | Лимит токенов ответа. |
| Creative mode | `N` | Более свободный стиль генерации. |

### Параметры отзывов

| Параметр | По умолчанию | Описание |
|---|---|---|
| Форум для отзывов | автосоздаётся | Форум, куда пишутся сгенерированные отзывы. |
| Отзывов на товар | `3` | Дефолт для bulk-операции. |
| Min / max слов | `20` / `60` | Диапазон длины отзыва. |
| Средний рейтинг | `5` | 1–5. |
| Автоодобрение | `Y` | `APPROVED='Y'` у сгенерированных сообщений. |
| Случайные даты | `Y` | Даты публикации рандомизируются в диапазоне. |
| С / по | `-2 года` / сегодня | Границы случайного диапазона дат. |
| Дополнительный промпт | пусто | Стиль/тон отзывов. |

### Проверка соединения

На странице настроек есть кнопка **«Проверить соединение»** — шлёт `action=test` и показывает HTTP-статус. Если возвращается `Access denied. Domain not allowed.` — нужно добавить ваш домен в белый список у вендора API (обратитесь к владельцу API).

## Использование

### Описания

**Сервисы → БЛОКСИ: ИИ SEO → Описания товаров**.

- Промпт сверху. Отредактируйте под ваш бренд/стиль и нажмите «Сохранить промпт».
- Тулбар: инфоблок / категория / статус / поиск.
- Точечно: кнопки `Сгенерировать` / `Сохранить` у каждой строки (правый край).
- Массово: `Массовая автоматическая генерация →`. Выбираете категории, сценарий (*Заполнить пустые* / *Перезаписать все*) — прогресс и результат видны на странице.

### Отзывы

**Сервисы → БЛОКСИ: ИИ SEO → Отзывы товаров**.

- Точечно: `+ 1 отзыв` на строке. Нажатие = один новый отзыв. Повторяйте для наращивания.
- Массово: `Массовая генерация отзывов →`. Указываете количество отзывов на товар, категории, сценарий (*Только пустые* / *Добавить к имеющимся*).
- Управление: `Посмотреть` открывает модалку со списком отзывов — там можно редактировать (автор, текст, рейтинг) или удалять.

## Архитектура

```
local/modules/blocksee.aiseo/
├─ admin/                         # Страницы админки
│  ├─ list.php                    # Описания товаров
│  ├─ reviews.php                 # Отзывы товаров
│  ├─ options.php                 # Настройки
│  └─ menu.php                    # Пункт меню «Сервисы»
├─ assets/
│  ├─ admin.css                   # Дизайн-система модуля
│  ├─ admin.js                    # JS для описаний
│  └─ reviews.js                  # JS для отзывов
├─ install/
│  ├─ index.php                   # Класс `blocksee_aiseo extends CModule`
│  ├─ version.php                 # VERSION / VERSION_DATE
│  └─ admin/                      # Stubs для /bitrix/admin/
├─ lang/ru/…                      # Локализация
├─ lib/
│  ├─ apiclient.php               # HTTP-клиент к вендорскому API (Referer-aware)
│  ├─ generator.php               # Логика описаний
│  ├─ reviewsgenerator.php        # Логика отзывов (форум + UF)
│  ├─ options.php                 # Helpers над Config\Option
│  ├─ textsanitizer.php           # Чистка эмодзи и невидимых символов
│  └─ controller/
│     ├─ generator.php            # D7 Controller: описания
│     └─ reviews.php              # D7 Controller: отзывы
├─ include.php                    # Loader::registerAutoLoadClasses
├─ .settings.php                  # controllers.defaultNamespace
├─ composer.json
├─ CHANGELOG.md
├─ LICENSE
└─ README.md
```

### Эндпоинты AJAX

- `POST /bitrix/services/main/ajax.php?action=blocksee:aiseo.generator.{method}`
  - `generate`, `save`, `generateAndSave`, `list`, `listNextChunk`, `ping`, `savePrompt`
- `POST /bitrix/services/main/ajax.php?action=blocksee:aiseo.reviews.{method}`
  - `generate`, `generateAndSave`, `list`, `update`, `delete`, `listNextChunk`, `savePrompt`

Все требуют `sessid` (CSRF) и проверяют `$USER->IsAdmin()`.

## Обновление модуля

### Через git clone

```bash
cd /path/to/bitrix-project/local/modules/blocksee.aiseo
git pull
```

После pull — в админке **Настройки → Модули** переустанавливать не нужно, если мажорная версия не поменялась. Но всегда полезно почистить кеш:

```
/bitrix/admin/cache_dependencies.php?clear_cache=Y
```

Если релиз ломающий (major version bump) — описано в CHANGELOG.

### Через git submodule

```bash
cd /path/to/bitrix-project
git submodule update --remote local/modules/blocksee.aiseo
git add local/modules/blocksee.aiseo
git commit -m "Update blocksee.aiseo to latest"
```

## Удаление

Админка → **Модули** → рядом с «БЛОКСИ: ИИ SEO» → **Удалить**.

- Stubs из `/bitrix/admin/` удаляются.
- Форум отзывов и UF-поля **сохраняются** (данные отзывов остаются в `b_forum_topic` / `b_forum_message`).
- Опции модуля удаляются стандартным механизмом `ModuleManager::unRegisterModule`.

Если нужно полностью вычистить данные — удалите форум вручную через админку форумов.

## Разработка

Ветка разработки: `main`. Теги релизов: `v1.0.0`, `v1.1.0`, ...

Склонируйте репо в `local/modules/blocksee.aiseo` вашего Битрикс-проекта, изменяйте — все изменения увидите сразу в админке. Не забудьте чистить кеш после правок PHP-файлов (`/bitrix/cache/`, `/bitrix/managed_cache/`).

### Стандарт стиля

- PHP 8.1+, strict types нет (из-за совместимости со старым Bitrix-кодом).
- Namespace `Blocksee\Aiseo\*`.
- D7 API предпочтительнее классического (`CIBlockElement` и пр. — только где у D7 нет прямого аналога).

## Лицензия

Proprietary. См. [LICENSE](LICENSE).

## Поддержка

- Issues: https://github.com/novalidesignl-ship-it/bitrix-blocksee-aiseo/issues
