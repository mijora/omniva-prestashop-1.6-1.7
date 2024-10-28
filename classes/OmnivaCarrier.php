<?php

class OmnivaCarrier
{
    public static function getAllMethods()
    {
        return array(
            //"method_key" => "Public carrier name",
            'omnivalt_pt' => 'Parcel terminal',
            'omnivalt_c' => 'Courier',
            'omnivalt_int_premium' => 'International (Premium)',
            'omnivalt_int_standard' => 'International (Standard)',
            'omnivalt_int_economy' => 'International (Economy)',
        );
    }

    public static function getIdKey($method_key)
    {
        return $method_key . '_id';
    }

    public static function getReferenceKey($method_key)
    {
        return $method_key . '_reference';
    }

    public static function updateMappingValues($method_key, $carrier_id, $carrier_reference = false)
    {
        Configuration::updateValue(self::getIdKey($method_key), $carrier_id);
        if ($carrier_reference) {
            Configuration::updateValue(self::getReferenceKey($method_key), $carrier_reference);
        }
    }

    public static function getId($method_key)
    {
        return Configuration::get(self::getIdKey($method_key));
    }

    public static function getReference($method_key)
    {
        return Configuration::get(self::getReferenceKey($method_key));
    }

    public static function getAllMethodsData()
    {
        $methods_data = array();
        foreach ( self::getAllMethods() as $key => $title ) {
            $short_key = OmnivaApiInternational::getPackageKeyFromMethodKey($key);
            $methods_data[$short_key] = array(
                'id' => $key,
                'title' => $title,
                'carrier_id' => self::getId($key),
                'carrier_reference' => self::getReference($key),
                'is_international' => OmnivaApiInternational::isInternationalMethod($key),
            );
        }

        return $methods_data;
    }

    public static function createCarrier($method_key, $title, $module_name, $logo_dir = false)
    {
        $carrier = new \Carrier();
        $carrier->name = $title;
        $carrier->active = true;
        $carrier->deleted = 0;
        $carrier->shipping_handling = true;
        $carrier->range_behavior = 0;
        $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = '1-2 business days';
        $carrier->shipping_external = true;
        $carrier->is_module = true;
        $carrier->external_module_name = $module_name;
        $carrier->need_range = true;
        $carrier->url = "https://www.omniva.lt/verslo/siuntos_sekimas?barcode=@";

        if ($carrier->add()) {
            $groups = \Group::getGroups(true);
            foreach ($groups as $group) {
                Db::getInstance()->insert('carrier_group', array(
                    'id_carrier' => (int)$carrier->id,
                    'id_group' => (int)$group['id_group']
                ));
            }

            $rangePrice = new \RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '1000';
            $rangePrice->add();

            $rangeWeight = new \RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '1000';
            $rangeWeight->add();

            $zones = \Zone::getZones(true);
            foreach ($zones as $z) {
                \Db::getInstance()->insert(
                    'carrier_zone',
                    array('id_carrier' => (int)$carrier->id, 'id_zone' => (int)$z['id_zone'])
                );
                \Db::getInstance()->insert(
                    'delivery',
                    array('id_carrier' => $carrier->id, 'id_range_price' => (int)$rangePrice->id, 'id_range_weight' => NULL, 'id_zone' => (int)$z['id_zone'], 'price' => '0'),
                    true
                );
                \Db::getInstance()->insert(
                    'delivery',
                    array('id_carrier' => $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int)$rangeWeight->id, 'id_zone' => (int)$z['id_zone'], 'price' => '0'),
                    true
                );
            }

            if ($logo_dir) {
                copy($logo_dir, _PS_SHIP_IMG_DIR_ . '/' . (int)$carrier->id . '.jpg'); //assign carrier logo
            }

            self::updateMappingValues($method_key, $carrier->id, $carrier->id);

            return true;
        }

        return false;
    }

    public static function getCarrierMethodKey($carrier_id = false, $carrier_ref_id = false)
    {
        $method_key = false;
        foreach ( self::getAllMethods() as $key => $title ) {
            if ( $carrier_id && $carrier_id == OmnivaCarrier::getId($key) ) {
                $method_key = $key;
            } else if ( $carrier_ref_id && $carrier_ref_id == OmnivaCarrier::getReference($key) ) {
                $method_key = $key;
            }
        }

        return $method_key;
    }

    public static function getCarrier($method_key)
    {
        $carrier_id = Configuration::get(self::getIdKey($method_key));
        if (!$carrier_id) {
            return false;
        }
        return new \Carrier($carrier_id);
    }

    public static function getCarrierById($carrier_id)
    {
        $carrier = new \Carrier((int)$carrier_id);
        return (! empty($carrier->id)) ? $carrier : false;
    }

    public static function isOmnivaCarrier($carrier_id = false, $carrier_ref_id = false)
    {
        return (self::getCarrierMethodKey($carrier_id, $carrier_ref_id)) ? true : false;
    }

    public static function markAsDeleted($method_key)
    {
        $carrier = self::getCarrier($method_key);
        if (!$carrier) {
            return false;
        }
        $carrier->deleted = true;
        $carrier->update();

        return true;
    }

    public static function unmarkAsDeleted($method_key)
    {
        $carrier = self::getCarrier($method_key);
        if (!$carrier) {
            return false;
        }
        $newest_carrier_id = Db::getInstance()->getValue('SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE id_reference = ' . (int) $carrier->id_reference . ' ORDER BY id_carrier DESC');
        $newest_carrier = self::getCarrierById($newest_carrier_id);
        if (!$newest_carrier) {
            return false;
        }
        $newest_carrier->deleted = false;
        $newest_carrier->update();

        self::updateMappingValues($method_key, $newest_carrier->id, $newest_carrier->id_reference);

        return $newest_carrier->id;
    }

    public static function removeCarrier($method_key)
    {
        $carrier = self::getCarrier($method_key);
        if (!$carrier) {
            return false;
        }
        $carrier->delete();
        Configuration::deleteByName(self::getIdKey($method_key));
        Configuration::deleteByName(self::getReferenceKey($method_key));
        
        return true;
    }
}
