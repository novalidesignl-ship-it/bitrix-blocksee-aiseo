<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Loader;

class ReviewsGenerator
{
    private ApiClient $apiClient;
    private Generator $productGen;

    public function __construct(?ApiClient $apiClient = null, ?Generator $productGen = null)
    {
        Loader::includeModule('iblock');
        Loader::includeModule('forum');
        $this->apiClient = $apiClient ?? new ApiClient();
        $this->productGen = $productGen ?? new Generator($this->apiClient);
    }

    /**
     * @return array{success:bool, reviews?:array<int,array{author_name:string,content:string,rating:int}>, error?:string}
     */
    public function generateForElement(int $elementId, int $count = 0): array
    {
        if ($count <= 0) {
            $count = Options::getReviewsPerProduct();
        }
        $productData = $this->productGen->collectProductData($elementId);
        if ($productData === null) {
            return ['success' => false, 'error' => 'Элемент не найден'];
        }
        return $this->apiClient->generateProductReviews($productData, $count, Options::getReviewsSettings());
    }

    /**
     * Save a batch of reviews to the forum topic of the given element.
     *
     * @param array<int,array{author_name:string,content:string,rating:int}> $reviews
     * @return array{success:bool, saved?:int, topic_id?:int, error?:string}
     */
    public function saveReviewsForElement(int $elementId, array $reviews): array
    {
        if (!$reviews) {
            return ['success' => false, 'error' => 'Нет отзывов для сохранения'];
        }

        $forumId = Options::getReviewsForumId();
        if ($forumId <= 0) {
            return ['success' => false, 'error' => 'Форум для отзывов не настроен. Переустановите модуль или задайте его в настройках.'];
        }

        $element = \CIBlockElement::GetByID($elementId)->Fetch();
        if (!$element) {
            return ['success' => false, 'error' => 'Элемент не найден'];
        }

        $topicId = $this->ensureTopicForElement((int)$element['ID'], (int)$element['IBLOCK_ID'], (string)$element['NAME']);
        if ($topicId <= 0) {
            return ['success' => false, 'error' => 'Не удалось создать/найти топик отзыва'];
        }

        $autoApprove = Options::getReviewsAutoApprove();
        $dateRangeEnabled = Options::getReviewsDateRangeEnabled();
        $from = strtotime(Options::getReviewsDateFrom() . ' 00:00:00');
        $to = strtotime(Options::getReviewsDateTo() . ' 23:59:59');
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $saved = 0;
        foreach ($reviews as $r) {
            $author = trim((string)($r['author_name'] ?? ''));
            $content = TextSanitizer::stripEmoji(trim((string)($r['content'] ?? '')));
            $rating = (int)($r['rating'] ?? 5);
            if ($rating < 1 || $rating > 5) $rating = 5;
            if ($author === '' || $content === '') continue;

            $ts = $dateRangeEnabled ? random_int($from, $to) : time();
            $postDate = \ConvertTimeStamp($ts, 'FULL');

            $fields = [
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
            ];

            $messageId = (int)\CForumMessage::Add($fields);
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
            return ['success' => false, 'error' => 'Не удалось сохранить ни одного отзыва', 'topic_id' => $topicId];
        }

        return ['success' => true, 'saved' => $saved, 'topic_id' => $topicId];
    }

    public function generateAndSaveForElement(int $elementId, int $count = 0): array
    {
        $gen = $this->generateForElement($elementId, $count);
        if (empty($gen['success'])) {
            return $gen;
        }
        $save = $this->saveReviewsForElement($elementId, $gen['reviews']);
        if (empty($save['success'])) {
            return $save;
        }
        return [
            'success' => true,
            'saved' => $save['saved'],
            'topic_id' => $save['topic_id'],
            'reviews' => $gen['reviews'],
        ];
    }

    public function countReviewsForElement(int $elementId): int
    {
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

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listReviewsForElement(int $elementId, int $limit = 50): array
    {
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

    public function deleteReview(int $messageId): bool
    {
        if ($messageId <= 0) return false;
        return (bool)\CForumMessage::Delete($messageId);
    }

    /**
     * @return array{success:bool, error?:string}
     */
    public function updateReview(int $messageId, string $author, string $content, int $rating): array
    {
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

        $fields = [
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
        ];
        $topicId = (int)\CForumTopic::Add($fields);
        return $topicId;
    }

    private function generateFakeEmail(string $name): string
    {
        $translit = $this->transliterate($name);
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $translit));
        $clean = trim($clean, '.');
        if ($clean === '') {
            $clean = 'user';
        }
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
