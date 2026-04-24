<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class blocksee_aiseo extends CModule
{
    public $MODULE_ID = 'blocksee.aiseo';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME = 'Cinar';
    public $PARTNER_URI = 'https://cinar.ru';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('BLOCKSEE_AISEO_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('BLOCKSEE_AISEO_MODULE_DESC');
    }

    public function DoInstall()
    {
        global $APPLICATION;

        ModuleManager::registerModule($this->MODULE_ID);
        $this->installFiles();
        $this->installDefaultOptions();
        $this->ensureReviewsForum();
        $this->ensureReviewsBlog();

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('BLOCKSEE_AISEO_INSTALL_TITLE'),
            __DIR__ . '/step.php'
        );
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        $this->uninstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('BLOCKSEE_AISEO_UNINSTALL_TITLE'),
            __DIR__ . '/unstep.php'
        );
    }

    public function installFiles()
    {
        $docRoot = Application::getDocumentRoot();
        CopyDirFiles(
            __DIR__ . '/admin/',
            $docRoot . '/bitrix/admin/',
            true,
            true
        );
        return true;
    }

    public function uninstallFiles()
    {
        $docRoot = Application::getDocumentRoot();
        foreach (['blocksee_aiseo_list.php', 'blocksee_aiseo_options.php', 'blocksee_aiseo_reviews.php'] as $f) {
            $path = $docRoot . '/bitrix/admin/' . $f;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        return true;
    }

    public function installDefaultOptions()
    {
        $defaults = [
            'api_endpoint' => 'https://lk.blocksee.ru/api.php',
            'target_field' => 'DETAIL_TEXT',
            'target_property_code' => '',
            'iblock_id' => '',
            'custom_prompt' => '',
            'temperature' => '0.7',
            'max_tokens' => '3000',
            'creative_mode' => 'N',
            'reviews_source' => 'auto',
            'reviews_blog_url' => 'catalog_comments',
            'reviews_blog_id' => '',
            'reviews_forum_id' => '',
            'reviews_per_product' => '3',
            'reviews_min_words' => '20',
            'reviews_max_words' => '60',
            'reviews_default_rating' => '5',
            'reviews_custom_prompt' => '',
            'reviews_auto_approve' => 'Y',
            'reviews_date_range_enabled' => 'Y',
            'reviews_date_from' => date('Y-m-d', strtotime('-2 years')),
            'reviews_date_to' => date('Y-m-d'),
        ];
        foreach ($defaults as $k => $v) {
            if (\Bitrix\Main\Config\Option::get($this->MODULE_ID, $k, null) === null) {
                \Bitrix\Main\Config\Option::set($this->MODULE_ID, $k, $v);
            }
        }
    }

    /**
     * Create a dedicated forum for AI-generated product reviews and save its ID.
     * Safe to call repeatedly: if forum already exists (by option or by name), it is reused.
     */
    public function ensureReviewsForum()
    {
        if (!ModuleManager::isModuleInstalled('forum')) {
            return;
        }
        if (!Loader::includeModule('forum')) {
            return;
        }

        $existingId = (int)\Bitrix\Main\Config\Option::get($this->MODULE_ID, 'reviews_forum_id', 0);
        if ($existingId > 0) {
            $row = \CForumNew::GetByID($existingId);
            if ($row && $row->Fetch()) {
                return;
            }
        }

        $forumName = 'Отзывы товаров (AI)';
        $rs = \CForumNew::GetList([], ['NAME' => $forumName]);
        if ($row = $rs->Fetch()) {
            \Bitrix\Main\Config\Option::set($this->MODULE_ID, 'reviews_forum_id', (int)$row['ID']);
            return;
        }

        // Collect active sites the forum should be visible on
        $sites = [];
        $rsSite = \CSite::GetList('sort', 'asc', ['ACTIVE' => 'Y']);
        while ($s = $rsSite->Fetch()) {
            $sites[$s['LID']] = ($s['DIR'] ?: '/');
        }
        if (!$sites) {
            $sites['s1'] = '/';
        }

        $fields = [
            'NAME' => $forumName,
            'DESCRIPTION' => 'Автоматически сгенерированные отзывы к товарам каталога.',
            'SORT' => 150,
            'ACTIVE' => 'Y',
            'ALLOW_HTML' => 'N',
            'ALLOW_ANCHOR' => 'Y',
            'ALLOW_BIU' => 'Y',
            'ALLOW_IMG' => 'N',
            'ALLOW_LIST' => 'Y',
            'ALLOW_QUOTE' => 'Y',
            'ALLOW_CODE' => 'N',
            'ALLOW_SMILES' => 'N',
            'ALLOW_NL2BR' => 'Y',
            'ALLOW_UPLOAD' => 'N',
            'ASK_GUEST_EMAIL' => 'N',
            'USE_CAPTCHA' => 'N',
            'SITES' => $sites,
        ];

        $forumId = (int)\CForumNew::Add($fields);
        if ($forumId > 0) {
            \Bitrix\Main\Config\Option::set($this->MODULE_ID, 'reviews_forum_id', $forumId);
        }

        $this->ensureForumMessageUserFields();
    }

    /**
     * Register UF_RATING and UF_AI_GENERATED on b_forum_message entity so that reviews
     * can carry a 1-5 star rating and an "AI-generated" flag compatible with standard
     * Bitrix / Aspro review templates.
     */
    public function ensureForumMessageUserFields()
    {
        if (!Loader::includeModule('forum')) {
            return;
        }
        $entity = 'FORUM_MESSAGE';
        $existing = [];
        $rs = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entity]);
        while ($r = $rs->Fetch()) {
            $existing[$r['FIELD_NAME']] = (int)$r['ID'];
        }

        $ute = new \CUserTypeEntity();

        if (empty($existing['UF_RATING'])) {
            $ute->Add([
                'ENTITY_ID' => $entity,
                'FIELD_NAME' => 'UF_RATING',
                'USER_TYPE_ID' => 'integer',
                'XML_ID' => 'UF_RATING',
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

        if (empty($existing['UF_AI_GENERATED'])) {
            $ute->Add([
                'ENTITY_ID' => $entity,
                'FIELD_NAME' => 'UF_AI_GENERATED',
                'USER_TYPE_ID' => 'integer',
                'XML_ID' => 'UF_AI_GENERATED',
                'SORT' => 110,
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

    /**
     * Лениво создаёт блог `catalog_comments` (для шаблонов с bitrix:catalog.comments + BLOG_USE=Y,
     * как Aspro Premier) и регистрирует UF-поля комментария, чтобы AI-генерируемые отзывы
     * сразу подхватывались штатным шаблоном.
     */
    public function ensureReviewsBlog()
    {
        if (!ModuleManager::isModuleInstalled('blog')) {
            return;
        }
        if (!Loader::includeModule('blog') || !Loader::includeModule('blocksee.aiseo')) {
            return;
        }
        // setupForIblock(0) создаёт сам блог и UF-поля комментария, без свойств инфоблока.
        $backend = new \Blocksee\Aiseo\Reviews\BlogBackend();
        $backend->setupForIblock(0);
    }
}
