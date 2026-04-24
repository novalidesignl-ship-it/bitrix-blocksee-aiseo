<?php

namespace Blocksee\Aiseo\Reviews;

interface Backend
{
    /** Бэкенд готов к работе (модуль установлен и т.п.). */
    public function isAvailable(): bool;

    /** Лениво готовит хранилище под инфоблок (создаёт форум/блог/свойства). */
    public function setupForIblock(int $iblockId): array;

    public function count(int $elementId): int;

    /**
     * @param int[] $elementIds
     * @return array<int,int> [elementId => count]
     */
    public function countsForElements(array $elementIds, int $iblockId): array;

    /** @return array<int,array<string,mixed>> */
    public function listForElement(int $elementId, int $limit = 50): array;

    /**
     * @param array<int,array{author_name:string,content:string,rating:int}> $reviews
     * @param array{auto_approve?:bool,date_from?:?int,date_to?:?int} $opts
     * @return array{success:bool, saved?:int, container_id?:int, error?:string}
     */
    public function saveForElement(int $elementId, array $reviews, array $opts): array;

    public function deleteOne(int $messageId): bool;

    /** @return array{success:bool, error?:string} */
    public function updateOne(int $messageId, string $author, string $content, int $rating): array;

    /**
     * Список ID элементов, у которых уже есть отзывы (для skip_with_reviews).
     * @return array<int,int> [elementId => count]
     */
    public function findElementsWithReviews(int $iblockId): array;
}
