<?php

function upgrade_module_2_3_0($module)
{
    $module->registerHook('displayCarrierExtraContent');
}