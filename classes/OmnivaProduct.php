<?php

class OmnivaProduct extends ObjectModel
{
    /** @var int $id */
    public $id;

    /** @var int $id_product */
    public $id_product;

    /** @var bool $is_18_plus */
    public $is_18_plus;

    /** @var bool $is_fragile */
    public $is_fragile;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'omniva_product',
        'primary' => 'id',
        'fields' => [
            'id_product' => [
                'type' => self::TYPE_INT,
            ],
            'is_18_plus' => [
                'type' => self::TYPE_BOOL,
                'validate' => 'isBool'
            ],
            'is_fragile' => [
                'type' => self::TYPE_BOOL,
                'validate' => 'isBool'
            ],
        ]
    ];

    /**
     * Check if record exists
     *
     * @param int $id_product Product ID
     * @return array|bool|mysqli_result|PDOStatement|resource|string|null
     * @throws PrestaShopDatabaseException
     */
    public static function isExists($id_product)
    {
        $query = (new DbQuery())
            ->select('id')
            ->from(self::$definition['table'])
            ->where('id_product = ' . (int) $id_product);

        return DB::getInstance()->getValue($query);
    }

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

    /**
     * Returns single value of is fragile or array
     *
     * @param int $id_product Product ID
     * @param bool $singleValue Whether to return single or array
     * @return array|bool|mysqli_result|PDOStatement|resource|string|null
     * @throws PrestaShopDatabaseException
     */
    public static function getFragileStatus($id_product, $singleValue = false)
    {
        $query = (new DbQuery())
            ->select('is_fragile')
            ->from(self::$definition['table'])
            ->where('id_product = ' . (int) $id_product);

        return $singleValue
            ? DB::getInstance()->getValue($query)
            : DB::getInstance()->executeS($query);
    }
}