<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Loader;
use Blocksee\Aiseo\Reviews\Backend;
use Blocksee\Aiseo\Reviews\Factory;
use Blocksee\Aiseo\Reviews\Scenarios;

class ReviewsGenerator
{
    /** @var ApiClient */
    private $apiClient;
    /** @var Generator */
    private $productGen;
    /** @var Backend|null */
    private $backend;

    public function __construct(?ApiClient $apiClient = null, ?Generator $productGen = null, ?Backend $backend = null)
    {
        Loader::includeModule('iblock');
        $this->apiClient = $apiClient ?? new ApiClient();
        $this->productGen = $productGen ?? new Generator($this->apiClient);
        $this->backend = $backend ?? Factory::create();
    }

    public function getBackend(): ?Backend
    {
        return $this->backend;
    }

    /**
     * Генерирует $count отзывов отдельными API-запросами (по одному на отзыв),
     * подставляя в custom_prompt случайный непересекающийся сценарий из Scenarios::ALL.
     * Сценарий дописывается к пользовательскому custom_prompt, не заменяя его.
     *
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

        $baseSettings = Options::getReviewsSettings();
        $userPrompt = trim((string)($baseSettings['custom_prompt'] ?? ''));
        $scenarios = Scenarios::pickRandom($count);

        // Глобальные «анти-AI» правила: запрещаем явные маркеры машинной генерации
        // и шаблонный язык. Конкретных эвфемизмов в примерах НЕ даём — иначе они
        // сами становятся повторяющимся клише во всех отзывах подряд.
        $globalRules = "Жёсткие правила для отзыва:\n"
            . "- НЕ упоминай артикул товара, внутренний код, RAL/Pantone коды цветов, точные числа из ТТХ (вес, нагрузка, размеры в мм/см/кг).\n"
            . "- Если хочется упомянуть характеристику — формулируй обобщённо, своими словами, как сказал бы человек в живой речи. Не цитируй цифры.\n"
            . "- Полное название товара повтори максимум один раз. В остальных местах — «эта вещь», «изделие», «оно», или вообще без подлежащего.\n"
            . "- НЕ начинай со слов: «Купил», «Заказал», «Заказывал», «Приобрёл», «Хороший товар», «Отличный товар», «Рекомендую», «Долго выбирал».\n"
            . "- НЕ используй слова: «качественный», «качество на высоте», «соотношение цена-качество», «однозначно рекомендую», «приятно удивлён», «оправдал ожидания».\n"
            . "- Пиши живым разговорным языком, как реальный покупатель на маркетплейсе. Можно лёгкие просторечия и одно личное наблюдение.\n"
            . "- Согласуй род глаголов и прилагательных по контексту (если в сценарии женщина — пиши в женском роде, и наоборот).\n"
            . "- Не повторяй слова и обороты, которые могут совпасть с другими AI-отзывами на этот же товар.";

        $allReviews = [];
        $lastError = '';
        foreach ($scenarios as $scenario) {
            $perRequest = $baseSettings;
            $extra = "Сценарий и формат этого конкретного отзыва: " . $scenario
                . "\n\n" . $globalRules;
            $perRequest['custom_prompt'] = $userPrompt !== ''
                ? $userPrompt . "\n\n" . $extra
                : $extra;

            $r = $this->apiClient->generateProductReviews($productData, 1, $perRequest);
            if (empty($r['success'])) {
                $lastError = (string)($r['error'] ?? 'unknown');
                continue;
            }
            foreach ((array)($r['reviews'] ?? []) as $rev) {
                $allReviews[] = $rev;
            }
        }

        if (!$allReviews) {
            return ['success' => false, 'error' => 'Не удалось сгенерировать отзывы' . ($lastError ? " ($lastError)" : '')];
        }
        return ['success' => true, 'reviews' => $allReviews];
    }

    /**
     * @param array<int,array{author_name:string,content:string,rating:int}> $reviews
     */
    public function saveReviewsForElement(int $elementId, array $reviews): array
    {
        if (!$reviews) {
            return ['success' => false, 'error' => 'Нет отзывов для сохранения'];
        }
        if (!$this->backend) {
            return ['success' => false, 'error' => 'Источник отзывов не настроен (нужен модуль blog или forum)'];
        }

        $opts = [
            'auto_approve' => Options::getReviewsAutoApprove(),
        ];
        if (Options::getReviewsDateRangeEnabled()) {
            $opts['date_from'] = strtotime(Options::getReviewsDateFrom() . ' 00:00:00') ?: 0;
            $opts['date_to'] = strtotime(Options::getReviewsDateTo() . ' 23:59:59') ?: 0;
        }

        $res = $this->backend->saveForElement($elementId, $reviews, $opts);
        // Совместимость со старым ключом topic_id
        if (isset($res['container_id']) && !isset($res['topic_id'])) {
            $res['topic_id'] = $res['container_id'];
        }
        return $res;
    }

    public function generateAndSaveForElement(int $elementId, int $count = 0): array
    {
        $gen = $this->generateForElement($elementId, $count);
        if (empty($gen['success'])) return $gen;
        $save = $this->saveReviewsForElement($elementId, $gen['reviews']);
        if (empty($save['success'])) return $save;
        return [
            'success' => true,
            'saved' => $save['saved'],
            'topic_id' => $save['topic_id'] ?? ($save['container_id'] ?? 0),
            'reviews' => $gen['reviews'],
        ];
    }

    public function countReviewsForElement(int $elementId): int
    {
        return $this->backend ? $this->backend->count($elementId) : 0;
    }

    /**
     * @param int[] $elementIds
     * @return array<int,int>
     */
    public function countsForElements(array $elementIds, int $iblockId): array
    {
        return $this->backend ? $this->backend->countsForElements($elementIds, $iblockId) : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listReviewsForElement(int $elementId, int $limit = 50): array
    {
        return $this->backend ? $this->backend->listForElement($elementId, $limit) : [];
    }

    public function deleteReview(int $messageId): bool
    {
        return $this->backend ? $this->backend->deleteOne($messageId) : false;
    }

    public function updateReview(int $messageId, string $author, string $content, int $rating): array
    {
        if (!$this->backend) {
            return ['success' => false, 'error' => 'Источник отзывов не настроен'];
        }
        return $this->backend->updateOne($messageId, $author, $content, $rating);
    }

    /** @return array<int,int> */
    public function findElementsWithReviews(int $iblockId): array
    {
        return $this->backend ? $this->backend->findElementsWithReviews($iblockId) : [];
    }
}
