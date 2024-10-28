<?php

use Mijora\Omniva\Locations\PickupPoints;
use Mijora\Omniva\OmnivaException;

class OmnivaHelper
{
    const OMNIVA_LOCATIONS_JSON = 'https://omniva.ee/locationsfull.json';

    const PARCELS_JSON_DIR = _PS_MODULE_DIR_ . 'omnivaltshipping/';

    const PARCELS_JSON_FILENAME = 'locations.json';

    const ENABLE_LOGS = false;

    public function updateTerminals()
    {
        $omnivaPickupPoints = new PickupPoints();
        try {
            // $pickups = $omnivaPickupPoints->getFilteredLocations();
            $pickups = file_get_contents(self::OMNIVA_LOCATIONS_JSON);
        } catch (OmnivaException $e) {
            return false;
        }
        if($pickups)
        {
            $terminals = json_decode($pickups, true);

            $pickups = array_filter($terminals, function($item) {
                return  (int) $item['TYPE'] !== 1 && (float) $item['X_COORDINATE'] > 0 && (float) $item['Y_COORDINATE'] > 0;
            });

            $pickupsJson = json_encode($pickups);
            if($this->isJson($pickupsJson))
            {
                file_put_contents(self::PARCELS_JSON_DIR . self::PARCELS_JSON_FILENAME, $pickupsJson);
                return true;
            }
        }
        return false;
    }

