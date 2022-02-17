<?php

class OmnivaOrderHistory extends ObjectModel
{
    public $id;

    public $id_order;

    public $tracking_numbers;

    /** @var string Object creation date */
    public $date_add;

    /** @var string Object last modification date */
    public $date_upd;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'omniva_order_history',
        'primary' => 'id',
        'fields' => [
            'id_order' =>            ['type' => self::TYPE_INT, 'size' => 10],
            'tracking_numbers' =>    ['type' => self::TYPE_STRING, 'size' => 512],
            'date_add' =>            ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' =>            ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public static function getHistoryByOrder($id_order)
    {
        $query = (new DbQuery())
            ->select("id")
            ->from(self::$definition['table'])
            ->where('id_order = ' . (int) $id_order);

        return array_map(function($orderHistory) {
                return new OmnivaOrderHistory($orderHistory['id']);
        }, Db::getInstance()->executeS($query));
    }
}
