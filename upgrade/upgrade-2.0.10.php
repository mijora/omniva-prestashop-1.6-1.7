<?php

function upgrade_module_2_0_10($module)
{
    return Db::getInstance()->execute('
        ALTER TABLE `' . _DB_PREFIX_ . 'omniva_order` MODIFY `error` TEXT;
    ');
}