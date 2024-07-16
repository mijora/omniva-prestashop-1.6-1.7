<?php

class OmnivaOrderHistory extends ObjectModel
{
    public $id;

    public $id_order;

    public $service_code;

    public $tracking_numbers;

    public $manifest;

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
            'service_code' =>        ['type' => self::TYPE_STRING, 'size' => 64],
            'tracking_numbers' =>    ['type' => self::TYPE_STRING, 'size' => 512],
            'manifest' =>            ['type' => self::TYPE_INT, 'size' => 10],
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

    public static function getLatestOrderHistory($id_order)
    {
        $query = (new DbQuery())
            ->select("id")
            ->from(self::$definition['table'])
            ->where('id_order = ' . (int) $id_order)
            ->orderBy('date_upd DESC');
            
        $orderHistoryId = Db::getInstance()->getValue($query);

        return $orderHistoryId ? new OmnivaOrderHistory($orderHistoryId) : null;
    }

    public static function getManifestOrders($id_manifest)
    {
        $query = (new DbQuery())
            ->select("id, id_order")
            ->from(self::$definition['table'])
            ->where('manifest = ' . $id_manifest);

        $orderHistoryEntries = Db::getInstance()->executeS($query);

        $orderHistoryEntriesUnique = [];
        foreach($orderHistoryEntries as $orderHistory)
        {
            $orderHistoryEntriesUnique[$orderHistory['id_order']] = $orderHistory;
        }

        return array_map(function($order) {
            return $order['id'];
        }, $orderHistoryEntriesUnique);
    }
}
