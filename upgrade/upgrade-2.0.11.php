<?php

function upgrade_module_2_0_11($module)
{
    return Db::getInstance()->execute('
        ALTER TABLE `' . _DB_PREFIX_ . 'omniva_order` CHANGE `cod_amount` `cod_amount` DECIMAL(8,2) NULL DEFAULT NULL;
    ');
}