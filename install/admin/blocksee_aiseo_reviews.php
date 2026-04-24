<?php

$root = $_SERVER['DOCUMENT_ROOT'];
foreach (['/local/modules/blocksee.aiseo/admin/reviews.php', '/bitrix/modules/blocksee.aiseo/admin/reviews.php'] as $p) {
    if (file_exists($root . $p)) {
        require_once $root . $p;
        return;
    }
}
