<?php

namespace Blocksee\Aiseo\Reviews;

use Blocksee\Aiseo\Options;

/**
 * Пул юзеров-«персон», от лица которых публикуются AI-отзывы.
 *
 * Зачем: штатный bitrix:blog.post.comment.list при AUTHOR_ID > 0 читает имя автора
 * из связанного юзера, а AUTHOR_NAME коммента игнорирует. Поэтому, чтобы под отзывом
 * показывалось реальное русское имя, нужен реальный юзер с этим NAME/LAST_NAME.
 * Создаём пул один раз; дальше берём случайного из пула для каждого комментария.
 *
 * Все «персоны» помечены EXTERNAL_AUTH_ID = 'blocksee.aiseo' и заблокированы
 * (LOGIN не используется для входа), чтобы не путаться с реальными юзерами.
 */
class PersonaPool
{
    private const EXTERNAL_AUTH_ID = 'blocksee_persona';
    private const TARGET_SIZE = 50;

    private const FIRST_NAMES_M = [
        'Алексей', 'Андрей', 'Антон', 'Артём', 'Борис', 'Вадим', 'Валерий', 'Василий',
        'Виктор', 'Виталий', 'Владимир', 'Вячеслав', 'Геннадий', 'Григорий', 'Денис',
        'Дмитрий', 'Евгений', 'Егор', 'Иван', 'Игорь', 'Илья', 'Кирилл', 'Константин',
        'Леонид', 'Максим', 'Михаил', 'Никита', 'Николай', 'Олег', 'Павел', 'Пётр',
        'Роман', 'Руслан', 'Сергей', 'Станислав', 'Степан', 'Тимур', 'Фёдор', 'Юрий', 'Ярослав',
    ];

    private const FIRST_NAMES_F = [
        'Анна', 'Елена', 'Ирина', 'Марина', 'Наталья', 'Ольга', 'Светлана', 'Татьяна',
        'Юлия', 'Екатерина', 'Мария', 'Дарья',
    ];

    private const LAST_NAMES_M = [
        'Иванов', 'Смирнов', 'Кузнецов', 'Попов', 'Соколов', 'Михайлов', 'Новиков',
        'Фёдоров', 'Морозов', 'Волков', 'Алексеев', 'Лебедев', 'Семёнов', 'Егоров',
        'Павлов', 'Козлов', 'Степанов', 'Николаев', 'Орлов', 'Андреев', 'Макаров',
        'Никитин', 'Захаров', 'Зайцев', 'Соловьёв', 'Борисов', 'Яковлев', 'Григорьев',
        'Романов', 'Воробьёв', 'Сергеев', 'Кузьмин', 'Фролов', 'Александров', 'Дмитриев',
        'Королёв', 'Гусев', 'Киселёв', 'Ильин', 'Максимов',
    ];

    private const LAST_NAMES_F = [
        'Иванова', 'Смирнова', 'Кузнецова', 'Попова', 'Соколова', 'Михайлова', 'Новикова',
        'Фёдорова', 'Морозова', 'Волкова', 'Лебедева', 'Семёнова',
    ];

    /**
     * Возвращает массив ID юзеров-«персон». Лениво создаёт пул при первом обращении.
     * @return int[]
     */
    public static function getPoolIds(): array
    {
        $ids = self::loadStoredIds();
        $ids = self::filterAlive($ids);
        if (count($ids) >= self::TARGET_SIZE) {
            return $ids;
        }

        $existing = self::findAllExisting();
        if (count($existing) >= self::TARGET_SIZE) {
            self::storeIds($existing);
            return $existing;
        }

        $needed = self::TARGET_SIZE - count($existing);
        $created = self::createPersonas($needed);
        $all = array_values(array_unique(array_merge($existing, $created)));
        self::storeIds($all);
        return $all;
    }

    public static function pickRandomId(): int
    {
        $pool = self::getPoolIds();
        if (!$pool) return 0;
        return (int)$pool[array_rand($pool)];
    }

    /** @return int[] */
    private static function loadStoredIds(): array
    {
        $raw = (string)Options::get('reviews_persona_pool_ids', '');
        if ($raw === '') return [];
        $ids = array_filter(array_map('intval', explode(',', $raw)));
        return array_values($ids);
    }

    /** @param int[] $ids */
    private static function storeIds(array $ids): void
    {
        Options::set('reviews_persona_pool_ids', implode(',', array_map('intval', $ids)));
    }

    /**
     * @param int[] $ids
     * @return int[] только ещё существующие
     */
    private static function filterAlive(array $ids): array
    {
        if (!$ids) return [];
        $alive = [];
        $rs = \CUser::GetList(
            'ID', 'ASC',
            ['ID' => implode('|', array_map('intval', $ids))],
            ['FIELDS' => ['ID']]
        );
        while ($u = $rs->Fetch()) {
            $alive[] = (int)$u['ID'];
        }
        return $alive;
    }

    /** @return int[] */
    private static function findAllExisting(): array
    {
        $rs = \CUser::GetList('ID', 'ASC', ['EXTERNAL_AUTH_ID' => self::EXTERNAL_AUTH_ID], ['FIELDS' => ['ID']]);
        $out = [];
        while ($u = $rs->Fetch()) {
            $out[] = (int)$u['ID'];
        }
        return $out;
    }

    /**
     * @return int[] ID созданных юзеров
     */
    private static function createPersonas(int $count): array
    {
        $created = [];
        $usedLogins = [];

        for ($i = 0; $i < $count; $i++) {
            $isMale = (random_int(0, 9) < 8); // ~80% мужчин (профиль каталога)
            $first = $isMale
                ? self::FIRST_NAMES_M[array_rand(self::FIRST_NAMES_M)]
                : self::FIRST_NAMES_F[array_rand(self::FIRST_NAMES_F)];
            $last = $isMale
                ? self::LAST_NAMES_M[array_rand(self::LAST_NAMES_M)]
                : self::LAST_NAMES_F[array_rand(self::LAST_NAMES_F)];

            $loginBase = 'persona_' . bin2hex(random_bytes(4));
            while (isset($usedLogins[$loginBase])) {
                $loginBase = 'persona_' . bin2hex(random_bytes(4));
            }
            $usedLogins[$loginBase] = true;

            $password = bin2hex(random_bytes(16));
            $user = new \CUser();
            $id = (int)$user->Add([
                'LOGIN' => $loginBase,
                'PASSWORD' => $password,
                'CONFIRM_PASSWORD' => $password,
                'EMAIL' => $loginBase . '@local.invalid',
                'NAME' => $first,
                'LAST_NAME' => $last,
                'ACTIVE' => 'N', // запрещаем логин
                'GROUP_ID' => [2],
                'EXTERNAL_AUTH_ID' => self::EXTERNAL_AUTH_ID,
                'XML_ID' => 'blocksee_persona',
            ]);
            if ($id > 0) {
                $created[] = $id;
            }
        }
        return $created;
    }
}
