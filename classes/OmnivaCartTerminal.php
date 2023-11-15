<?php

class OmnivaCartTerminal extends ObjectModel
{
    public $id;

    public $id_terminal;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'omniva_cart_terminal',
        'primary' => 'id_cart',
        'fields' => [
            'id_terminal' =>   ['type' => self::TYPE_STRING, 'required' => true, 'size' => 50],
        ],
    ];

}
