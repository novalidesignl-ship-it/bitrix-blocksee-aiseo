<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

class ApiClient
{
    /** @var string */
    private $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $this->endpoint = $endpoint ?? Options::getApiEndpoint();
    }

    /**
     * @param array<string,mixed> $productData
     * @return array{success:bool, description?:string, error?:string}
     */
    public function generateProductDescription(array $productData, string $customPrompt = '', array $settings = []): array
    {
        $payload = [
            // Битрикс-специфичный action: серверный case
            // 'generate_product_description_bitrix' имеет hardcoded chain
            // [DS Pro, DS Flash, Sonnet 4.6] независимо от общей
            // bsee_models_chain_for_quality(), которой пользуется WP-плагин.
            // Sonnet — страховка на случай SSN content-filter / залипания
            // DeepSeek-провайдера.
            'action' => 'generate_product_description_bitrix',
            'product_data' => $productData,
            'custom_prompt' => $customPrompt,
            'settings' => [
                'temperature' => (float)($settings['temperature'] ?? 0.7),
                'max_tokens' => (int)($settings['max_tokens'] ?? 3000),
                'creative_mode' => !empty($settings['creative_mode']),
                'quality' => (($settings['quality'] ?? 'standard') === 'high') ? 'high' : 'standard',
            ],
        ];

        // Таймаут 180s. Серверная chain Sonnet 4.6 → DS Pro → DS Flash может
        // последовательно прогнать 3 модели с 60s timeout каждая (если первые
        // отвечают пустым content). 90s нам не хватало.
        $client = new HttpClient([
            'socketTimeout' => 180,
            'streamTimeout' => 180,
            'waitResponse' => true,
        ]);
        $client->setHeader('Content-Type', 'application/json; charset=utf-8');
        $client->setHeader('Referer', self::getSiteUrl());

        try {
            $body = Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'JSON encode failed: ' . $e->getMessage()];
        }

        $response = $client->post($this->endpoint, $body);
        if ($response === false) {
            $errors = $client->getError();
            $msg = $errors ? implode('; ', $errors) : 'Unknown transport error';
            return ['success' => false, 'error' => 'Transport error: ' . $msg];
        }

        $status = $client->getStatus();
        if ($status !== 200) {
            return ['success' => false, 'error' => "HTTP $status: " . substr($response, 0, 500)];
        }

        try {
            $data = Json::decode($response);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Invalid JSON from API: ' . $e->getMessage()];
        }

        if (!is_array($data) || empty($data['success'])) {
            return ['success' => false, 'error' => $data['error'] ?? 'API returned unsuccessful response'];
        }

        $description = $data['data']['description'] ?? '';
        if (!is_string($description) || $description === '') {
            return ['success' => false, 'error' => 'API returned empty description'];
        }

        $description = TextSanitizer::stripEmoji($description);
        if (trim(strip_tags($description)) === '') {
            return ['success' => false, 'error' => 'After sanitization the description is empty'];
        }

        return ['success' => true, 'description' => $description];
    }

    /**
     * @param array<string,mixed> $productData
     * @return array{success:bool, reviews?:array<int,array{author_name:string,content:string,rating:int}>, error?:string}
     */
    public function generateProductReviews(array $productData, int $count = 3, array $settings = []): array
    {
        $count = max(1, min(50, $count));
        $payload = [
            // Битрикс-специфичный action: серверный case 'generate_product_reviews_bitrix'
            // использует отдельный класс BitrixProductReviewsGenerator с собственной
            // fallback chain (Sonnet 4.6 → DS Pro → DS Flash). Изоляция от WP-плагина:
            // правки промптов / post-processing для Битрикса не задевают WordPress.
            'action' => 'generate_product_reviews_bitrix',
            'product_data' => $productData,
            'count' => $count,
            'request_id' => bin2hex(random_bytes(8)),
            'settings' => [
                'min_words' => (int)($settings['min_words'] ?? 20),
                'max_words' => (int)($settings['max_words'] ?? 60),
                // float, чтобы поддержать диапазонные оценки (4.5-5.0). Серверный
                // BPRG принимает оба формата — int или float.
                'rating' => (float)($settings['rating'] ?? 5),
                'custom_prompt' => (string)($settings['custom_prompt'] ?? ''),
                'temperature' => (float)($settings['temperature'] ?? 0.8),
                'creative_mode' => !empty($settings['creative_mode']),
                'quality' => (($settings['quality'] ?? 'standard') === 'high') ? 'high' : 'standard',
                // Структурированный режим (split): просим AI вернуть plusses/minuses
                // отдельно от content. Активируется когда в настройках модуля включён
                // чекбокс «Заполнять Достоинства и Недостатки отдельно». Сервер
                // (BitrixProductReviewsGenerator) поддерживает с v1.11+. На старом
                // сервере флаг игнорируется — fallback на обычный режим.
                'split_pros_cons' => Options::getReviewsCustomSplitProsCons(),
            ],
        ];

        $client = new HttpClient([
            'socketTimeout' => 120,
            'streamTimeout' => 120,
            'waitResponse' => true,
        ]);
        $client->setHeader('Content-Type', 'application/json; charset=utf-8');
        $client->setHeader('Referer', self::getSiteUrl());

        try {
            $body = Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'JSON encode failed: ' . $e->getMessage()];
        }

        $response = $client->post($this->endpoint, $body);
        if ($response === false) {
            $errors = $client->getError();
            $msg = $errors ? implode('; ', $errors) : 'Unknown transport error';
            return ['success' => false, 'error' => 'Transport error: ' . $msg];
        }

        $status = $client->getStatus();
        if ($status !== 200) {
            return ['success' => false, 'error' => "HTTP $status: " . substr($response, 0, 500)];
        }

        try {
            $data = Json::decode($response);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Invalid JSON from API: ' . $e->getMessage()];
        }

        if (!is_array($data) || empty($data['success'])) {
            return ['success' => false, 'error' => $data['error'] ?? 'API returned unsuccessful response'];
        }

        // Reviews may appear either top-level or under data.reviews depending on API version.
        $reviews = $data['reviews'] ?? ($data['data']['reviews'] ?? null);
        if (!is_array($reviews) || empty($reviews)) {
            return ['success' => false, 'error' => 'API returned no reviews'];
        }

        $clean = [];
        foreach ($reviews as $r) {
            if (!is_array($r)) continue;
            $author = trim((string)($r['author_name'] ?? ''));
            $content = TextSanitizer::stripEmoji((string)($r['content'] ?? ''));
            // Rating: float-safe (диапазон 4.5-5.0 с шагом 0.1). Раньше int-каст
            // обрезал 4.7→4, что выходило за границы заданного диапазона.
            $rating = (float)($r['rating'] ?? 5);
            if ($rating < 1.0) $rating = 5.0;
            if ($rating > 5.0) $rating = 5.0;
            $rating = round($rating, 1);
            if ($author === '' || trim($content) === '') continue;
            $reviewClean = [
                'author_name' => $author,
                'content' => $content,
                'rating' => $rating,
            ];
            // Split-режим: пробрасываем plusses/minuses если они пришли от сервера.
            // Раньше они стрипались — поэтому CustomBackend всегда видел только три
            // поля и записывал заглушки «—» в PLUSSES/MINUSES.
            if (isset($r['plusses']) && is_string($r['plusses']) && trim($r['plusses']) !== '') {
                $reviewClean['plusses'] = TextSanitizer::stripEmoji((string)$r['plusses']);
            }
            if (isset($r['minuses']) && is_string($r['minuses']) && trim($r['minuses']) !== '') {
                $reviewClean['minuses'] = TextSanitizer::stripEmoji((string)$r['minuses']);
            }
            $clean[] = $reviewClean;
        }
        if (!$clean) {
            return ['success' => false, 'error' => 'All reviews were empty after sanitization'];
        }
        return ['success' => true, 'reviews' => $clean];
    }

    public function ping(): array
    {
        $client = new HttpClient(['socketTimeout' => 10, 'streamTimeout' => 10]);
        $client->setHeader('Content-Type', 'application/json; charset=utf-8');
        $client->setHeader('Referer', self::getSiteUrl());
        $response = $client->post($this->endpoint, Json::encode(['action' => 'test']));
        if ($response === false) {
            return ['success' => false, 'error' => implode('; ', $client->getError() ?: ['transport failure'])];
        }
        return ['success' => true, 'status' => $client->getStatus(), 'body' => substr($response, 0, 500)];
    }

    private static function getSiteUrl(): string
    {
        $request = \Bitrix\Main\Context::getCurrent()->getRequest();
        $scheme = $request->isHttps() ? 'https' : 'http';
        $host = $request->getHttpHost();
        if (!$host) {
            $host = defined('SITE_SERVER_NAME') ? SITE_SERVER_NAME : 'localhost';
        }
        return $scheme . '://' . $host . '/';
    }
}
