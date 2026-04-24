<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);
?>
<div class="adm-info-message-wrap adm-info-message-green">
    <div class="adm-info-message">
        <?= Loc::getMessage('BLOCKSEE_AISEO_INSTALL_SUCCESS') ?>
        <br><br>
        <a href="/bitrix/admin/blocksee_aiseo_list.php?lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('BLOCKSEE_AISEO_INSTALL_GOTO') ?></a>
    </div>
</div>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANG ?>">
    <input type="submit" name="" value="<?= Loc::getMessage('MOD_BACK') ?>">
</form>
