<?php

function upgrade_module_2_2_0($module)
{
    $carriers = array('omnivalt_pt', 'omnivalt_c');
    foreach ($carriers as $key) {
        $carrier_id = (int) Configuration::get($key);
        if ($carrier_id && $carrier_id != 1) {
            Configuration::updateValue($key . '_id', $carrier_id);
            Configuration::deleteByName($key);
        }
    }
    return true;
}