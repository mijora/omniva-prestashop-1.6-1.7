<?php

use Mijora\Omniva\OmnivaException;
use Mijora\Omniva\ServicePackageHelper\ServicePackageHelper;
use Mijora\Omniva\ServicePackageHelper\PackageItem;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;
use Mijora\Omniva\Shipment\Package\ServicePackage;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Package\Measures;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;

class OmnivaApiInternational extends OmnivaApi
{
    /**************************** Functions for get data from API library ****************************/
    /**
     * Get the units used by the API
     * 
     * @return array - List of units
     */
    public static function getUnits()
    {
        return (object) array('weight' => 'kg', 'dimensions' => 'm');
    }

    /**
     * Get all services from API library
     * 
     * @return array|null - Services data or null if failed to get
     */
    public static function getAllServices()
    {
        return ServicePackageHelper::getServices();
    }

    /**
     * Get available services for specific country
     *
     * @return array - List of services
     */
    public static function getCountryData( $country_code )
    {
        return ServicePackageHelper::getCountryOptions($country_code);
    }

    /**
     * Check if package key is valid
     * 
     * @param string $package_key - Package key
     * @return string|null - Package key if valid, null if invalid
     */
    public static function getPackageCode( $package_key )
    {
        return ServicePackageHelper::getServicePackageCode($package_key);
    }

    /**
     * Get all available serrvices, regions and countries
     * 
     * @return array - List of services, each divided into regions with a list of available countries
     */
    public static function getAvailablePackages()
    {
        $packages = array();
        $all_services = self::getAllServices();
        if ( ! is_array($all_services) ) {
            return $packages;
        }

        foreach ( $all_services as $country_code => $service ) {
            if ( ! isset($service['package']) || ! is_array($service['package']) ) {
                continue;
            }
            foreach ( $service['package'] as $package_key => $package_data ) {
                if ( ! isset($packages[$package_key]) ) {
                    $packages[$package_key] = array();
                }
                $region = ($service['eu']) ? 'eu' : 'non';
                $packages[$package_key][$region][] = (isset($service['iso'])) ? $service['iso'] : $country_code;
            }
        }

        return $packages;
    }

    /**
     * Get all available countries
     * 
     * @return array - List of countries
     */
    public static function getAllAvailableCountries()
    {
        $countries = array();
        foreach ( self::getAvailablePackages() as $package_key => $package_regions ) {
            foreach ( $package_regions as $region_key => $region_countries ) {
                foreach ( $region_countries as $country ) {
                    if ( ! in_array($country, $countries) ) {
                        $countries[] = $country;
                    }
                }
            }
        }
        return $countries;
    }

    /**
     * Get data for specific package of the country
     * 
     * @param string $country - Country ISO code
     * @param string $package_key - Package key
     * @return array|boolean - Package data or false if failed to get
     */
    public static function getCountryPackageData( $country, $package_key )
    {
        if ( empty($country) ) {
            return false;
        }

        $country_data = self::getCountryData($country);
        if ( empty($country_data['package']) || ! isset($country_data['package'][$package_key]) ) {
            return false;
        }

        $package_data = $country_data['package'][$package_key];
        return array(
            'max_weight' => (isset($package_data['maxWeightKg'])) ? $package_data['maxWeightKg'] : false,
            'longest_side' => (isset($package_data['maxDimensionsM']) && isset($package_data['maxDimensionsM']['longestSide'])) ? $package_data['maxDimensionsM']['longestSide'] : false,
            'max_perimeter' => (isset($package_data['maxDimensionsM']) && isset($package_data['maxDimensionsM']['total'])) ? $package_data['maxDimensionsM']['total'] : false,
            'insurance' => (isset($package_data['insurance'])) ? $package_data['insurance'] : false,
        );
    }

    /**
     * Check if package is available for items
     * 
     * @param string $package_key - Package key
     * @param string $country_code - Country ISO code
     * @param array $items - Items list. Items with quantity more then 1 must be divided into the corresponding quantity of items.
     * @return boolean - If package is available
     */
    public static function isPackageAvailableForItems( $package_key, $country_code, $items )
    {
        $package_code = self::getPackageCode($package_key);

        if ( ! $package_code || empty(self::getCountryData($country_code)) || empty($items) ) {
            return false;
        }

        $units = self::getUnits();
        $package_items = array();
        foreach ( $items as $item ) {
            $package_items[] = new PackageItem(
                OmnivaHelper::convertWeightUnit($item['weight'], $units->weight),
                OmnivaHelper::convertDimensionsUnit($item['length'], $units->dimensions),
                OmnivaHelper::convertDimensionsUnit($item['width'], $units->dimensions),
                OmnivaHelper::convertDimensionsUnit($item['height'], $units->dimensions),
            );
        }

        $available_packages = ServicePackageHelper::getAvailablePackages($country_code, $package_items);
        if ( ! in_array($package_code, $available_packages) ) {
            return false;
        }

        return true;
    }

