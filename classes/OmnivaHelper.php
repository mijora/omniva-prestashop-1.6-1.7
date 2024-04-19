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
}