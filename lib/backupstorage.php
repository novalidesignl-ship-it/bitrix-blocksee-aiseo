<?php

namespace Blocksee\Aiseo;

use Bitrix\Main\Application;

/**
 * Хранилище резервных копий описаний товаров.
 *
 * Перед каждой AI-генерацией старое значение целевого поля копируется в таблицу
 * `b_blocksee_aiseo_backups`. Это позволяет восстановить предыдущую версию,
 * если новый текст оказался хуже.
 *
 * Хранится история всех версий, но с лимитом MAX_VERSIONS_PER_ELEMENT
 * на один товар + одно поле (старые удаляются автоматически).
 */
class BackupStorage
{
    public const TABLE = 'b_blocksee_aiseo_backups';
    public const MAX_VERSIONS_PER_ELEMENT = 10;

    /** @var bool|null Кеш проверки существования таблицы в рамках одного PHP-процесса. */
    private static $tableEnsured = null;

    public static function ensureTable(): void
    {
        if (self::$tableEnsured === true) {
            return;
        }
        $conn = Application::getConnection();
        if ($conn->isTableExists(self::TABLE)) {
            self::$tableEnsured = true;
            return;
        }
        $sql = "CREATE TABLE `" . self::TABLE . "` (
            ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ELEMENT_ID INT UNSIGNED NOT NULL,
            IBLOCK_ID INT UNSIGNED NOT NULL,
            FIELD VARCHAR(64) NOT NULL,
            ORIGINAL_VALUE LONGTEXT NULL,
            NEW_VALUE LONGTEXT NULL,
            USER_ID INT UNSIGNED NULL,
            CREATED_AT DATETIME NOT NULL,
            PRIMARY KEY (ID),
            KEY IDX_ELEMENT (ELEMENT_ID, FIELD, CREATED_AT),
            KEY IDX_IBLOCK (IBLOCK_ID, CREATED_AT)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $conn->queryExecute($sql);
        self::$tableEnsured = true;
    }

    public static function dropTable(): void
    {
        $conn = Application::getConnection();
        if ($conn->isTableExists(self::TABLE)) {
            $conn->queryExecute('DROP TABLE `' . self::TABLE . '`');
        }
    }

    public static function save(
        int $elementId,
        int $iblockId,
        string $field,
        ?string $originalValue,
        ?string $newValue = null,
        ?int $userId = null
    ): void {
        if ($elementId <= 0) return;
        // Не сохраняем, если оригинал реально пустой — нечего бэкапить
        if ($originalValue === null || trim((string)$originalValue) === '') {
            return;
        }

        // Lazy create: на проде модуль уже установлен и DoInstall() заново не запускается.
        // Поэтому таблицу создаём при первой записи, если её нет.
        self::ensureTable();

        $conn = Application::getConnection();
        $helper = $conn->getSqlHelper();
        $sql = 'INSERT INTO `' . self::TABLE . '` '
            . '(ELEMENT_ID, IBLOCK_ID, FIELD, ORIGINAL_VALUE, NEW_VALUE, USER_ID, CREATED_AT) '
            . "VALUES ({$elementId}, {$iblockId}, '" . $helper->forSql($field) . "', "
            . "'" . $helper->forSql((string)$originalValue) . "', "
            . ($newValue === null ? 'NULL' : "'" . $helper->forSql((string)$newValue) . "'") . ', '
            . ($userId === null ? 'NULL' : (int)$userId) . ', '
            . 'NOW())';
        $conn->queryExecute($sql);

        self::cleanupOld($elementId, $field);
    }

    /**
     * Удаляет старые версии, оставляя только MAX_VERSIONS_PER_ELEMENT последних.
     */
    public static function cleanupOld(int $elementId, string $field): void
    {
        $conn = Application::getConnection();
        $helper = $conn->getSqlHelper();
        $field = $helper->forSql($field);
        $limit = self::MAX_VERSIONS_PER_ELEMENT;
        $sql = "SELECT ID FROM `" . self::TABLE . "`
                WHERE ELEMENT_ID = {$elementId} AND FIELD = '{$field}'
                ORDER BY CREATED_AT DESC, ID DESC
                LIMIT {$limit}, 1000000";
        $rs = $conn->query($sql);
        $idsToDelete = [];
        while ($row = $rs->fetch()) {
            $idsToDelete[] = (int)$row['ID'];
        }
        if ($idsToDelete) {
            $conn->queryExecute('DELETE FROM `' . self::TABLE . '` WHERE ID IN (' . implode(',', $idsToDelete) . ')');
        }
    }

    /**
     * Возвращает последнюю сохранённую копию для товара и поля.
     *
     * @return array{id:int, original_value:string, new_value:?string, created_at:string}|null
     */
    public static function getLatest(int $elementId, string $field): ?array
    {
        $conn = Application::getConnection();
        $helper = $conn->getSqlHelper();
        $field = $helper->forSql($field);
        $sql = "SELECT ID, ORIGINAL_VALUE, NEW_VALUE, CREATED_AT
                FROM `" . self::TABLE . "`
                WHERE ELEMENT_ID = {$elementId} AND FIELD = '{$field}'
                ORDER BY CREATED_AT DESC, ID DESC
                LIMIT 1";
        $row = $conn->query($sql)->fetch();
        if (!$row) return null;
        return [
            'id' => (int)$row['ID'],
            'original_value' => (string)$row['ORIGINAL_VALUE'],
            'new_value' => $row['NEW_VALUE'] === null ? null : (string)$row['NEW_VALUE'],
            'created_at' => (string)$row['CREATED_AT'],
        ];
    }

    /**
     * Возвращает все версии для товара и поля (последние сверху).
     *
     * @return array<int, array{id:int, original_value:string, new_value:?string, created_at:string}>
     */
    public static function getHistory(int $elementId, string $field, int $limit = 20): array
    {
        $conn = Application::getConnection();
        $helper = $conn->getSqlHelper();
        $field = $helper->forSql($field);
        $limit = max(1, min(100, $limit));
        $sql = "SELECT ID, ORIGINAL_VALUE, NEW_VALUE, CREATED_AT
                FROM `" . self::TABLE . "`
                WHERE ELEMENT_ID = {$elementId} AND FIELD = '{$field}'
                ORDER BY CREATED_AT DESC, ID DESC
                LIMIT {$limit}";
        $rs = $conn->query($sql);
        $rows = [];
        while ($row = $rs->fetch()) {
            $rows[] = [
                'id' => (int)$row['ID'],
                'original_value' => (string)$row['ORIGINAL_VALUE'],
                'new_value' => $row['NEW_VALUE'] === null ? null : (string)$row['NEW_VALUE'],
                'created_at' => (string)$row['CREATED_AT'],
            ];
        }
        return $rows;
    }
}
