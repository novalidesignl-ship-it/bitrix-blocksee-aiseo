<?php

namespace Blocksee\Aiseo\Reviews;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Blocksee\Aiseo\Options;
use Blocksee\Aiseo\TextSanitizer;

/**
 * Хранит отзывы как blog-комментарии (совместимо с bitrix:catalog.comments + BLOG_USE=Y,
 * которое использует Aspro Premier и другие похожие шаблоны).
 *
 * Ключевые артефакты:
 *  - блог с URL «catalog_comments» (один на сайт);
 *  - свойства товара BLOG_POST_ID (ID поста-контейнера) и BLOG_COMMENTS_COUNT (счётчик);
 *  - UF-поля b_blog_comment: UF_ASPRO_COM_RATING (integer), UF_ASPRO_COM_APPROVE (boolean),
 *    UF_AI_GENERATED (integer) — наша пометка, что отзыв сгенерирован.
 */
class BlogBackend implements Backend
{
    public function isAvailable(): bool
    {
        return ModuleManager::isModuleInstalled('blog')
            && Loader::includeModule('blog')
            && Loader::includeModule('iblock');
    }

    public function setupForIblock(int $iblockId): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Модуль blog недоступен'];
        }
        $blogId = $this->ensureBlog();
        if ($blogId <= 0) {
            return ['success' => false, 'error' => 'Не удалось создать/найти блог отзывов'];
        }
        $this->ensureCommentUserFields();
        if ($iblockId > 0) {
            [$propPostId, $propCountId] = $this->ensureIblockProperties($iblockId);
            if ($propPostId <= 0 || $propCountId <= 0) {
                return ['success' => false, 'error' => 'Не удалось создать свойства инфоблока для блог-отзывов'];
            }
        }
        return ['success' => true, 'blog_id' => $blogId];
    }

    public function count(int $elementId): int
    {
        if (!$this->isAvailable()) return 0;
        $blogId = $this->ensureBlog();
        if ($blogId <= 0) return 0;

        $postId = $this->getPostIdForElement($elementId);
        if ($postId <= 0) return 0;
        return $this->countCommentsForPost($blogId, $postId);
    }

    public function countsForElements(array $elementIds, int $iblockId): array
    {
        if (!$this->isAvailable() || !$elementIds) return [];
        $blogId = $this->ensureBlog();
        if ($blogId <= 0) return [];

        $propCode = \CIBlockPropertyTools::CODE_BLOG_POST;
        $counts = [];
        $postToElement = [];

        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $elementIds],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'PROPERTY_' . $propCode]
        );
        while ($row = $rs->Fetch()) {
            $postId = (int)($row['PROPERTY_' . $propCode . '_VALUE'] ?? 0);
            if ($postId > 0) {
                $postToElement[$postId] = (int)$row['ID'];
            }
        }
        if (!$postToElement) return [];

        $postIds = array_keys($postToElement);
        $conn = \Bitrix\Main\Application::getConnection();
        $inList = implode(',', array_map('intval', $postIds));
        $sql = "SELECT POST_ID, COUNT(*) AS CNT FROM b_blog_comment
                WHERE BLOG_ID = " . (int)$blogId
            . " AND POST_ID IN ($inList)
                AND PUBLISH_STATUS = 'P'
                AND (PARENT_ID IS NULL OR PARENT_ID = 0 OR PARENT_ID = '')
                GROUP BY POST_ID";
        $rsC = $conn->query($sql);
        while ($t = $rsC->fetch()) {
            $eid = $postToElement[(int)$t['POST_ID']] ?? 0;
            if ($eid > 0) {
                $counts[$eid] = (int)$t['CNT'];
            }
        }
        return $counts;
    }

    public function listForElement(int $elementId, int $limit = 50): array
    {
        if (!$this->isAvailable()) return [];
        $blogId = $this->ensureBlog();
        if ($blogId <= 0) return [];
        $postId = $this->getPostIdForElement($elementId);
        if ($postId <= 0) return [];

        $rsC = \CBlogComment::GetList(
            ['DATE_CREATE' => 'DESC'],
            [
                'BLOG_ID' => $blogId,
                'POST_ID' => $postId,
                'PARENT_ID' => '',
            ],
            false,
            ['nTopCount' => $limit],
            [
                'ID', 'AUTHOR_NAME', 'POST_TEXT', 'DATE_CREATE', 'PUBLISH_STATUS',
                'UF_ASPRO_COM_RATING', 'UF_ASPRO_COM_APPROVE', 'UF_AI_GENERATED',
            ]
        );
        $out = [];
        while ($c = $rsC->Fetch()) {
            $out[] = [
                'id' => (int)$c['ID'],
                'author' => (string)$c['AUTHOR_NAME'],
                'content' => (string)$c['POST_TEXT'],
                'date' => (string)$c['DATE_CREATE'],
                'rating' => (int)($c['UF_ASPRO_COM_RATING'] ?? 0),
                'approved' => ($c['PUBLISH_STATUS'] === 'P') && !empty($c['UF_ASPRO_COM_APPROVE']),
                'ai' => !empty($c['UF_AI_GENERATED']),
            ];
        }
        return $out;
    }

    public function saveForElement(int $elementId, array $reviews, array $opts): array
    {
        $el = \CIBlockElement::GetByID($elementId)->Fetch();
        if (!$el) {
            return ['success' => false, 'error' => 'Элемент не найден'];
        }
        $setup = $this->setupForIblock((int)$el['IBLOCK_ID']);
        if (empty($setup['success'])) {
            return $setup;
        }
        $blogId = (int)($setup['blog_id'] ?? 0);
        if ($blogId <= 0) {
            $blogId = $this->ensureBlog();
        }

        $postId = $this->ensurePostForElement(
            (int)$el['ID'],
            (int)$el['IBLOCK_ID'],
            (string)$el['NAME'],
            $blogId
        );
        if ($postId <= 0) {
            return ['success' => false, 'error' => 'Не удалось создать/найти пост блога для товара'];
        }

        $autoApprove = (bool)($opts['auto_approve'] ?? true);
        $from = (int)($opts['date_from'] ?? 0);
        $to = (int)($opts['date_to'] ?? 0);
        $useDateRange = $from > 0 && $to > 0;
        if ($useDateRange && $from > $to) { [$from, $to] = [$to, $from]; }

        global $USER_FIELD_MANAGER;
        $saved = 0;
        // Без $author из API — имя автора возьмётся из юзера-«персоны» (NAME + LAST_NAME),
        // потому что blog.post.comment.list при AUTHOR_ID > 0 игнорирует AUTHOR_NAME коммента.
        foreach ($reviews as $r) {
            $content = TextSanitizer::stripEmoji(trim((string)($r['content'] ?? '')));
            $rating = (int)($r['rating'] ?? 5);
            if ($rating < 1 || $rating > 5) $rating = 5;
            if ($content === '') continue;

            // Aspro Premier рендерит ~POST_TEXT через preg_match('/<comment>(.*?)<\/comment>/s')
            // и берёт только захваченную группу. Без обёртки текст в шаблоне не виден,
            // поэтому подставляем теги.
            $content = '<comment>' . $content . '</comment>';

            $personaId = PersonaPool::pickRandomId();
            if ($personaId <= 0) continue;

            $ts = $useDateRange ? random_int($from, $to) : time();
            $dateCreate = \ConvertTimeStamp($ts, 'FULL');

            // AUTHOR_NAME/EMAIL дублируем из юзера — для UI админки и совместимости с потенциальными
            // шаблонами, которые читают AUTHOR_NAME напрямую.
            $personaInfo = $this->getPersonaInfo($personaId);

            $commentId = (int)\CBlogComment::Add([
                'BLOG_ID' => $blogId,
                'POST_ID' => $postId,
                'PARENT_ID' => '',
                'AUTHOR_ID' => $personaId,
                'AUTHOR_NAME' => $personaInfo['name'] ?: 'Покупатель',
                'AUTHOR_EMAIL' => $personaInfo['email'] ?: 'user@local.invalid',
                'AUTHOR_IP' => '127.0.0.1',
                'POST_TEXT' => $content,
                'PUBLISH_STATUS' => $autoApprove ? 'P' : 'M',
                'DATE_CREATE' => $dateCreate,
            ]);
            if ($commentId > 0) {
                if ($USER_FIELD_MANAGER) {
                    $USER_FIELD_MANAGER->Update('BLOG_COMMENT', $commentId, [
                        'UF_ASPRO_COM_RATING' => $rating,
                        'UF_ASPRO_COM_APPROVE' => $autoApprove ? 1 : 0,
                        'UF_AI_GENERATED' => 1,
                    ]);
                }
                $saved++;
            }
        }

        if ($saved > 0) {
            $this->updateCommentsCountProp((int)$el['ID'], (int)$el['IBLOCK_ID'], $blogId, $postId);
        }

        if ($saved === 0) {
            return ['success' => false, 'error' => 'Не удалось сохранить ни одного отзыва', 'container_id' => $postId];
        }
        return ['success' => true, 'saved' => $saved, 'container_id' => $postId];
    }

    public function deleteOne(int $messageId): bool
    {
        if (!$this->isAvailable() || $messageId <= 0) return false;
        $comment = \CBlogComment::GetByID($messageId);
        if (!$comment) return false;
        $ok = (bool)\CBlogComment::Delete($messageId);
        if ($ok && !empty($comment['POST_ID']) && !empty($comment['BLOG_ID'])) {
            $elementId = $this->findElementByPostId((int)$comment['BLOG_ID'], (int)$comment['POST_ID']);
            if ($elementId > 0) {
                $el = \CIBlockElement::GetByID($elementId)->Fetch();
                if ($el) {
                    $this->updateCommentsCountProp(
                        $elementId,
                        (int)$el['IBLOCK_ID'],
                        (int)$comment['BLOG_ID'],
                        (int)$comment['POST_ID']
                    );
                }
            }
        }
        return $ok;
    }

    public function updateOne(int $messageId, string $author, string $content, int $rating): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Модуль blog недоступен'];
        }
        $author = trim(TextSanitizer::stripEmoji($author));
        $content = trim(TextSanitizer::stripEmoji($content));
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'error' => 'Рейтинг должен быть от 1 до 5'];
        }
        if ($author === '') {
            return ['success' => false, 'error' => 'Имя автора не может быть пустым'];
        }
        if ($content === '') {
            return ['success' => false, 'error' => 'Текст отзыва не может быть пустым'];
        }
        if (!\CBlogComment::GetByID($messageId)) {
            return ['success' => false, 'error' => 'Комментарий не найден'];
        }
        $ok = \CBlogComment::Update($messageId, [
            'AUTHOR_NAME' => $author,
            'POST_TEXT' => $content,
        ]);
        if (!$ok) {
            return ['success' => false, 'error' => 'Не удалось обновить комментарий'];
        }
        global $USER_FIELD_MANAGER;
        if ($USER_FIELD_MANAGER) {
            $USER_FIELD_MANAGER->Update('BLOG_COMMENT', $messageId, [
                'UF_ASPRO_COM_RATING' => $rating,
            ]);
        }
        return ['success' => true];
    }

    public function findElementsWithReviews(int $iblockId): array
    {
        if (!$this->isAvailable()) return [];
        $blogId = $this->ensureBlog();
        if ($blogId <= 0) return [];

        $propCode = \CIBlockPropertyTools::CODE_BLOG_POST;
        $postToElement = [];
        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '!PROPERTY_' . $propCode => false],
            false,
            false,
            ['ID', 'PROPERTY_' . $propCode]
        );
        while ($row = $rs->Fetch()) {
            $pid = (int)($row['PROPERTY_' . $propCode . '_VALUE'] ?? 0);
            if ($pid > 0) {
                $postToElement[$pid] = (int)$row['ID'];
            }
        }
        if (!$postToElement) return [];

        $conn = \Bitrix\Main\Application::getConnection();
        $inList = implode(',', array_map('intval', array_keys($postToElement)));
        $sql = "SELECT POST_ID, COUNT(*) AS CNT FROM b_blog_comment
                WHERE BLOG_ID = " . (int)$blogId
            . " AND POST_ID IN ($inList)
                AND PUBLISH_STATUS = 'P'
                AND (PARENT_ID IS NULL OR PARENT_ID = 0 OR PARENT_ID = '')
                GROUP BY POST_ID";
        $rsC = $conn->query($sql);
        $out = [];
        while ($t = $rsC->fetch()) {
            $cnt = (int)$t['CNT'];
            $eid = $postToElement[(int)$t['POST_ID']] ?? 0;
            if ($cnt > 0 && $eid > 0) {
                $out[$eid] = $cnt;
            }
        }
        return $out;
    }

    // ────────────────────────────── internals ──────────────────────────────

    /** Лениво создаёт блог `catalog_comments`, возвращает его ID. */
    private function ensureBlog(): int
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $blogId = Options::getReviewsBlogId();
        if ($blogId > 0) {
            $rs = \CBlog::GetByID($blogId);
            if ($rs) {
                return $cached = $blogId;
            }
        }

        $blogUrl = Options::getReviewsBlogUrl();
        $rs = \CBlog::GetList([], ['URL' => $blogUrl], false, false, ['ID']);
        if ($row = $rs->Fetch()) {
            Options::set('reviews_blog_id', (int)$row['ID']);
            return $cached = (int)$row['ID'];
        }

        // Create blog group (one per site, shared)
        $groupName = 'Комментарии к товарам';
        $rsG = \CBlogGroup::GetList([], ['SITE_ID' => SITE_ID, 'NAME' => $groupName], false, false, ['ID']);
        $groupId = 0;
        if ($g = $rsG->Fetch()) {
            $groupId = (int)$g['ID'];
        } else {
            $groupId = (int)\CBlogGroup::Add(['SITE_ID' => SITE_ID, 'NAME' => $groupName]);
        }
        if ($groupId <= 0) return $cached = 0;

        global $DB;
        $blogId = (int)\CBlog::Add([
            'NAME' => 'Отзывы к товарам',
            'DESCRIPTION' => 'Блог-контейнер для отзывов (auto-created by blocksee.aiseo).',
            'GROUP_ID' => $groupId,
            'ENABLE_COMMENTS' => 'Y',
            'ENABLE_IMG_VERIF' => 'Y',
            'EMAIL_NOTIFY' => 'N',
            'URL' => $blogUrl,
            'ACTIVE' => 'Y',
            'OWNER_ID' => 1,
            'SEARCH_INDEX' => 'N',
            'AUTO_GROUPS' => 'N',
            'PERMS_POST' => [
                1 => BLOG_PERMS_READ,
                2 => BLOG_PERMS_READ,
            ],
            'PERMS_COMMENT' => [
                1 => BLOG_PERMS_WRITE,
                2 => BLOG_PERMS_WRITE,
            ],
            '=DATE_CREATE' => $DB->GetNowFunction(),
            '=DATE_UPDATE' => $DB->GetNowFunction(),
        ]);

        if ($blogId > 0) {
            Options::set('reviews_blog_id', $blogId);
        }
        return $cached = $blogId;
    }

    /** Гарантирует, что у инфоблока есть свойства BLOG_POST_ID и BLOG_COMMENTS_COUNT. */
    private function ensureIblockProperties(int $iblockId): array
    {
        if ($iblockId <= 0) return [0, 0];
        $postCode = \CIBlockPropertyTools::CODE_BLOG_POST;
        $cntCode = \CIBlockPropertyTools::CODE_BLOG_COMMENTS_COUNT;

        $propPostId = 0;
        $propCountId = 0;
        $rs = \Bitrix\Iblock\PropertyTable::getList([
            'select' => ['ID', 'CODE'],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=PROPERTY_TYPE' => \Bitrix\Iblock\PropertyTable::TYPE_NUMBER,
                '=MULTIPLE' => 'N',
                '=CODE' => [$postCode, $cntCode],
            ],
        ]);
        while ($p = $rs->fetch()) {
            if ($p['CODE'] === $postCode) $propPostId = (int)$p['ID'];
            elseif ($p['CODE'] === $cntCode) $propCountId = (int)$p['ID'];
        }
        if ($propPostId <= 0) {
            $propPostId = (int)\CIBlockPropertyTools::createProperty($iblockId, $postCode);
        }
        if ($propCountId <= 0) {
            $propCountId = (int)\CIBlockPropertyTools::createProperty($iblockId, $cntCode);
        }
        return [$propPostId, $propCountId];
    }

    /** Регистрирует UF-поля на сущности BLOG_COMMENT. */
    private function ensureCommentUserFields(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $entity = 'BLOG_COMMENT';
        $existing = [];
        $rs = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entity]);
        while ($r = $rs->Fetch()) {
            $existing[$r['FIELD_NAME']] = (int)$r['ID'];
        }
        $ute = new \CUserTypeEntity();
        if (empty($existing['UF_ASPRO_COM_RATING'])) {
            $ute->Add([
                'ENTITY_ID' => $entity,
                'FIELD_NAME' => 'UF_ASPRO_COM_RATING',
                'USER_TYPE_ID' => 'integer',
                'XML_ID' => 'UF_ASPRO_COM_RATING',
                'SORT' => 100,
                'MULTIPLE' => 'N',
                'MANDATORY' => 'N',
                'SHOW_FILTER' => 'I',
                'SHOW_IN_LIST' => 'Y',
                'EDIT_IN_LIST' => 'Y',
                'IS_SEARCHABLE' => 'N',
                'SETTINGS' => ['DEFAULT_VALUE' => 0, 'SIZE' => 2, 'MIN_VALUE' => 1, 'MAX_VALUE' => 5],
                'EDIT_FORM_LABEL' => ['ru' => 'Рейтинг (1-5)', 'en' => 'Rating (1-5)'],
                'LIST_COLUMN_LABEL' => ['ru' => 'Рейтинг', 'en' => 'Rating'],
                'LIST_FILTER_LABEL' => ['ru' => 'Рейтинг', 'en' => 'Rating'],
            ]);
        }
        if (empty($existing['UF_ASPRO_COM_APPROVE'])) {
            $ute->Add([
                'ENTITY_ID' => $entity,
                'FIELD_NAME' => 'UF_ASPRO_COM_APPROVE',
                'USER_TYPE_ID' => 'boolean',
                'XML_ID' => 'UF_ASPRO_COM_APPROVE',
                'SORT' => 110,
                'MULTIPLE' => 'N',
                'MANDATORY' => 'N',
                'SHOW_FILTER' => 'I',
                'SHOW_IN_LIST' => 'Y',
                'EDIT_IN_LIST' => 'Y',
                'IS_SEARCHABLE' => 'N',
                'SETTINGS' => ['DEFAULT_VALUE' => 1],
                'EDIT_FORM_LABEL' => ['ru' => 'Одобрено', 'en' => 'Approved'],
                'LIST_COLUMN_LABEL' => ['ru' => 'Одобрено', 'en' => 'Approved'],
                'LIST_FILTER_LABEL' => ['ru' => 'Одобрено', 'en' => 'Approved'],
            ]);
        }
        if (empty($existing['UF_AI_GENERATED'])) {
            $ute->Add([
                'ENTITY_ID' => $entity,
                'FIELD_NAME' => 'UF_AI_GENERATED',
                'USER_TYPE_ID' => 'integer',
                'XML_ID' => 'UF_AI_GENERATED',
                'SORT' => 120,
                'MULTIPLE' => 'N',
                'MANDATORY' => 'N',
                'SHOW_FILTER' => 'I',
                'SHOW_IN_LIST' => 'Y',
                'EDIT_IN_LIST' => 'Y',
                'IS_SEARCHABLE' => 'N',
                'SETTINGS' => ['DEFAULT_VALUE' => 0, 'SIZE' => 2, 'MIN_VALUE' => 0, 'MAX_VALUE' => 1],
                'EDIT_FORM_LABEL' => ['ru' => 'AI-сгенерировано', 'en' => 'AI-generated'],
                'LIST_COLUMN_LABEL' => ['ru' => 'AI', 'en' => 'AI'],
                'LIST_FILTER_LABEL' => ['ru' => 'AI', 'en' => 'AI'],
            ]);
        }
    }

    private function getPostIdForElement(int $elementId): int
    {
        $propCode = \CIBlockPropertyTools::CODE_BLOG_POST;
        $rs = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'PROPERTY_' . $propCode]
        );
        if ($row = $rs->Fetch()) {
            return (int)($row['PROPERTY_' . $propCode . '_VALUE'] ?? 0);
        }
        return 0;
    }

    private function ensurePostForElement(int $elementId, int $iblockId, string $name, int $blogId): int
    {
        $existing = $this->getPostIdForElement($elementId);
        if ($existing > 0) {
            $rs = \CBlogPost::GetByID($existing);
            if ($rs) {
                return $existing;
            }
        }

        global $DB;
        $title = trim($name) !== '' ? $name : "Товар #$elementId";
        if (mb_strlen($title) > 240) {
            $title = mb_substr($title, 0, 240);
        }

        // Структура полей повторяет bitrix:catalog.comments (BLOG_USE=Y) — чтобы пост был
        // полностью совместим с штатным шаблоном Aspro Premier.
        $postId = (int)\CBlogPost::Add([
            'TITLE' => $title,
            'DETAIL_TEXT' => $title,
            'PUBLISH_STATUS' => BLOG_PUBLISH_STATUS_PUBLISH,
            'PERMS_POST' => [],
            'PERMS_COMMENT' => [],
            '=DATE_CREATE' => $DB->GetNowFunction(),
            '=DATE_PUBLISH' => $DB->GetNowFunction(),
            'AUTHOR_ID' => 1,
            'BLOG_ID' => $blogId,
            'ENABLE_TRACKBACK' => 'N',
        ]);
        if ($postId <= 0) {
            return 0;
        }

        // Используем SetPropertyValues (как в нативе) — нужен ID свойства.
        $propPostId = $this->getPropertyId($iblockId, \CIBlockPropertyTools::CODE_BLOG_POST);
        if ($propPostId > 0) {
            \CIBlockElement::SetPropertyValues($elementId, $iblockId, $postId, $propPostId);
        }
        return $postId;
    }

    /** @return array{name:string, email:string} */
    private function getPersonaInfo(int $userId): array
    {
        static $cache = [];
        if (isset($cache[$userId])) return $cache[$userId];
        $rs = \CUser::GetList('ID', 'ASC', ['ID' => $userId], ['FIELDS' => ['NAME', 'LAST_NAME', 'EMAIL']]);
        if ($u = $rs->Fetch()) {
            $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            return $cache[$userId] = ['name' => $name, 'email' => (string)($u['EMAIL'] ?? '')];
        }
        return $cache[$userId] = ['name' => '', 'email' => ''];
    }

    private function getPropertyId(int $iblockId, string $code): int
    {
        $rs = \Bitrix\Iblock\PropertyTable::getList([
            'select' => ['ID'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
            'limit' => 1,
        ]);
        if ($r = $rs->fetch()) {
            return (int)$r['ID'];
        }
        return 0;
    }

    private function countCommentsForPost(int $blogId, int $postId): int
    {
        $conn = \Bitrix\Main\Application::getConnection();
        $rs = $conn->query(
            "SELECT COUNT(*) AS CNT FROM b_blog_comment
             WHERE BLOG_ID = " . (int)$blogId
            . " AND POST_ID = " . (int)$postId
            . " AND PUBLISH_STATUS = 'P'
                AND (PARENT_ID IS NULL OR PARENT_ID = 0 OR PARENT_ID = '')"
        );
        if ($row = $rs->fetch()) {
            return (int)$row['CNT'];
        }
        return 0;
    }

    private function updateCommentsCountProp(int $elementId, int $iblockId, int $blogId, int $postId): void
    {
        $count = $this->countCommentsForPost($blogId, $postId);
        $propId = $this->getPropertyId($iblockId, \CIBlockPropertyTools::CODE_BLOG_COMMENTS_COUNT);
        if ($propId > 0) {
            \CIBlockElement::SetPropertyValues($elementId, $iblockId, $count, $propId);
        }
    }

    private function findElementByPostId(int $blogId, int $postId): int
    {
        $propCode = \CIBlockPropertyTools::CODE_BLOG_POST;
        $rs = \CIBlockElement::GetList(
            [],
            ['PROPERTY_' . $propCode => $postId],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        if ($row = $rs->Fetch()) {
            return (int)$row['ID'];
        }
        return 0;
    }

    private function generateFakeEmail(string $name): string
    {
        $translit = $this->transliterate($name);
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $translit));
        $clean = trim($clean, '.');
        if ($clean === '') $clean = 'user';
        $domains = ['gmail.com', 'yandex.ru', 'mail.ru', 'outlook.com'];
        return $clean . random_int(10, 99) . '@' . $domains[array_rand($domains)];
    }

    private function transliterate(string $text): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'Yo','Ж'=>'Zh','З'=>'Z',
            'И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R',
            'С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch',
            'Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        ];
        return strtr($text, $map);
    }
}
