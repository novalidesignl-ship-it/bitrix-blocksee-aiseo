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
            'action' => 'generate_product_description',
            'product_data' => $productData,
            'custom_prompt' => $customPrompt,
            'settings' => [
                'temperature' => (float)($settings['temperature'] ?? 0.7),
                'max_tokens' => (int)($settings['max_tokens'] ?? 3000),
                'creative_mode' => !empty($settings['creative_mode']),
                'quality' => (($settings['quality'] ?? 'standard') === 'high') ? 'high' : 'standard',
            ],
        ];

        $client = new HttpClient([
            'socketTimeout' => 90,
            'streamTimeout' => 90,
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
            'action' => 'generate_product_reviews',
            'product_data' => $productData,
            'count' => $count,
            'request_id' => bin2hex(random_bytes(8)),
            'settings' => [
                'min_words' => (int)($settings['min_words'] ?? 20),
                'max_words' => (int)($settings['max_words'] ?? 60),
                'rating' => (int)($settings['rating'] ?? 5),
                'custom_prompt' => (string)($settings['custom_prompt'] ?? ''),
                'temperature' => (float)($settings['temperature'] ?? 0.8),
                'creative_mode' => !empty($settings['creative_mode']),
                'quality' => (($settings['quality'] ?? 'standard') === 'high') ? 'high' : 'standard',
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
            $rating = (int)($r['rating'] ?? 5);
            if ($rating < 1 || $rating > 5) $rating = 5;
            if ($author === '' || trim($content) === '') continue;
            $clean[] = [
                'author_name' => $author,
                'content' => $content,
                'rating' => $rating,
            ];
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
