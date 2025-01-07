<?php

function upgrade_module_2_1_1($module)
{
    return Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'omniva_order` ADD `date_track` DATETIME DEFAULT NULL');
}