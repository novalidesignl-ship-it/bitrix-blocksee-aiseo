<?php

$root = $_SERVER['DOCUMENT_ROOT'];
foreach (['/local/modules/blocksee.aiseo/admin/list.php', '/bitrix/modules/blocksee.aiseo/admin/list.php'] as $p) {
    if (file_exists($root . $p)) {
        require_once $root . $p;
        return;
    }
}
