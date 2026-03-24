<?php
// FreePBX Module: outcidmap
// uninstall.php — выполняется при удалении модуля

function outcidmap_uninstall() {
    // Таблицы специально НЕ удаляем при деинсталляции,
    // чтобы данные сохранились при переустановке.
    // Если нужна полная очистка — раскомментируйте:
    //
    // global $db;
    // $db->query("DROP TABLE IF EXISTS outcidmap_mapping");
    // $db->query("DROP TABLE IF EXISTS outcidmap_groups");
}
