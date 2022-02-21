<?php

use Mijora\Omniva\OmnivaException;
use Mijora\Omniva\Shipment\CallCourier;
use Mijora\Omniva\Shipment\Package\AdditionalService;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;
use Mijora\Omniva\Shipment\Package\Measures;
use Mijora\Omniva\Shipment\Package\Cod;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;
use Mijora\Omniva\Shipment\Label;
use Mijora\Omniva\Shipment\Manifest;
use Mijora\Omniva\Shipment\Order;
use Mijora\Omniva\Shipment\Tracking;

class OmnivaApi
{
    private $username;

    private $password;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    public function createShipment($id_order)
    {
        $order = new \Order($id_order);
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
                ->setSenderCd($this->username)
                ->setFileId(date('Ymdhis'));
            $shipment->setShipmentHeader($shipmentHeader);

            $packages = [];

            $service = $this->getServiceCode($order->id_carrier);

            $additionalServices = [];
            if ($service == "PA" || $service == "PU")
                $additionalServices[] = "ST";
            if ($omnivaOrder->cod)
                $additionalServices[] = "BP";

            for ($i = 0; $i < $omnivaOrder->packs; $i++)
            {
                $package = new Package();
                $package->setService($service);
                $additionalServiceObj = [];
                foreach ($additionalServices as $additionalServiceCode)
                {
                    $additionalServiceObj[] = (new AdditionalService())->setServiceCode($additionalServiceCode);
                }
                $package->setAdditionalServices($additionalServiceObj);

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
                if (($service == 'PU' || $service == 'PA') && $id_terminal)
                    $receiverAddress->setOffloadPostcode($id_terminal);
                else
                    $receiverAddress->setOffloadPostcode($orderAdress->postcode);
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

    public function getServiceCode($id_carrier)
    {

        $send_method = '';
        $terminals = OmnivaltShipping::getCarrierIds(['omnivalt_pt']);
        $couriers = OmnivaltShipping::getCarrierIds(['omnivalt_c']);
        if (in_array((int)$id_carrier, $terminals, true))
            $send_method = 'pt';
        if (in_array((int)$id_carrier, $couriers, true))
            $send_method =  'c';

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

        return $service;
    }

    private function getSenderContact()
    {
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

        return $senderContact;
    }

    public function getOrderLabels($tracking_numbers)
    {
        $label = new Label();
        $this->setAuth($label);
        $label->downloadLabels($tracking_numbers, Configuration::get('omnivalt_print_type') == 'four');
    }

    public function getBulkLabels($order_ids)
    {
        $label = new Label();
        $this->setAuth($label);

        $tracking_numbers = [];
        foreach ($order_ids as $id_order)
        {
            $omnivaOrder = new OmnivaOrder($id_order);
            if(Validate::isLoadedObject($omnivaOrder) && $omnivaOrder->tracking_numbers)
            {
                $tracking_numbers = array_merge($tracking_numbers, json_decode($omnivaOrder->tracking_numbers));
            }
        }
        $label->downloadLabels($tracking_numbers, Configuration::get('omnivalt_print_type') == 'four');
    }

    public function getManifest()
    {
        $manifest = new Manifest();
        $manifest->setSender($this->getSenderContact());

        $omnivaOrderHistoryIds = OmnivaOrderHistory::getCurrentManifestOrders();
        foreach ($omnivaOrderHistoryIds as $omnivaOrderHistoryId)
        {
            $omnivaOrderHistory = new OmnivaOrderHistory($omnivaOrderHistoryId);
            if(Validate::isLoadedObject($omnivaOrderHistory))
            {
                $terminal_address = '';
                $order = new \Order($omnivaOrderHistory->id_order);
                $omnivaOrder = new OmnivaOrder($omnivaOrderHistory->id_order);
                $cartTerminal = new OmnivaCartTerminal($order->id_cart);
                if(Validate::isLoadedObject($cartTerminal))
                {
                    $terminal_address = OmnivaltShipping::getTerminalAddress($cartTerminal->id_terminal);
                }

                $address = new \Address($order->id_address_delivery);
                $client_address = $address->firstname . ' ' . $address->lastname . ', ' . $address->address1 . ', ' . $address->postcode . ', ' . $address->city . ' ' . $address->country;

                $barcodes = json_decode($omnivaOrderHistory->tracking_numbers);
                if(!empty($barcodes))
                {
                    $num_packages = count($barcodes);
                    foreach ($barcodes as $barcode)
                    {
                        $order = new Order();
                        $order->setTracking($barcode);
                        $order->setQuantity(1);
                        $order->setWeight($omnivaOrder->weight / $num_packages);
                        $order->setReceiver($terminal_address ?: $client_address);
                        $manifest->addOrder($order);
                    }
                }
            }
        }

        Configuration::updateValue('omnivalt_manifest', ((int) Configuration::get('omnivalt_manifest')) + 1);
        $manifest->downloadManifest();
    }

    public function getTracking($tracking_numbers)
    {
        $tracking = new Tracking();
        $this->setAuth($tracking);

        return $tracking->getTracking($tracking_numbers);
    }

    public function callCarrier()
    {
        $call = new CallCourier();
        $this->setAuth($call);
        $call->setSender($this->getSenderContact());

        try {
            return $call->callCourier();
        }
        catch (OmnivaException $e)
        {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    private function setAuth($object)
    {
        if(method_exists($object, 'setAuth'))
            $object->setAuth($this->username, $this->password);
    }

    private function getMethod($order_carrier_id = false)
    {

    }
}