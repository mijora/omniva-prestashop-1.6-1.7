<?php

function upgrade_module_2_2_5($module)
{
    $hooks = array(
        'displayOrderConfirmation',
        'displayOrderDetail',
        'actionEmailSendBefore'
    );
    foreach ( $hooks as $hook ) {
        if ( ! $module->registerHook($hook) ) {
            return false;
        }
    }

    return true;
}