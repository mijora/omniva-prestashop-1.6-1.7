<?php
if (!defined('_PS_VERSION_'))
    exit;

use \Mijora\Omniva\Shipment\Package\Package;
use \Mijora\Omniva\Shipment\Package\ServicePackage;
use \Mijora\Omniva\Shipment\AdditionalService\CodService;
use \Mijora\Omniva\Shipment\AdditionalService\DeliveryToAnAdultService;
use \Mijora\Omniva\Shipment\AdditionalService\DeliveryToSpecificPersonService;
use \Mijora\Omniva\Shipment\AdditionalService\DocumentReturnService;
use \Mijora\Omniva\Shipment\AdditionalService\FragileService;
use \Mijora\Omniva\Shipment\AdditionalService\InsuranceService;
use \Mijora\Omniva\Shipment\AdditionalService\LetterDeliveryToASpecificPersonService;
use \Mijora\Omniva\Shipment\AdditionalService\RegisteredAdviceOfDeliveryService;
use \Mijora\Omniva\Shipment\AdditionalService\SameDayDeliveryService;
use \Mijora\Omniva\Shipment\AdditionalService\SecondDeliveryAttemptOnSaturdayService;
use \Mijora\Omniva\Shipment\AdditionalService\StandardAdviceOfDeliveryService;

class OmnivaApiServices
{
    public static function getChannels()
    {
        return array(
            'terminal' => self::getConstantValue('Package', 'CHANNEL_PARCEL_MACHINE'),
            'courier' => self::getConstantValue('Package', 'CHANNEL_COURIER'),
            'post' => self::getConstantValue('Package', 'CHANNEL_POST_OFFICE'),
            'postbox' => self::getConstantValue('Package', 'CHANNEL_POST_BOX'),
        );
    }

    public static function getShipmentTypes()
    {
        return array(
            'parcel' => self::getConstantValue('Package', 'MAIN_SERVICE_PARCEL'),
            'letter' => self::getConstantValue('Package', 'MAIN_SERVICE_LETTER'),
            'pallet' => self::getConstantValue('Package', 'MAIN_SERVICE_PALLET'),
        );
    }

    public static function getLetterServiceCodes()
    {
        return array(
            'document' => self::getConstantValue('ServicePackage', 'CODE_PROCEDURAL_DOCUMENT'),
            'registered' => self::getConstantValue('ServicePackage', 'CODE_REGISTERED_LETTER'),
            'maxiletter' => self::getConstantValue('ServicePackage', 'CODE_REGISTERED_MAXILETTER'),
            'express' => self::getConstantValue('ServicePackage', 'CODE_EXPRESS_LETTER'),
        );
    }

    public static function getAdditionalServices()
    {
        $module = Module::getInstanceByName('omnivaltshipping');

        return array(
            'cod' => array(
                'title' => $module->l('Cash on delivery'),
                'code' => (new CodService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\CodService',
            ),
            'persons_over_18' => array(
                'title' => $module->l('Issue to persons at the age of 18+'),
                'code' => (new DeliveryToAnAdultService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\DeliveryToAnAdultService',
            ),
            'personal_delivery' => array(
                'title' => $module->l('Personal delivery'),
                'code' => (new DeliveryToSpecificPersonService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\DeliveryToSpecificPersonService',
            ),
            'personal_delivery_letter' => array(
                'title' => $module->l('Personal delivery') . ' (' . $module->l('Letter') . ')',
                'code' => (new LetterDeliveryToASpecificPersonService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\LetterDeliveryToASpecificPersonService',
            ),
            'doc_return' => array(
                'title' => $module->l('Document return'),
                'code' => (new DocumentReturnService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\DocumentReturnService',
            ),
            'fragile' => array(
                'title' => $module->l('Fragile'),
                'code' => (new FragileService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\FragileService',
            ),
            'insurance' => array(
                'title' => $module->l('Insurance'),
                'code' => (new InsuranceService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\InsuranceService',
            ),
            'standard_advice_delivery' => array(
                'title' => $module->l('Standard Advice Of Delivery'),
                'code' => (new StandardAdviceOfDeliveryService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\StandardAdviceOfDeliveryService',
            ),
            'registered_advice_delivery' => array(
                'title' => $module->l('Registered Advice Of Delivery'),
                'code' => (new RegisteredAdviceOfDeliveryService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\RegisteredAdviceOfDeliveryService',
            ),
            'same_day_delivery' => array(
                'title' => $module->l('Same day delivery'),
                'code' => (new SameDayDeliveryService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\SameDayDeliveryService',
            ),
            'second_delivery_saturday' => array(
                'title' => $module->l('Second delivery attempt on Saturday'),
                'code' => (new SecondDeliveryAttemptOnSaturdayService())->getServiceCode(),
                'class' => '\Mijora\Omniva\Shipment\AdditionalService\SecondDeliveryAttemptOnSaturdayService',
            ),
        );
    }

    public static function haveTerminals( $country_iso )
    {
        return (in_array($country_iso, array(
            'LT', 'LV', 'EE', 'FI'
        )));
    }

    public static function getTerminalsType( $shipment_type_key, $channel_key )
    {
        if ( $shipment_type_key == 'parcel' ) {
            if ( $channel_key == 'terminal' ) {
                return 'terminal';
            }
            if ( $channel_key == 'post' ) {
                return 'post';
            }
        }
        return false;
    }

    private static function getConstantValue( $class_name, $constant_key, $on_fail = false )
    {
        $all_classes = array(
            'Package' => Package::class,
            'ServicePackage' => ServicePackage::class
        );
        if ( ! isset($all_classes[$class_name]) ) {
            return $on_fail;
        }

        if ( defined($all_classes[$class_name] . '::' . $constant_key) ) {
            return constant($all_classes[$class_name] . '::' . $constant_key);
        }
        return $on_fail;
    }
}