    private function isJson($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function printToLog($log_data, $file_name = 'debug')
    {
        if ( ! self::ENABLE_LOGS ) {
            return;
        }

        if ( ! preg_match('/^[A-Za-z0-9_-]*$/', $file_name) ) {
            $file_name = 'debug';
        }
        
        $dir = self::PARCELS_JSON_DIR . 'logs';
        if ( ! file_exists($dir) ) {
            mkdir($dir, 0755, true);
        }

        $time = date('Y-m-d H:i:s');
        $content = '[' . $time . '] ' . print_r($log_data, true);
        file_put_contents($dir . '/' . $file_name . '.log', $content . PHP_EOL, FILE_APPEND);
    }

    public static function getCartItems($cart_products, $split_quantity = false)
    {
        $items = array();
        $units = array(
            'dimensions' => Configuration::get('PS_DIMENSION_UNIT'),
            'weight' => Configuration::get('PS_WEIGHT_UNIT'),
        );
        foreach ( $cart_products as $cart_prod ) {
            if ( ! isset($cart_prod['id_product']) ) {
                continue;
            }
            if ( isset($cart_prod['is_virtual']) && (bool) $cart_prod['is_virtual'] ) {
                continue;
            }
            $item = array(
                'product_id' => $cart_prod['id_product'],
                'product_attribute_id' => isset($cart_prod['id_product_attribute']) ? $cart_prod['id_product_attribute'] : 0,
                'shop_id' => isset($cart_prod['id_shop']) ? $cart_prod['id_shop'] : 1,
                'quantity' => isset($cart_prod['cart_quantity']) ? $cart_prod['cart_quantity'] : 1,
                'name' => isset($cart_prod['name']) ? $cart_prod['name'] : '',
                'price_org' => isset($cart_prod['price_without_reduction']) ? $cart_prod['price_without_reduction'] : 0,
                'discount' => isset($cart_prod['reduction']) ? $cart_prod['reduction'] : 0,
                'price' => isset($cart_prod['price_with_reduction']) ? $cart_prod['price_with_reduction'] : 0,
                'price_total' => isset($cart_prod['total_wt']) ? $cart_prod['total_wt'] : 0,
                'tax_rate' => isset($cart_prod['rate']) ? $cart_prod['rate'] : 0,
                'tax_name' => isset($cart_prod['tax_name']) ? $cart_prod['tax_name'] : '',
                'attribute' => isset($cart_prod['attributes']) ? $cart_prod['attributes'] : '',
                'weight' => isset($cart_prod['weight']) ? $cart_prod['weight'] : 0,
                'width' => isset($cart_prod['width']) ? $cart_prod['width'] : 0,
                'height' => isset($cart_prod['height']) ? $cart_prod['height'] : 0,
                'length' => isset($cart_prod['depth']) ? $cart_prod['depth'] : 0,
                'dimensions_unit' => Configuration::get('PS_DIMENSION_UNIT'),
                'weight_unit' => Configuration::get('PS_WEIGHT_UNIT'),
            );
            if ( $split_quantity && $item['quantity'] > 1 ) {
                for ( $i = 0; $i < $item['quantity']; $i++ ) {
                    $splited_item = $item;
                    $splited_item['quantity'] = 1;
                    $splited_item['price_total'] = $item['price'];
                    $items[] = $splited_item;
                }
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }

    public static function convertWeightUnit( $value, $unit_to, $unit_from = false )
    {
        if ( ! $unit_from ) {
            $unit_from = Configuration::get('PS_WEIGHT_UNIT');
        }
        if ( empty($unit_from) ) {
            $unit_from = 'kg';
        }

        $converted_weight = $value;
        if ( $unit_from === $unit_to ) {
            return $converted_weight;
        }

        switch ($unit_from) { //Convert to kg
            case 'mg':
                $value_in_kg =  $value / 1000000;
                break;
            case 'g':
                $value_in_kg =  $value / 1000;
                break;
            case 'kg':
                $value_in_kg = $value;
                break;
            case 't':
                $value_in_kg = $value * 1000;
                break;
            default:
                $value_in_kg = $value;
        }
        switch ($unit_to) { //Convert to unit_to
            case 'mg':
                $converted_weight =  $value_in_kg * 1000000;
                break;
            case 'g':
                $converted_weight =  $value_in_kg * 1000;
                break;
            case 'kg':
                $converted_weight = $value_in_kg;
                break;
            case 't':
                $converted_weight = $value_in_kg / 1000;
                break;
            default:
                $converted_weight = $value_in_kg;
        }

        return $converted_weight;
    }

    public static function convertDimensionsUnit( $value, $unit_to, $unit_from = false )
    {
        if ( ! $unit_from ) {
            $unit_from = Configuration::get('PS_DIMENSION_UNIT');
        }
        if ( empty($unit_from) ) {
            $unit_from = 'cm';
        }

        $converted_dim = $value;
        if ( $unit_from === $unit_to ) {
            return $converted_dim;
        }

        switch ($unit_from) { //Convert to cm
            case 'mm':
                $value_in_cm =  $value / 10;
                break;
            case 'cm':
                $value_in_cm =  $value;
                break;
            case 'dm':
                $value_in_cm = $value * 10;
                break;
            case 'm':
                $value_in_cm = $value * 100;
                break;
            default:
                $value_in_cm = $value;
        }
        switch ($unit_to) { //Convert to unit_to
            case 'mm':
                $converted_dim =  $value_in_cm * 10;
                break;
            case 'cm':
                $converted_dim =  $value_in_cm;
                break;
            case 'dm':
                $converted_dim = $value_in_cm / 10;
                break;
            case 'm':
                $converted_dim = $value_in_cm / 100;
                break;
            default:
                $converted_dim = $value_in_cm;
        }

        return $converted_dim;
    }

    public static function predictOrderSize( $items_data, $max_dimension = array() )
    {
        $all_order_dim_length = 0;
        $all_order_dim_width = 0;
        $all_order_dim_height = 0;
        $max_dim_length = (!empty($max_dimension['length'])) ? $max_dimension['length'] : 999999;
        $max_dim_width = (!empty($max_dimension['width'])) ? $max_dimension['width'] : 999999;
        $max_dim_height = (!empty($max_dimension['height'])) ? $max_dimension['height'] : 999999;

        foreach ( $items_data as $item ) {
            $item_dim_length = (!empty($item['length'])) ? $item['length'] : 0;
            $item_dim_width = (!empty($item['width'])) ? $item['width'] : 0;
            $item_dim_height = (!empty($item['height'])) ? $item['height'] : 0;

            //Add to length
            if ( ($item_dim_length + $all_order_dim_length) <= $max_dim_length 
                && $item_dim_width <= $max_dim_width && $item_dim_height <= $max_dim_height )
            {
                $all_order_dim_length = $all_order_dim_length + $item_dim_length;
                $all_order_dim_width = ($item_dim_width > $all_order_dim_width) ? $item_dim_width : $all_order_dim_width;
                $all_order_dim_height = ($item_dim_height > $all_order_dim_height) ? $item_dim_height : $all_order_dim_height;
            }
            //Add to width
            else if ( ($item_dim_width + $all_order_dim_width) <= $max_dim_width 
                && $item_dim_length <= $max_dim_length && $item_dim_height <= $max_dim_height )
            {
                $all_order_dim_length = ($item_dim_length > $all_order_dim_length) ? $item_dim_length : $all_order_dim_length;
                $all_order_dim_width = $all_order_dim_width + $item_dim_width;
                $all_order_dim_height = ($item_dim_height > $all_order_dim_height) ? $item_dim_height : $all_order_dim_height;
            }
            //Add to height
            else if ( ($item_dim_height + $all_order_dim_height) <= $max_dim_height 
                && $item_dim_length <= $max_dim_length && $item_dim_width <= $max_dim_width )
            {
                $all_order_dim_length = ($item_dim_length > $all_order_dim_length) ? $item_dim_length : $all_order_dim_length;
                $all_order_dim_width = ($item_dim_width > $all_order_dim_width) ? $item_dim_width : $all_order_dim_width;
                $all_order_dim_height = $all_order_dim_height + $item_dim_height;
            }
            //If all fails
            else {
                return false;
            }
        }

        return array(
            'length' => (float) $all_order_dim_length,
            'width' => (float) $all_order_dim_width,
            'height' => (float) $all_order_dim_height,
        );
    }

    public static function getScheduledCourierCalls()
    {
        $all_calls = unserialize(Configuration::get('omnivalt_courier_calls'));
        if ( ! $all_calls ) {
            return array();
        }

        foreach ( $all_calls as $id => $time ) {
            if ( time() > strtotime($time['start']) && time() > strtotime($time['end']) ) {
                unset($all_calls[$id]);
            }
        }

        return $all_calls;
    }

    public static function splitScheduledCourierCalls( $calls )
    {
        $splited_calls = array();
        foreach ( $calls as $id => $time ) {
            $splited_calls[$id] = array(
                'id' => $id,
                'start_date' => date('Y-m-d', strtotime($time['start'])),
                'start_time' => date('H:i', strtotime($time['start'])),
                'end_date' => date('Y-m-d', strtotime($time['end'])),
                'end_time' => date('H:i', strtotime($time['end'])),
            );
        }

        return $splited_calls;
    }

    public static function addScheduledCourierCall( $id, $start_time, $end_time )
    {
        $all_calls = self::getScheduledCourierCalls();
        $all_calls[$id] = array('start' => $start_time, 'end' => $end_time);

        Configuration::updateValue('omnivalt_courier_calls', serialize($all_calls));
    }

    public static function removeScheduledCourierCall( $id )
    {
        $all_calls = self::getScheduledCourierCalls();
        if ( isset($all_calls[$id]) ) {
            unset($all_calls[$id]);
            Configuration::updateValue('omnivalt_courier_calls', serialize($all_calls));

            return true;
        }

        return false;
    }

    public static function getEuCountriesList( $lang_id )
    {
        $countries_list = array();
        $all_countries = Country::getCountries($lang_id, false, false, false);
        $eu_iso_codes = array('BE', 'BG', 'CZ', 'DK', 'DE', 'EE', 'IE', 'GR', 'ES', 'FR', 'HR', 'IT', 'CY', 'LV', 'LT', 'LU', 'HU', 'MT', 'NL', 'AT', 'PL', 'PT', 'RO', 'SI', 'SK', 'FI', 'SE');

        foreach ( $all_countries as $country ) {
            if ( in_array(strtoupper($country['iso_code']), $eu_iso_codes) ) {
                $countries_list[strtoupper($country['iso_code'])] = $country['name'];
            }
        }

        return $countries_list;
    }

    public static function buildExceptionMessage( $exception, $prefix = '' )
    {
        $msg = $prefix;
        if ( ! empty($msg) ) {
            $msg .= '. ';
        }

        return $msg . $exception->getMessage() . '. In ' . basename($exception->getFile()) . ':' . $exception->getLine();
    }
}