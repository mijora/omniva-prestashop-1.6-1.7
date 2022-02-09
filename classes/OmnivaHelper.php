<?php

use Mijora\Omniva\Locations\PickupPoints;
use Mijora\Omniva\OmnivaException;

class OmnivaHelper
{
    const PARCELS_JSON_DIR = _PS_MODULE_DIR_ . 'omnivaltshipping/';

    const PARCELS_JSON_FILENAME = 'locations.json';

    public function updateTerminals()
    {
        $omnivaPickupPoints = new PickupPoints();
        try {
            $pickups = $omnivaPickupPoints->getFilteredLocations();
        } catch (OmnivaException $e) {
            return false;
        }
        if($pickups)
        {
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
}