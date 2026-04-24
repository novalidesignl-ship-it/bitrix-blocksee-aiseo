<?php

namespace Blocksee\Aiseo\Reviews;

use Blocksee\Aiseo\Options;

class Factory
{
    public static function create(): ?Backend
    {
        $source = Options::resolveReviewsSource();
        if ($source === Options::REVIEWS_SOURCE_BLOG) {
            return new BlogBackend();
        }
        if ($source === Options::REVIEWS_SOURCE_FORUM) {
            return new ForumBackend();
        }
        return null;
    }

    public static function createOrFail(): Backend
    {
        $b = self::create();
        if ($b === null) {
            throw new \RuntimeException('Не настроен источник отзывов: ни blog, ни forum не доступны');
        }
        return $b;
    }
}
