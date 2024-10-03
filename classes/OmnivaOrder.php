<?php

class OmnivaOrder extends ObjectModel
{
    public $id;

    public $packs;

    public $cod;

    public $cod_amount;

    public $weight;

    public $tracking_numbers;

    public $error;

    /** @var string Object creation date */
    public $date_add;

    /** @var string Object last modification date */
    public $date_upd;

    public $date_track;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'omniva_order',
        'primary' => 'id',
        'fields' => [
            'packs' =>               ['type' => self::TYPE_INT, 'size' => 10],
            'cod' =>                 ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'cod_amount' =>          ['type' => self::TYPE_FLOAT],
            'weight' =>              ['type' => self::TYPE_FLOAT, 'size' => 10],
            'tracking_numbers' =>    ['type' => self::TYPE_STRING, 'size' => 512],
            'error' =>               ['type' => self::TYPE_STRING],
            'date_add' =>            ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' =>            ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_track' =>          ['type' => self::TYPE_DATE, 'allow_null' => true],
        ],
    ];

}
