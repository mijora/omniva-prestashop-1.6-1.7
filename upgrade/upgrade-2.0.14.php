<?php

function upgrade_module_2_0_14($module)
{
    return Db::getInstance()->execute('
        ALTER TABLE `' . _DB_PREFIX_ . 'omniva_cart_terminal` CHANGE `id_terminal` `id_terminal` VARCHAR(50) NOT NULL;
    ');
}