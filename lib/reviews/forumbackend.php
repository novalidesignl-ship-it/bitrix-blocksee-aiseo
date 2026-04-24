<?php

namespace Blocksee\Aiseo\Reviews;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Blocksee\Aiseo\Options;
use Blocksee\Aiseo\TextSanitizer;

class ForumBackend implements Backend
{
    public function isAvailable(): bool
    {
        return ModuleManager::isModuleInstalled('forum') && Loader::includeModule('forum');
    }

    public function setupForIblock(int $iblockId): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Модуль forum недоступен'];
        }
        if (Options::getReviewsForumId() <= 0) {
            return ['success' => false, 'error' => 'Форум для отзывов не настроен. Переустановите модуль или задайте его в настройках.'];
        }
        return ['success' => true];
    }

    public function count(int $elementId): int
    {
        if (!$this->isAvailable()) return 0;
        $element = \CIBlockElement::GetByID($elementId)->Fetch();
        if (!$element) return 0;
        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) return 0;

        $xmlId = $this->makeTopicXmlId((int)$element['IBLOCK_ID'], (int)$element['ID']);
        $rs = \CForumTopic::GetList([], ['FORUM_ID' => $forumId, 'XML_ID' => $xmlId]);
        if ($topic = $rs->Fetch()) {
            return (int)$topic['POSTS'];
        }
        return 0;
    }

    public function countsForElements(array $elementIds, int $iblockId): array
    {
        if (!$this->isAvailable() || !$elementIds) return [];
        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) return [];

        $placeholders = [];
        foreach ($elementIds as $id) {
            $placeholders[] = "'iblock_" . (int)$iblockId . "_" . (int)$id . "'";
        }
        $inList = implode(',', $placeholders);

        $counts = [];
        $conn = \Bitrix\Main\Application::getConnection();
        $rs = $conn->query(
            "SELECT XML_ID, POSTS FROM b_forum_topic WHERE FORUM_ID = " . (int)$forumId
            . " AND XML_ID IN ($inList)"
        );
        while ($t = $rs->fetch()) {
            if (preg_match('/^iblock_\d+_(\d+)$/', $t['XML_ID'], $m)) {
                $counts[(int)$m[1]] = (int)$t['POSTS'];
            }
        }
        return $counts;
    }

    public function listForElement(int $elementId, int $limit = 50): array
    {
        if (!$this->isAvailable()) return [];
        $element = \CIBlockElement::GetByID($elementId)->Fetch();
        if (!$element) return [];
        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) return [];

        $xmlId = $this->makeTopicXmlId((int)$element['IBLOCK_ID'], (int)$element['ID']);
        $rs = \CForumTopic::GetList([], ['FORUM_ID' => $forumId, 'XML_ID' => $xmlId]);
        $topic = $rs->Fetch();
        if (!$topic) return [];

        $rsMsg = \CForumMessage::GetList(
            ['POST_DATE' => 'DESC'],
            ['TOPIC_ID' => (int)$topic['ID']],
            false,
            false,
            ['nTopCount' => $limit]
        );
        global $USER_FIELD_MANAGER;
        $out = [];
        while ($m = $rsMsg->Fetch()) {
            $rating = 0; $ai = false;
            if ($USER_FIELD_MANAGER) {
                $uf = $USER_FIELD_MANAGER->GetUserFields('FORUM_MESSAGE', (int)$m['ID']);
                $rating = (int)($uf['UF_RATING']['VALUE'] ?? 0);
                $ai = !empty($uf['UF_AI_GENERATED']['VALUE']);
            }
            $out[] = [
                'id' => (int)$m['ID'],
                'author' => (string)$m['AUTHOR_NAME'],
                'content' => (string)$m['POST_MESSAGE'],
                'date' => (string)$m['POST_DATE'],
                'rating' => $rating,
                'approved' => $m['APPROVED'] === 'Y',
                'ai' => $ai,
            ];
        }
        return $out;
    }

    public function saveForElement(int $elementId, array $reviews, array $opts): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Модуль forum недоступен'];
        }
        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) {
            return ['success' => false, 'error' => 'Форум для отзывов не настроен'];
        }
        $element = \CIBlockElement::GetByID($elementId)->Fetch();
        if (!$element) {
            return ['success' => false, 'error' => 'Элемент не найден'];
        }

        $topicId = $this->ensureTopicForElement(
            (int)$element['ID'],
            (int)$element['IBLOCK_ID'],
            (string)$element['NAME']
        );
        if ($topicId <= 0) {
            return ['success' => false, 'error' => 'Не удалось создать/найти топик отзыва'];
        }

        $autoApprove = (bool)($opts['auto_approve'] ?? true);
        $from = (int)($opts['date_from'] ?? 0);
        $to = (int)($opts['date_to'] ?? 0);
        $useDateRange = $from > 0 && $to > 0;
        if ($useDateRange && $from > $to) { [$from, $to] = [$to, $from]; }

        $saved = 0;
        foreach ($reviews as $r) {
            $author = trim((string)($r['author_name'] ?? ''));
            $content = TextSanitizer::stripEmoji(trim((string)($r['content'] ?? '')));
            $rating = (int)($r['rating'] ?? 5);
            if ($rating < 1 || $rating > 5) $rating = 5;
            if ($author === '' || $content === '') continue;

            $ts = $useDateRange ? random_int($from, $to) : time();
            $postDate = \ConvertTimeStamp($ts, 'FULL');

            $messageId = (int)\CForumMessage::Add([
                'POST_MESSAGE' => $content,
                'AUTHOR_NAME' => $author,
                'AUTHOR_EMAIL' => $this->generateFakeEmail($author),
                'AUTHOR_IP' => '127.0.0.1',
                'AUTHOR_REAL_IP' => '127.0.0.1',
                'FORUM_ID' => $forumId,
                'TOPIC_ID' => $topicId,
                'NEW_TOPIC' => 'N',
                'APPROVED' => $autoApprove ? 'Y' : 'N',
                'POST_DATE' => $postDate,
            ]);
            if ($messageId > 0) {
                global $USER_FIELD_MANAGER;
                if ($USER_FIELD_MANAGER) {
                    $USER_FIELD_MANAGER->Update('FORUM_MESSAGE', $messageId, [
                        'UF_RATING' => $rating,
                        'UF_AI_GENERATED' => 1,
                    ]);
                }
                $saved++;
            }
        }

        if ($saved === 0) {
            return ['success' => false, 'error' => 'Не удалось сохранить ни одного отзыва', 'container_id' => $topicId];
        }
        return ['success' => true, 'saved' => $saved, 'container_id' => $topicId];
    }

    public function deleteOne(int $messageId): bool
    {
        if (!$this->isAvailable() || $messageId <= 0) return false;
        return (bool)\CForumMessage::Delete($messageId);
    }

    public function updateOne(int $messageId, string $author, string $content, int $rating): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Модуль forum недоступен'];
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

        $rsExisting = \CForumMessage::GetList([], ['ID' => $messageId]);
        $existing = $rsExisting ? $rsExisting->Fetch() : null;
        if (!$existing) {
            return ['success' => false, 'error' => 'Сообщение не найдено'];
        }
        $ok = \CForumMessage::Update($messageId, [
            'AUTHOR_NAME' => $author,
            'POST_MESSAGE' => $content,
        ]);
        if (!$ok) {
            return ['success' => false, 'error' => 'Не удалось обновить сообщение'];
        }
        global $USER_FIELD_MANAGER;
        if ($USER_FIELD_MANAGER) {
            $USER_FIELD_MANAGER->Update('FORUM_MESSAGE', $messageId, ['UF_RATING' => $rating]);
        }
        return ['success' => true];
    }

    public function findElementsWithReviews(int $iblockId): array
    {
        if (!$this->isAvailable()) return [];
        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) return [];

        $out = [];
        $conn = \Bitrix\Main\Application::getConnection();
        $rs = $conn->query(
            "SELECT XML_ID, POSTS FROM b_forum_topic WHERE FORUM_ID = " . (int)$forumId
            . " AND XML_ID LIKE 'iblock_" . (int)$iblockId . "_%' AND POSTS > 0"
        );
        while ($t = $rs->fetch()) {
            if (preg_match('/^iblock_\d+_(\d+)$/', $t['XML_ID'], $m)) {
                $out[(int)$m[1]] = (int)$t['POSTS'];
            }
        }
        return $out;
    }

    private function makeTopicXmlId(int $iblockId, int $elementId): string
    {
        return "iblock_{$iblockId}_{$elementId}";
    }

    private function ensureTopicForElement(int $elementId, int $iblockId, string $name): int
    {
        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) return 0;

        $xmlId = $this->makeTopicXmlId($iblockId, $elementId);
        $rs = \CForumTopic::GetList([], ['FORUM_ID' => $forumId, 'XML_ID' => $xmlId]);
        if ($topic = $rs->Fetch()) {
            return (int)$topic['ID'];
        }

        $title = trim($name) !== '' ? $name : "Товар #$elementId";
        if (mb_strlen($title) > 240) {
            $title = mb_substr($title, 0, 240);
        }

        return (int)\CForumTopic::Add([
            'TITLE' => $title,
            'TAGS' => '',
            'FORUM_ID' => $forumId,
            'USER_START_ID' => 0,
            'USER_START_NAME' => 'AI Review Bot',
            'START_DATE' => \ConvertTimeStamp(time(), 'FULL'),
            'LAST_POSTER_NAME' => 'AI Review Bot',
            'LAST_POST_DATE' => \ConvertTimeStamp(time(), 'FULL'),
            'APPROVED' => 'Y',
            'XML_ID' => $xmlId,
        ]);
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
