<?php

use Mijora\Omniva\OmnivaException;
use Mijora\Omniva\Shipment\Package\AdditionalService;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;
use Mijora\Omniva\Shipment\Package\Measures;
use Mijora\Omniva\Shipment\Package\Cod;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;

class OmnivaApi
{
    public function createShipment($id_order)
    {
        $order = new Order($id_order);
        $customer = new Customer($order->id_customer);
        $omnivaOrder = new OmnivaOrder($id_order);
        $orderAdress = new \Address($order->id_address_delivery);
        $country_iso = Country::getIsoById($orderAdress->id_country);
        $omnivaCartTerminal = new OmnivaCartTerminal($order->id_cart);
        $id_terminal = $omnivaCartTerminal->id_terminal;
        try {
            $shipment = new Shipment();

            $shipmentHeader = new ShipmentHeader();
            $shipmentHeader
                ->setSenderCd(Configuration::get('omnivalt_api_user'))
                ->setFileId(date('Ymdhis'));
            $shipment->setShipmentHeader($shipmentHeader);

            $packages = [];

            $send_method = $this->getMethod($order->id_carrier);
            $pickup_method = Configuration::get('omnivalt_send_off');

            switch ($pickup_method . ' ' . $send_method) {
                case 'c pt':
                    $service = "PU";
                    break;
                case 'c c':
                    $service = "QH";
                    break;
                case 'pt c':
                    $service = "PK";
                    break;
                case 'pt pt':
                    $service = "PA";
                    break;
                default:
                    $service = "";
                    break;
            }

            $additionalServices = [];
            if ($service == "PA" || $service == "PU")
                $additionalServices[] = "ST";
            if ($omnivaOrder->cod)
                $additionalServices[] = "BP";

            for ($i = 0; $i < $omnivaOrder->packs; $i++)
            {
                $package = new Package();
                $package->setService($service);
                foreach ($additionalServices as $additionalServiceCode)
                {
                    $additionalService = (new AdditionalService())->setServiceCode($additionalServiceCode);
                    $package->setAdditionalServices([$additionalService]);
                }

                $measures = new Measures();
                $measures->setWeight($omnivaOrder->weight);
                $package->setMeasures($measures);

                //set COD
                if($omnivaOrder->cod)
                {
                    $company = Configuration::get('omnivalt_company');
                    $bank_account = Configuration::get('omnivalt_bank_account');
                    $cod = new Cod();
                    $cod
                        ->setAmount($omnivaOrder->cod_amount)
                        ->setBankAccount($bank_account)
                        ->setReceiverName($company)
                        ->setReferenceNumber(OmnivaltShipping::getReferenceNumber($id_order));
                    $package->setCod($cod);
                }

                // Receiver contact data
                $receiverContact = new Contact();
                $receiverAddress = new Address();
                $receiverAddress
                    ->setCountry($country_iso)
                    ->setPostcode($orderAdress->postcode)
                    ->setDeliverypoint($orderAdress->city)
                    ->setStreet($orderAdress->address1);
                if ($send_method == "pt" && $id_terminal)
                    $receiverAddress->setOffloadPostcode($id_terminal);
                $receiverContact
                    ->setAddress($receiverAddress)
                    ->setEmail($customer->email)
                    ->setPersonName($customer->firstname . ' ' . $customer->lastname);
                if(isset($orderAdress->phone) && $orderAdress->phone)
                {
                    $receiverContact->setPhone($orderAdress->phone);
                }
                if(isset($orderAdress->phone_mobile) && $orderAdress->phone_mobile)
                {
                    $receiverContact->setMobile($orderAdress->phone_mobile);
                }
                elseif (isset($orderAdress->phone) && $orderAdress->phone)
                {
                    $receiverContact->setMobile($orderAdress->phone);
                }
                $package->setReceiverContact($receiverContact);

                // Sender contact data
                $senderContact = new Contact();
                $senderAddress = new Address();
                $senderAddress
                    ->setCountry(Configuration::get('omnivalt_countrycode'))
                    ->setPostcode(Configuration::get('omnivalt_postcode'))
                    ->setDeliverypoint(Configuration::get('omnivalt_city'))
                    ->setStreet(Configuration::get('omnivalt_address'));
                $senderContact
                    ->setAddress($senderAddress)
                    ->setMobile(Configuration::get('omnivalt_phone'))
                    ->setPersonName(Configuration::get('omnivalt_company'));
                $package->setSenderContact($senderContact);

                $packages[] = $package;
            }

            $shipment->setPackages($packages);

            //set auth data
            $shipment->setAuth(Configuration::get('omnivalt_api_user'), Configuration::get('omnivalt_api_pass'));

            return $shipment->registerShipment();

        } catch (OmnivaException $e) {
            echo "\n<br>Exception:<br>\n"
                . str_replace("\n", "<br>\n", $e->getMessage()) . "<br>\n"
                . str_replace("\n", "<br>\n", $e->getTraceAsString());
        }
    }

    private function getMethod($order_carrier_id = false)
    {
        if (!$order_carrier_id)
            return '';
        $terminals = OmnivaltShipping::getCarrierIds(['omnivalt_pt']);
        $couriers = OmnivaltShipping::getCarrierIds(['omnivalt_c']);
        if (in_array((int)$order_carrier_id, $terminals, true))
            return 'pt';
        if (in_array((int)$order_carrier_id, $couriers, true))
            return 'c';
        return '';
    }
}