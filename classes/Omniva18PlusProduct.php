<?php

class Omniva18PlusProduct extends ObjectModel
{
    /** @var int $id */
    public $id;

    /** @var int $id_product */
    public $id_product;

    /** @var bool $is_18_plus */
    public $is_18_plus;

    /** @var string Object creation date */
    public $date_add;

    /** @var string Object last modification date */
    public $date_upd;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'omniva_18_plus_product',
        'primary' => 'id',
        'fields' => [
            'id_product' => [
                'type' => self::TYPE_INT,
            ],
            'is_18_plus' => [
                'type' => self::TYPE_BOOL,
                'validate' => 'isBool'
            ]
        ]
    ];

    /**
     * Returns single value of is product for 18+ or array
     *
     * @param int $id_product Product ID
     * @param bool $singleValue Whether to return single or array
     * @return array|bool|mysqli_result|PDOStatement|resource|string|null
     * @throws PrestaShopDatabaseException
     */
    public static function get18PlusStatus($id_product, $singleValue = false)
    {
        $query = (new DbQuery())
            ->select('is_18_plus')
            ->from(self::$definition['table'])
            ->where('id_product = ' . (int) $id_product);

        return $singleValue
            ? DB::getInstance()->getValue($query)
            : DB::getInstance()->executeS($query);
    }
}