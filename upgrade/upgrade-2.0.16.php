<?php

function upgrade_module_2_0_16($module)
{
    $module->registerHook('displayAdminProductsExtra');
    $module->registerHook('actionProductUpdate');

    return DB::getInstance()->execute('
        CREATE TABLE IF NOT EXISTS `'. _DB_PREFIX_ .'omniva_18_plus_product` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT(10) NOT NULL,
            `is_18_plus` TINYINT(1) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE='. _MYSQL_ENGINE_ .' DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ');
}