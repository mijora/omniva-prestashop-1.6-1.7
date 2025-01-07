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
use Mijora\Omniva\PowerBi\OmnivaPowerBi;

class OmnivaApi
{
    const LABEL_COMMENT_TYPE_NONE = 0;
    const LABEL_COMMENT_TYPE_ORDER_ID = 1;
    const LABEL_COMMENT_TYPE_ORDER_REF = 2;

    protected $username;
    protected $password;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    public function createShipment($id_order)
    {
        try {
            $orderObjs = $this->getOrderObjects($id_order);
            $omnivaObjs = $this->getOmnivaObjects($orderObjs->order);
            
            $country_iso = $this->getCountryIso($orderObjs->address);
            $id_terminal = $omnivaObjs->cart_terminal->id_terminal;
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
            
            $packages = [];

            $sendOffCountry = $this->getSendOffCountry($orderObjs->address);
            $service = $this->getServiceCode($orderObjs->order->id_carrier, $sendOffCountry);
            $sender_iso_code = strtoupper((string) Configuration::get('omnivalt_countrycode'));

            $is_terminal_service = ($service == "PA" || $service == "PU" || $service == 'CD');
            
            if ($is_terminal_service && !self::isOmnivaMethodAllowed('pt', $country_iso)) {
                $countries_txt = array('LT' => 'Lithuania', 'LV' => 'Latvia', 'EE' => 'Estonia', 'FI' => 'Finland');
                $receiver_country_txt = (isset($countries_txt[$country_iso])) ? $countries_txt[$country_iso] : $country_iso;
                $sender_country_code = strtoupper((string) Configuration::get('omnivalt_countrycode'));
                $sender_country_txt = (isset($countries_txt[$sender_country_code])) ? $countries_txt[$sender_country_code] : $sender_country_code;
                return ['msg' => 'Sending to ' . $receiver_country_txt . ' terminals from ' . $sender_country_txt . ' is not available for ' . $sender_iso_code . ' users'];
            }

            $additionalServices = self::getAdditionalServices($orderObjs->order);
            $pack_weight = $this->getPackageWeight($omnivaObjs->order);

            $is_consolidated = ($omnivaObjs->order->packs > 1 && $omnivaObjs->order->cod) ? true : false;

            for ($i = 0; $i < $omnivaObjs->order->packs; $i++)
            {
                $package_id = (string) $id_order;
                if ($omnivaObjs->order->packs > 1 && ! $is_consolidated) {
                    $package_id .= '_' . ($i + 1);
                }

                $package = new Package();
                $package->setId($package_id);
                $package->setService($service);
                $package->setReturnAllowed($this->shouldSendReturnCode());
                $additionalServiceObj = [];
                foreach ($additionalServices as $additionalServiceCode)
                {
                    if ($is_consolidated && $i > 0) {
                        if ($additionalServiceCode !== 'BC') {
                            continue;
                        }
                    }
                    $additionalServiceObj[] = (new AdditionalService())->setServiceCode($additionalServiceCode);
                }
                $package->setAdditionalServices($additionalServiceObj);

                $measures = new Measures();
                $measures->setWeight($pack_weight);
                $package->setMeasures($measures);

                //set COD
                $allow_cod = ($is_consolidated && $i > 0) ? false : true;
                if($omnivaObjs->order->cod && $allow_cod)
                {
                    $company = Configuration::get('omnivalt_company');
                    $bank_account = Configuration::get('omnivalt_bank_account');
                    $cod = new Cod();
                    $cod
                        ->setAmount($omnivaObjs->order->cod_amount)
                        ->setBankAccount($bank_account)
                        ->setReceiverName($company)
                        ->setReferenceNumber(OmnivaltShipping::getReferenceNumber($id_order));
                    $package->setCod($cod);
                }

                // Receiver contact data
                $receiverAddress = new Address();
                $receiverAddress
                    ->setCountry($receiver_data->country)
                    ->setPostcode($receiver_data->postcode)
                    ->setDeliverypoint($receiver_data->city)
                    ->setStreet($receiver_data->street);
                if ($is_terminal_service && $id_terminal)
                    $receiverAddress->setOffloadPostcode($id_terminal);
                else
                    $receiverAddress->setOffloadPostcode($receiver_data->postcode);

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

    protected function getOrderObjects($id_order)
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

    protected function getOmnivaObjects($order)
    {
        $omnivaOrder = new OmnivaOrder($order->id);
        $cartTerminal = new OmnivaCartTerminal($order->id_cart);

        return (object) array(
            'order' => $omnivaOrder,
            'cart_terminal' => $cartTerminal,
        );
    }

    protected function getCountryIso($address)
    {
        return \Country::getIsoById($address->id_country);
    }

    protected function getLabelComment($order)
    {
        $label_comment_type = (int) Configuration::get('omnivalt_label_comment_type');
        switch ($label_comment_type) {
            case self::LABEL_COMMENT_TYPE_ORDER_ID:
                return 'Order ID: ' . $order->id;
                break;
            case self::LABEL_COMMENT_TYPE_ORDER_REF:
                return 'Order Ref: ' . $order->getUniqReference();
                break;
            default:
                // nothing
                break;
        }
        return '';
    }

    protected function shouldSendReturnCode()
    {
        return (in_array(Configuration::get('omnivalt_send_return'), array('all', 'sms', 'email'))) ? true : false;
    }

    protected function getPackageWeight($omnivaOrder)
    {
        $pack_weight = (float) $omnivaOrder->weight;
        if ( $pack_weight <= 0 ) {
            $pack_weight = 1;
        }

        if ( (int) $omnivaOrder->packs > 0 ) {
            $pack_weight = round($pack_weight / (int) $omnivaOrder->packs, 2);
        }

        return $pack_weight;
    }

    protected function getReceiverData($address, $customer)
    {
        $mobile_phone = null;
        if (isset($address->phone_mobile) && $address->phone_mobile) {
            $mobile_phone = $address->phone_mobile;
        } else if (isset($address->phone) && $address->phone) {
            $mobile_phone = $address->phone;
        }

        return (object) array(
            'name' => $this->getReceiverName($address),
            'country' => $this->getCountryIso($address),
            'postcode' => $address->postcode,
            'city' => $address->city,
            'street' => $address->address1,
            'email' => $customer->email,
            'phone' => (isset($address->phone) && $address->phone) ? $address->phone : null,
            'mobile' => $mobile_phone
        );
    }

    public static function getAdditionalServices($order)
    {
        $omnivaOrder = new OmnivaOrder($order->id);
        $orderAdress = new \Address($order->id_address_delivery);
        $country_iso = Country::getIsoById($orderAdress->id_country);
        $sendOffCountry = self::getSendOffCountry($orderAdress);
        $service = self::getServiceCode($order->id_carrier, $sendOffCountry);
        $additionalServices = [];

        $is_terminal_service = ($service == "PA" || $service == "PU" || $service == 'CD');

        if ($is_terminal_service) {
            $additionalServices[] = "ST";
            if(Configuration::get('send_delivery_email')) {
                $additionalServices[] = "SF";
            }
        }            
        
        if ($omnivaOrder->cod) {
            $additionalServices[] = "BP";
            if ($country_iso == 'FI' && $is_terminal_service) {
                return ['error' => 'Additional service COD is not available in this country'];
            }
        }

        // Products services check
        foreach ($order->getProducts() as $orderProduct) {
            $productId = (int) $orderProduct['product_id'];

            $isProduct18Plus = OmnivaProduct::get18PlusStatus($productId, true);
            if ($isProduct18Plus) {
                $additionalServices[] = "PC";
            }

            $isFragile = OmnivaProduct::getFragileStatus($productId, true);
            if ($isFragile) {
                $additionalServices[] = "BC";
            }
        }

        return $additionalServices;
    }

    private function getReceiverName($orderAdress)
    {
        $reveicer_name = $orderAdress->firstname . ' ' . $orderAdress->lastname;
        if ( ! empty($orderAdress->company) && ! empty($orderAdress->vat_number) ) {
            $reveicer_name = $orderAdress->company;
        }

        return trim($reveicer_name);
    }

    public static function getServiceCode($id_carrier, $sendOffCountry)
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

    public static function getSendOffCountry($address = null)
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

        $country_iso = null;
        if (Validate::isLoadedObject($address)) {
            $country_iso = Country::getIsoById($address->id_country);
        }

        return $country_iso == 'FI' ? 'finland' : 'baltic';
    }

    /**
     * Check if the Omniva method is allowed to ship to the specified country
     * 
     * @param string $method_key - Method key. 'pt' for terminal, 'c' for courier
     * @param string $receiver_country - The country code to which the delivery is being attempted
     * @return boolean - Is allowed or not
     */
    public static function isOmnivaMethodAllowed($method_key, $receiver_country)
    {
        $api_country = Configuration::get('omnivalt_api_country');
        $ee_service_enabled = Configuration::get('omnivalt_ee_service');
        $fi_service_enabled = Configuration::get('omnivalt_fi_service');
        $sender_country = Configuration::get('omnivalt_countrycode');

        if (empty($api_country) || empty($sender_country)) {
            return false;
        }

        $api_country = strtoupper($api_country);
        $sender_country = strtoupper($sender_country);
        $receiver_country = strtoupper($receiver_country);

        if ($api_country == 'EE' && $receiver_country == 'EE' && !$ee_service_enabled) {
            return false;
        }
        if ($api_country == 'EE' && $receiver_country == 'FI' && !$fi_service_enabled) {
            return false;
        }

        /* Allowed countries structure:
         * 'api_countries' => array(
         *   'sender_country' => array('receiver_country')
         *  )
         */
        $allowed_terminal_countries = array(
            'LT,LV,EE' => array(
                'LT' => array('LT', 'LV', 'EE', 'FI'),
                'LV' => array('LT', 'LV', 'EE', 'FI'),
                'EE' => array('LT', 'LV', 'EE', 'FI'),
            ),
        );
        $allowed_courier_countries = array(
            'LT,LV' => array(
                'LT' => array('LT', 'LV', 'EE'),
                'LV' => array('LT', 'LV', 'EE'),
                'EE' => array('LT', 'LV', 'EE'),
            ),
            'EE' => array(
                'LT' => array('LT', 'LV', 'EE', 'FI'),
                'LV' => array('LT', 'LV', 'EE', 'FI'),
                'EE' => array('LT', 'LV', 'EE', 'FI'),
            ),
        );

        switch ($method_key) {
            case 'pt':
                $allowed_countries = $allowed_terminal_countries;
                break;
            case 'c':
                $allowed_countries = $allowed_courier_countries;
                break;
            default:
                $allowed_countries = array();
        }
        
        $is_allow = false;
        foreach ($allowed_countries as $api_countries => $shipping_countries) {
            $splited_api_countries = explode(',', $api_countries);
            if (in_array($api_country, $splited_api_countries) && isset($shipping_countries[$sender_country])) {
                if (in_array($receiver_country, $shipping_countries[$sender_country])) {
                    $is_allow = true;
                }
            }
        }

        return $is_allow;
    }

    protected function getSenderContact()
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
                        $order->setOrderNumber($omnivaOrder->id);
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

        try {
            $call = new CallCourier();
            $call->setDestinationCountry($this->getSendOffCountry());
            $call->setEarliestPickupTime($pickup_start);
            $call->setLatestPickupTime($pickup_end);
            $this->setAuth($call);
            $call->setSender($this->getSenderContact());

            $result = $call->callCourier();
            if ($result) {
                $result_data = $call->getResponseBody();
                $call_data = array(
                    'id' => $result_data['courierOrderNumber'],
                    'start' => date('Y-m-d H:i:s', strtotime($result_data['startTime'] . ' UTC')),
                    'end' => date('Y-m-d H:i:s', strtotime($result_data['endTime'] . ' UTC'))
                );
                OmnivaHelper::addScheduledCourierCall($call_data['id'], $call_data['start'], $call_data['end']);
                return array(
                    'status' => true,
                    'call_id' => $call_data['id'],
                    'start_time' => $call_data['start'],
                    'end_time' => $call_data['end'],
                );
            }
            return $result;
        }
        catch (OmnivaException $e)
        {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    protected function setAuth($object)
    {
        if(method_exists($object, 'setAuth'))
            $object->setAuth($this->username, $this->password);
    }

    public function sendStatistics($shipments_data, $test_mode = false)
    {
        if ( empty($shipments_data) || ! is_array($shipments_data) ) {
            return false;
        }

        $prepared_prices = array();
        if ( isset($shipments_data['shipping_prices']) ) {
            foreach ( $shipments_data['shipping_prices'] as $country => $country_methods ) {
                $preparing_prices = array();
                if ( ! in_array($country, array('LT', 'LV', 'EE', 'FI')) ) {
                    continue;
                }
                foreach ( $country_methods as $method => $method_data ) {
                    if ( ! $method_data['enabled'] ) {
                        continue;
                    }
                    $price_values = array(
                        'min' => null,
                        'max' => null,
                    );
                    if ( is_array($method_data['prices']) ) {
                        $min_price = 9999999;
                        $max_price = 0;
                        foreach ( $method_data['prices'] as $price_range ) {
                            if ( $price_range['price'] < $min_price ) {
                                $min_price = $price_range['price'];
                            }
                            if ( $price_range['price'] > $max_price ) {
                                $max_price = $price_range['price'];
                            }
                        }
                        $price_values['min'] = $min_price;
                        $price_values['max'] = $max_price;
                    } else {
                        $price_values['min'] = $method_data['prices'];
                    }
                    $preparing_prices[] = array(
                        'method' => $method,
                        'prices' => $price_values,
                    );
                }
                $prepared_prices[$country] = array(
                    'courier' => null,
                    'terminal' => null,
                );
                foreach ( $preparing_prices as $price ) {
                    if ( ! array_key_exists($price['method'], $prepared_prices[$country]) ) {
                        continue;
                    }
                    $prepared_prices[$country][$price['method']] = $price['prices'];
                }
            }
        }

        try {
            $powerbi = new OmnivaPowerBi($this->username, $test_mode);
            $powerbi
                ->setPluginVersion($shipments_data['module_version'])
                ->setPlatform('Prestashop v' .$shipments_data['platform_version'])
                ->setSenderName($shipments_data['client_name'])
                ->setSenderCountry($shipments_data['client_country'])
                ->setDateTimeStamp($shipments_data['track_since'])
                ->setOrderCountCourier((isset($shipments_data['total_orders']['courier'])) ? $shipments_data['total_orders']['courier'] : 0)
                ->setOrderCountTerminal((isset($shipments_data['total_orders']['terminal'])) ? $shipments_data['total_orders']['terminal'] : 0);
            foreach ( $prepared_prices as $country => $prices ) {
                if ( $prices['courier'] !== null ) {
                    $powerbi->setCourierPrice($country, $prices['courier']['min'], $prices['courier']['max']);
                }
                if ( $prices['terminal'] !== null ) {
                    $powerbi->setTerminalPrice($country, $prices['terminal']['min'], $prices['terminal']['max']);
                }
            }
            OmnivaHelper::printToLog("Sending data to PowerBi:\n" . print_r($powerbi, true), 'powerbi');
            $result = $powerbi->send();
            if ( $result ) {
                OmnivaHelper::printToLog('Data sent successfully.', 'powerbi');
                return true;
            }
            OmnivaHelper::printToLog('Failed to send data.', 'powerbi');
        } catch (OmnivaException $e) {
            OmnivaHelper::printToLog('An error occurred while preparing to send data or while sending.', 'powerbi');
        }

        return false;
    }
}