<?php

function upgrade_module_2_0_1($module)
{
    return $module->registerHook('actionObjectOrderUpdateAfter');
}