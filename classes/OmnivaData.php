<?php
if (!defined('_PS_VERSION_'))
    exit;

class OmnivaData
{
    public static function getOrderObjects( $id_order )
    {
        $order = new \Order((int)$id_order);
        $customer = new \Customer((int)$order->id_customer);
        $address = new \Address((int)$order->id_address_delivery);
        $carrier = new \Carrier((int)$order->id_carrier);

        return (object) array(
            'order' => $order,
            'customer' => $customer,
            'address' => $address,
            'carrier' => $carrier,
        );
    }

    public static function getOmnivaObjects( $orderObj )
    {
        $omnivaOrder = new OmnivaOrder($orderObj->id);
        $cartTerminal = new OmnivaCartTerminal($orderObj->id_cart);

        return (object) array(
            'order' => $omnivaOrder,
            'cart_terminal' => $cartTerminal,
        );
    }

    public static function getCountryIso( $addressObj )
    {
        return \Country::getIsoById($addressObj->id_country);
    }

    public static function getReceiverData( $addressObj, $customerObj )
    {
        $mobile_phone = null;
        if (isset($addressObj->phone_mobile) && $addressObj->phone_mobile) {
            $mobile_phone = $addressObj->phone_mobile;
        } else if (isset($addressObj->phone) && $addressObj->phone) {
            $mobile_phone = $addressObj->phone;
        }

        return (object) array(
            'name' => self::getReceiverName($addressObj),
            'country' => self::getCountryIso($addressObj),
            'postcode' => $addressObj->postcode,
            'city' => $addressObj->city,
            'street' => self::getReceiverStreet($addressObj),
            'email' => $customerObj->email,
            'phone' => (isset($addressObj->phone) && $addressObj->phone) ? $addressObj->phone : null,
            'mobile' => $mobile_phone
        );
    }

    private static function getReceiverName( $addressObj )
    {
        $reveicer_name = $addressObj->firstname . ' ' . $addressObj->lastname;
        if ( ! empty($addressObj->company) ) {
            $reveicer_name = $addressObj->company;
        }

        return trim($reveicer_name);
    }

    private static function getReceiverStreet( $addressObj )
    {
        $receiver_street = $addressObj->address1;
        if ( ! empty($addressObj->address2) ) {
            $receiver_street .= ' - ' . $addressObj->address2;
        }

        return trim($receiver_street);
    }
}