    /**************************** API related module functions ****************************/
    public static function getPackageKeyFromMethodKey( $method_key )
    {
        if (strpos($method_key, 'omnivalt_') !== false) {
            $method_key = str_replace('omnivalt_', '', $method_key);
        }

        if (strpos($method_key, 'int_') !== false) {
            $method_key = str_replace('int_', '', $method_key);
        }

        return $method_key;
    }

    public static function isOmnivaMethodAllowed( $method_key, $receiver_country )
    {
        $parent_result = parent::isOmnivaMethodAllowed($method_key, $receiver_country);
        if ($parent_result) {
            return true;
        }

        $package_key = self::getPackageKeyFromMethodKey($method_key);

        return array_key_exists($package_key, self::getAvailablePackages());
    }

    public static function isInternationalMethod( $method_key )
    {
        if (strpos($method_key, 'int_') === false) {
            return false;
        }

        $package_key = self::getPackageKeyFromMethodKey($method_key);

        return array_key_exists($package_key, self::getAvailablePackages());
    }

    /**************************** Override class parent functions ****************************/
    public function createShipment($id_order)
    {
        try {
            $orderObjs = $this->getOrderObjects($id_order);
            $omnivaObjs = $this->getOmnivaObjects($orderObjs->order);
            
            $method_key = OmnivaCarrier::getCarrierMethodKey($orderObjs->carrier->id, $orderObjs->carrier->id_reference);

            if (! self::isInternationalMethod($method_key)) {
                return parent::createShipment($id_order);
            }

            $package_key = self::getPackageKeyFromMethodKey($method_key);
            $country_iso = $this->getCountryIso($orderObjs->address);
            $receiver_data = $this->getReceiverData($orderObjs->address, $orderObjs->customer);
        } catch (\Exception $e) {
            return ['msg' => OmnivaHelper::buildExceptionMessage($e, 'Failed to get Order data')];
        }

        try {
            $shipment = new Shipment();
            $shipment->setComment($this->getLabelComment($orderObjs->order));

            $shipmentHeader = new ShipmentHeader();
            $shipmentHeader
                ->setSenderCd($this->username)
                ->setFileId(date('Ymdhis'));
            $shipment->setShipmentHeader($shipmentHeader);

            $servicePackage = new ServicePackage(self::getPackageCode($package_key));

            $packages = [];
            $package_weight = $this->getPackageWeight($omnivaObjs->order);
            for ( $i = 0; $i < $omnivaObjs->order->packs; $i++ ) {
                $package_id = (string) $id_order;
                if ( $omnivaObjs->order->packs > 1 ) {
                    $package_id .= '_' . ($i + 1);
                }

                $package = new Package();
                $package
                    ->setId($package_id)
                    //->setComment('') //Not working yet
                    ->setService(Package::MAIN_SERVICE_PARCEL, Package::CHANNEL_COURIER)
                    ->setReturnAllowed($this->shouldSendReturnCode())
                    ->setServicePackage($servicePackage);

                $measures = new Measures();
                $measures->setWeight($package_weight);
                $package->setMeasures($measures);

                // Receiver contact data
                $receiverAddress = new Address();
                $receiverAddress
                    ->setCountry($receiver_data->country)
                    ->setPostcode($receiver_data->postcode)
                    ->setDeliverypoint($receiver_data->city)
                    ->setStreet($receiver_data->street);

                $receiverContact = new Contact();
                $receiverContact
                    ->setAddress($receiverAddress)
                    ->setPersonName($receiver_data->name)
                    ->setPhone($receiver_data->phone)
                    ->setMobile($receiver_data->mobile);
                if(Configuration::get('send_delivery_email'))
                {
                    $receiverContact->setEmail($receiver_data->email);
                }
                $package->setReceiverContact($receiverContact);
                $package->setSenderContact($this->getSenderContact());

                $packages[] = $package;
            }

            $shipment->setPackages($packages);

            //set auth data
            $this->setAuth($shipment);

            return $shipment->registerShipment();
        } catch (OmnivaException $e) {
            return ['msg' => $e->getMessage()];
        }
    }
}
