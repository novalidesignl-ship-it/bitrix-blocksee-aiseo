<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);
?>
<div class="adm-info-message-wrap adm-info-message-green">
    <div class="adm-info-message">
        <?= Loc::getMessage('BLOCKSEE_AISEO_UNINSTALL_SUCCESS') ?>
    </div>
</div>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANG ?>">
    <input type="submit" name="" value="<?= Loc::getMessage('MOD_BACK') ?>">
</form>
