<?php

function upgrade_module_2_3_2($module)
{
    return $module->registerHook('displayTop');
}