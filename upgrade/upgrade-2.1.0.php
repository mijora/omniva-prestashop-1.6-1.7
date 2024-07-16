<?php

function upgrade_module_2_1_0($module)
{
    $result = DB::getInstance()->execute('SHOW TABLES LIKE "' . _DB_PREFIX_ . 'omniva_18_plus_product"');
    if ($result) {
        $result = DB::getInstance()->execute('RENAME TABLE `' . _DB_PREFIX_ . 'omniva_18_plus_product` TO `' . _DB_PREFIX_ . 'omniva_product`');
        if (!$result) {
            return false;
        }
        $result = Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'omniva_product`ADD `is_fragile` TINYINT(1) NOT NULL');
        if (!$result) {
            return false;
        }
        return true;
    }

    $result = DB::getInstance()->execute('
        CREATE TABLE IF NOT EXISTS `'. _DB_PREFIX_ .'omniva_product` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT(10) NOT NULL,
            `is_18_plus` TINYINT(1) NOT NULL,
            `is_fragile` TINYINT(1) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE='. _MYSQL_ENGINE_ .' DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ');
    if (!$result) {
        return false;
    }

    return true;
}