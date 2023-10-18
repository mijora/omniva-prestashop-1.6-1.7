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
    const LABEL_COMMENT_TYPE_NONE = 0;
    const LABEL_COMMENT_TYPE_ORDER_ID = 1;
    const LABEL_COMMENT_TYPE_ORDER_REF = 2;

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
        $label_comment_type = (int) Configuration::get('omnivalt_label_comment_type');
        try {
            $shipment = new Shipment();

            $shipmentHeader = new ShipmentHeader();
            $shipmentHeader
                ->setSenderCd($this->username)
                ->setFileId(date('Ymdhis'));
            $shipment->setShipmentHeader($shipmentHeader);
            
            switch ($label_comment_type) {
                case self::LABEL_COMMENT_TYPE_ORDER_ID:
                    $shipment->setComment('Order ID: ' . $order->id);
                    break;
                case self::LABEL_COMMENT_TYPE_ORDER_REF:
                    $shipment->setComment('Order Ref: ' . $order->getUniqReference());
                    break;
                default:
                    // nothing
                    break;
            }

            $packages = [];

            $sendOffCountry = $this->getSendOffCountry($orderAdress);
            $service = $this->getServiceCode($order->id_carrier, $sendOffCountry);

            $additionalServices = [];
            if ($service == "PA" || $service == "PU")
            {
                $additionalServices[] = "ST";
                if(Configuration::get('send_delivery_email'))
                {
                    $additionalServices[] = "SF";
                }
            }            
            if ($omnivaOrder->cod)
                $additionalServices[] = "BP";

            // calculate weight
            $pack_weight = (float) $omnivaOrder->weight;
            if($pack_weight <= 0) {
                $pack_weight = 1;
            }

            if ((int) $omnivaOrder->packs > 0) {
                $pack_weight = round($pack_weight / (int) $omnivaOrder->packs, 2);
            }

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
                $measures->setWeight($pack_weight);
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
                    ->setStreet($orderAdress->address1)
                    ;
                if (($service == 'PU' || $service == 'PA') && $id_terminal)
                    $receiverAddress->setOffloadPostcode($id_terminal);
                else
                    $receiverAddress->setOffloadPostcode($orderAdress->postcode);
                $receiverContact
                    ->setAddress($receiverAddress)
                    ->setPersonName($this->getReceiverName($orderAdress));
                if(Configuration::get('send_delivery_email'))
                {
                    $receiverContact->setEmail($customer->email);
                }
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

            if (Configuration::get('omnivalt_send_return')) {
                switch (Configuration::get('omnivalt_send_return')) {
                    case 'sms':
                        $shipment->setShowReturnCodeEmail(false);
                        break;
                    case 'email':
                        $shipment->setShowReturnCodeSms(false);
                        break;
                    case 'dont':
                        $shipment->setShowReturnCodeEmail(false);
                        $shipment->setShowReturnCodeSms(false);
                        break;
                }
            }

            //set auth data
            $this->setAuth($shipment);

            return $shipment->registerShipment();

        } catch (OmnivaException $e) {
            return ['msg' => $e->getMessage()];
        }
    }

    private function getReceiverName($orderAdress)
    {
        $reveicer_name = $orderAdress->firstname . ' ' . $orderAdress->lastname;
        if ( ! empty($orderAdress->company) && ! empty($orderAdress->vat_number) ) {
            $reveicer_name = $orderAdress->company;
        }

        return trim($reveicer_name);
    }

    public function getServiceCode($id_carrier, $sendOffCountry)
    {
        $send_method = '';
        $terminals = OmnivaltShipping::getCarrierIds(['omnivalt_pt']);
        $couriers = OmnivaltShipping::getCarrierIds(['omnivalt_c']);
        if (in_array((int)$id_carrier, $terminals, true))
            $send_method = 'pt';
        if (in_array((int)$id_carrier, $couriers, true))
        {
            $send_method =  'c';
            if($sendOffCountry == 'estonia')
            {
                $send_method =  'cp'; 
            }
            if($sendOffCountry == 'finland')
            {
                $send_method =  'pc'; 
            }
        }

        $pickup_method = Configuration::get('omnivalt_send_off');
        $method_code = $pickup_method . ' ' . $send_method;

        $service = '';
        if(isset(OmnivaltShipping::SHIPPING_SETS[$sendOffCountry][$method_code]))
        {
            $service = OmnivaltShipping::SHIPPING_SETS[$sendOffCountry][$method_code];
        }
        if (empty($service)) {
            $method_code = $pickup_method . ' -> ' . $send_method;
            throw new OmnivaException('Invalid shipment sending method: ' . $method_code);
        }
        
        return $service;
    }

    public function getSendOffCountry($address = null)
    {
        $api_country = Configuration::get('omnivalt_api_country');
        $ee_service_enabled = Configuration::get('omnivalt_ee_service');
        $fi_service_enabled = Configuration::get('omnivalt_fi_service');
        if($api_country == 'ee')
        {
            if(!$address)
            {
                if($ee_service_enabled)
                {
                    return 'estonia';
                }
                elseif($fi_service_enabled)
                {
                    return 'finland';
                }
                return 'baltic';
            }
            // Determine the type by destination address.
            $country_iso = Country::getIsoById($address->id_country);
            if(($country_iso == 'EE' && $ee_service_enabled) || ($country_iso == 'FI' && !$fi_service_enabled && $ee_service_enabled))
            {
                return 'estonia';
            }
            elseif($country_iso == 'FI' && $fi_service_enabled)
            {
                return 'finland';
            }
        }
        return 'baltic';
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

        $omnivaOrderHistoryIds = OmnivaOrderHistory::getManifestOrders((int) Configuration::get('omnivalt_manifest'));
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
                        $order->setWeight(round($omnivaOrder->weight / $num_packages, 2));
                        $order->setReceiver($terminal_address ?: $client_address);
                        $manifest->addOrder($order);
                    }
                }
            }
        }

        Configuration::updateValue('omnivalt_manifest', ((int) Configuration::get('omnivalt_manifest')) + 1);
        $manifest->downloadManifest();
    }

    public function getAllManifests()
    {
        $manifests = [];
        for($i = 1; $i <= (int) Configuration::get('omnivalt_manifest'); $i++)
        {
            $manifest = new Manifest();
            $manifest->setSender($this->getSenderContact());
    
            $omnivaOrderHistoryIds = OmnivaOrderHistory::getManifestOrders($i);
            foreach($omnivaOrderHistoryIds as $omnivaOrderHistoryId)
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
                            $order->setWeight(round($omnivaOrder->weight / $num_packages, 2));
                            $order->setReceiver($terminal_address ?: $client_address);
                            $manifest->addOrder($order);
                        }
                    }
                }
            }
            $manifests[] = $manifest;
        }
        Manifest::downloadMultipleManifests($manifests);
    }

    public function getTracking($tracking_numbers)
    {
        $tracking = new Tracking();
        $this->setAuth($tracking);

        return $tracking->getTracking($tracking_numbers);
    }

    public function callCarrier()
    {
        $pickup_start = Configuration::get('omnivalt_pick_up_time_start');
        $pickup_end = Configuration::get('omnivalt_pick_up_time_finish');
        if (empty($pickup_start)) $pickup_start = '8:00';
        if (empty($pickup_end)) $pickup_end = '17:00';

        $call = new CallCourier();
        $call->setDestinationCountry($this->getSendOffCountry());
        $call->setEarliestPickupTime($pickup_start);
        $call->setLatestPickupTime($pickup_end);
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
}