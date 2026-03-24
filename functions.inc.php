<?php
// FreePBX Module: outcidmap
// functions.inc.php — устаревший API compatibility layer
// Необходим для совместимости с FreePBX 16 (некоторые хуки ищут функции)

/**
 * Вызывается при генерации dialplan (Apply Config).
 * Возвращает конфигурацию для macro-dialout-trunk-predial-hook.
 */
function outcidmap_get_config($engine) {
    $module = \FreePBX::Outcidmap();
    return $module->generateDialplan($engine);
}
