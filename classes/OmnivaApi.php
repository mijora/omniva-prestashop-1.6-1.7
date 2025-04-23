<?php
if (!defined('_PS_VERSION_'))
    exit;

use \Mijora\Omniva\OmnivaException;
use \Mijora\Omniva\Shipment\Shipment;
use \Mijora\Omniva\Shipment\ShipmentHeader;
use \Mijora\Omniva\Shipment\Label;
use \Mijora\Omniva\Shipment\Manifest;
use \Mijora\Omniva\Shipment\Order as ApiOrder;
use \Mijora\Omniva\Shipment\Tracking;
use \Mijora\Omniva\Shipment\CallCourier;
use \Mijora\Omniva\Shipment\Package\Package;
use \Mijora\Omniva\Shipment\Package\Address;
use \Mijora\Omniva\Shipment\Package\Contact;
use \Mijora\Omniva\Shipment\Package\Measures;
use \Mijora\Omniva\Shipment\Package\ServicePackage;
use \Mijora\Omniva\PowerBi\OmnivaPowerBi;

class OmnivaApi
{
    const LABEL_COMMENT_TYPE_NONE = 0;
    const LABEL_COMMENT_TYPE_ORDER_ID = 1;
    const LABEL_COMMENT_TYPE_ORDER_REF = 2;

    protected $username;
    protected $password;

    protected $methods_types = array(
        'parcel' => array('omnivalt_pt', 'omnivalt_c'),
        'letter' => array('omnivalt_el'),
        'pallet' => array()
    );
    protected $methods_channels = array(
        'terminal' => array('omnivalt_pt'),
        'courier' => array('omnivalt_c'),
        'post' => array(),
        'postbox' => array('omnivalt_el')
    );

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;

        $this->declareIntegrationAgent();
    }

    protected function declareIntegrationAgent()
    {
        if ( ! defined('_OMNIVA_INTEGRATION_AGENT_ID_') ) {
            $module_version = DB::getInstance()->getValue("
                SELECT version FROM " . _DB_PREFIX_ . "module 
                WHERE name = 'omnivaltshipping'
            ");
            $version = (! empty($module_version)) ? $module_version : '0.0.0';
            define('_OMNIVA_INTEGRATION_AGENT_ID_', '7005511 Prestashop v' . $version);
        }
    }

    public function createShipment( $id_order )
    {
        try {
            $orderObjs = OmnivaData::getOrderObjects($id_order);
            $omnivaObjs = OmnivaData::getOmnivaObjects($orderObjs->order);
            
            $country_iso = strtoupper(OmnivaData::getCountryIso($orderObjs->address));
            $id_terminal = $omnivaObjs->cart_terminal->id_terminal;
            $receiver_data = OmnivaData::getReceiverData($orderObjs->address, $orderObjs->customer);
        } catch (\Exception $e) {
            return ['msg' => OmnivaHelper::buildExceptionMessage($e, 'Failed to get Order data')];
        }

        try {
            $shipment_codes = $this->getShipmentCodes($orderObjs->order->id_carrier);
            if ( ! $shipment_codes->main_service ) {
                throw new OmnivaException('Failed to get shipment service');
            }
            if ( ! $shipment_codes->delivery_service ) {
                throw new OmnivaException('Failed to get delivery service');
            }

            if ( ! self::isOmnivaMethodAllowed(array('type' => $shipment_codes->type_key, 'channel' => $shipment_codes->channel_key), $country_iso) ) {
                $countries_txt = array('LT' => 'Lithuania', 'LV' => 'Latvia', 'EE' => 'Estonia', 'FI' => 'Finland');
                $sender_country_code = Configuration::get('omnivalt_countrycode');
                if ( empty($sender_country_code) ) {
                    throw new OmnivaException('The sender country is not specified in the module settings');
                }
                throw new OmnivaException(sprintf(
                    'Shipment type "%1$s" is not allowed to send via "%2$s" from %3$s to %4$s',
                    $shipment_codes->main_service,
                    $shipment_codes->delivery_service,
                    (isset($countries_txt[$sender_country_code])) ? $countries_txt[$sender_country_code] : $sender_country_code,
                    (isset($countries_txt[$country_iso])) ? $countries_txt[$country_iso] : $country_iso
                ));
            }

            $is_consolidated = ($omnivaObjs->order->packs > 1 && $omnivaObjs->order->cod) ? true : false;
            $pack_weight = $this->getPackageWeight($omnivaObjs->order);
            $terminals_type = OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key);
            $additional_services = self::getAdditionalServices($orderObjs->order);

            /* Create shipment */
            $api_shipment = new Shipment();
            $api_shipment->setComment($this->getLabelComment($orderObjs->order));

            /* Prepare shipment header */
            $api_shipment_header = new ShipmentHeader();
            $api_shipment_header
                ->setSenderCd($this->username)
                ->setFileId(date('Ymdhis'));
            $api_shipment->setShipmentHeader($api_shipment_header);

            /* Prepare packages */
            $packages = [];
            for ( $i = 0; $i < $omnivaObjs->order->packs; $i++ ) {
                $package_id = (string) $id_order;
                if ( $omnivaObjs->order->packs > 1 && ! $is_consolidated ) {
                    $package_id .= '_' . ($i + 1);
                }

                /* Create package */
                $api_package = new Package();
                $api_package->setId($package_id);
                $api_package->setService($shipment_codes->main_service, $shipment_codes->delivery_service);
                $api_package->setReturnAllowed($this->shouldSendReturnCode());

                /* Set additional services */
                foreach ( $additional_services as $service_code => $service ) {
                    if ( $i > 0 && $is_consolidated && $service_code != 'fragile' ) {
                        continue;
                    }

                    /* Add additional service */
                    $api_additional_service = new $service['class']();

                    if ( $service_code == 'cod' ) {
                        $company = Configuration::get('omnivalt_company');
                        $bank_account = Configuration::get('omnivalt_bank_account');
                        
                        $api_additional_service->setCodAmount($omnivaObjs->order->cod_amount);
                        $api_additional_service->setCodReceiver($company);
                        $api_additional_service->setCodIban($bank_account);
                        $api_additional_service->setCodReference($api_additional_service::calculateReferenceNumber($id_order));
                    }
                    if ( $service_code == 'insurance' ) {
                        $api_additional_service->setInsuranceValue($orderObjs->order->total_products_wt);
                    }

                    $api_package->setAdditionalServiceOmx($api_additional_service);
                }

                /* Set measures */
                if ( $shipment_codes->type_key != 'letter' ) {
                    $api_measures = new Measures();
                    $api_measures->setWeight($pack_weight);
                    //$api_measures->setLength();
                    //$api_measures->setHeight();
                    //$api_measures->setWidth();

                    $api_package->setMeasures($api_measures);
                }

                /* Set receiver */
                $api_receiver_address = new Address();
                $api_receiver_address->setCountry($receiver_data->country);
                $api_receiver_address->setPostcode($receiver_data->postcode);
                $api_receiver_address->setDeliverypoint($receiver_data->city);
                $api_receiver_address->setStreet($receiver_data->street);
                if ( $terminals_type ) {
                    $api_receiver_address->setOffloadPostcode($id_terminal);
                }

                $api_receiver_contact = new Contact();
                $api_receiver_contact->setAddress($api_receiver_address);
                $api_receiver_contact->setPersonName($receiver_data->name);
                $api_receiver_contact->setPhone($receiver_data->phone);
                $api_receiver_contact->setMobile($receiver_data->mobile);
                if ( Configuration::get('send_delivery_email') ) {
                    $api_receiver_contact->setEmail($receiver_data->email);
                }

                $api_package->setReceiverContact($api_receiver_contact);

                /* Set sender */
                $api_package->setSenderContact($this->getSenderContact());

                /* Set package service */
                if ( $shipment_codes->type_key == 'letter' ) {
                    $letter_service_code = $this->getLetterServiceCode($shipment_codes->delivery_service);
                    if ( $letter_service_code ) {
                        $api_service_package = new ServicePackage($letter_service_code);
                        $api_package->setServicePackage($api_service_package);
                    }
                }

                $packages[] = $api_package;
            }

            if ( empty($packages) ) {
                throw new OmnivaException('Failed to get packages');
            }

            $api_shipment->setPackages($packages);

            /* Register shipment */
            $this->setAuth($api_shipment);
            return $api_shipment->registerShipment(false);
        } catch (OmnivaException $e) {
            return ['msg' => $e->getMessage()];
        }
        return ['msg' => 'An unknown error occurred'];
    }

    public function getOrderLabels( $tracking_numbers )
    {
        $print_type_bool = (Configuration::get('omnivalt_print_type') == 'four');

        $api_label = new Label();
        $this->setAuth($api_label);
        $api_label->downloadLabels($tracking_numbers, $print_type_bool, 'D', 'Omniva_labels_' . date('Ymd_His'));
    }

    public function getBulkLabels( $order_ids )
    {
        $tracking_numbers = [];
        foreach ( $order_ids as $id_order ) {
            $omnivaOrder = new OmnivaOrder($id_order);
            if ( Validate::isLoadedObject($omnivaOrder) && $omnivaOrder->tracking_numbers ) {
                $tracking_numbers = array_merge($tracking_numbers, json_decode($omnivaOrder->tracking_numbers));
            }
        }

        if ( empty($tracking_numbers) ) {
            throw new OmnivaException('Failed to get tracking numbers');
        }

        $this->getOrderLabels($tracking_numbers);
    }

    public function getManifest( $orders_ids = false )
    {
        $omnivaOrderHistoryIds = array();
        if ( empty($orders_ids) ) {
            $omnivaOrderHistoryIds = OmnivaOrderHistory::getManifestOrders((int) Configuration::get('omnivalt_manifest'));
        } else {
            foreach ( $orders_ids as $id_order ) {
                if ( empty($id_order) ) {
                    continue;
                }
                $omnivaOrderHistory = OmnivaOrderHistory::getLatestOrderHistory($id_order);
                if ( \Validate::isLoadedObject($omnivaOrderHistory) ) {
                    $omnivaOrderHistoryIds[$id_order] = $omnivaOrderHistory->id;
                }
            }
        }
        $manifest_orders = $this->getManifestOrders($omnivaOrderHistoryIds);

        $api_manifest = new Manifest();
        $api_manifest->setSender($this->getSenderContact());
        $api_manifest->showBarcode(false);

        foreach ( $manifest_orders as $manifest_order ) {
            $api_manifest->addOrder($manifest_order);
        }

        if ( empty($orders_ids) ) {
            Configuration::updateValue('omnivalt_manifest', ((int) Configuration::get('omnivalt_manifest')) + 1);
        }
        $api_manifest->downloadManifest('D', 'Omniva_manifest_' . date('Ymd_His'));
    }

    public function getTracking( $tracking_numbers )
    {
        try {
            $tracking = new Tracking();
            $this->setAuth($tracking);

            if ( ! is_array($tracking_numbers) ) {
                $tracking_numbers = array($tracking_numbers);
            }

            $tracking_data = array();
            foreach ( $tracking_numbers as $barcode ) {
                $tracking_data[$barcode] = $tracking->getTrackingOmx($barcode);
            }
            return $tracking_data;
        } catch (OmnivaException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function callCarrier()
    {
        $pickup_start = Configuration::get('omnivalt_pick_up_time_start');
        $pickup_end = Configuration::get('omnivalt_pick_up_time_finish');
        if (empty($pickup_start)) $pickup_start = '8:00';
        if (empty($pickup_end)) $pickup_end = '17:00';

        try {
            $api_call = new CallCourier();
            $this->setAuth($api_call);
            $api_call->setSender($this->getSenderContact());
            $api_call->setEarliestPickupTime($pickup_start);
            $api_call->setLatestPickupTime($pickup_end);
            $api_call->setTimezone('Europe/Tallinn');
            //$api_call->setComment();
            //$api_call->setIsHeavyPackage();
            //$api_call->setIsTwoManPickup();
            //$api_call->setParcelsNumber();

            $result = $api_call->callCourier();
            if ( ! $result ) {
                return array('error' => 'Failed to call courier');
            }
            $result_data = $api_call->getResponseBody();
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
        } catch (OmnivaException $e) {
            return array('error' => $e->getMessage());
        }
    }

    public function cancelCarrier( $call_id )
    {
        try {
            $api_call = new CallCourier();
            $this->setAuth($api_call);

            $result = $api_call->cancelCourierOmx($call_id);
            if ( ! $result ) {
                return array('error' => 'Failed to cancel the courier');
            }
            return array(
                'status' => true,
                'call_id' => $call_id
            );
        } catch (OmnivaException $e) {
            return array('error' => $e->getMessage());
        }
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

    public static function getTrackingUrl( $country_iso )
    {
        $country_iso = strtoupper($country_iso);
        $all_url = array(
            'LT' => 'https://mano.omniva.lt/track/',
            'LV' => 'https://mana.omniva.lv/track/',
            'EE' => 'https://minu.omniva.ee/track/',
            'FI' => 'https://minu.omniva.ee/track/'
        );
        return (isset($all_url[$country_iso])) ? $all_url[$country_iso] : $all_url['LT'];
    }

    public static function getAdditionalServices( $order )
    {
        $services = array();
        $all_services = OmnivaApiServices::getAdditionalServices();
        $omnivaOrder = new OmnivaOrder($order->id);

        if ( $omnivaOrder->cod && isset($all_services['cod']) ) {
            $services['cod'] = $all_services['cod'];
        }

        foreach ( $order->getProducts() as $order_product ) {
            $product_id = (int) $order_product['product_id'];

            if ( OmnivaProduct::get18PlusStatus($product_id, true) && isset($all_services['persons_over_18']) ) {
                $services['over_18'] = $all_services['persons_over_18'];
            }
            if ( OmnivaProduct::getFragileStatus($product_id, true) && isset($all_services['fragile']) ) {
                $services['fragile'] = $all_services['fragile'];
            }
        }

        return $services;
    }

    public function getShipmentCodes( $id_carrier )
    {
        $shipment_type_key = $this->getShipmentTypeKey($id_carrier);
        $shipment_main_service = $this->getShipmentTypeCode($shipment_type_key);
        $shipment_channel_key = $this->getShipmentChannelKey($id_carrier);
        $shipment_delivery_service = $this->getShipmentChannelCode($shipment_channel_key);

        return (object) array(
            'type_key' => $shipment_type_key,
            'main_service' => $shipment_main_service,
            'channel_key' => $shipment_channel_key,
            'delivery_service' => $shipment_delivery_service,
            '_exists' => (! empty($shipment_main_service) && ! empty($shipment_delivery_service))
        );
    }

    protected function getSenderContact()
    {
        $api_sender_address = new Address();
        $api_sender_address->setCountry(Configuration::get('omnivalt_countrycode'));
        $api_sender_address->setPostcode(Configuration::get('omnivalt_postcode'));
        $api_sender_address->setDeliverypoint(Configuration::get('omnivalt_city'));
        $api_sender_address->setStreet(Configuration::get('omnivalt_address'));

        $api_sender_contact = new Contact();
        $api_sender_contact->setAddress($api_sender_address);
        $api_sender_contact->setPersonName(Configuration::get('omnivalt_company'));
        //$api_sender_contact->setEmail();
        //$api_sender_contact->setPhone();
        $api_sender_contact->setMobile(Configuration::get('omnivalt_phone'));

        return $api_sender_contact;
    }

    protected function getLetterServiceCode( $channel_code )
    {
        $all_letter_service_codes = OmnivaApiServices::getLetterServiceCodes();
        $all_channels = OmnivaApiServices::getChannels();

        if ( isset($all_channels['postbox']) && $channel_code == $all_channels['postbox'] ) {
            if ( isset($all_letter_service_codes['express']) ) {
                return $all_letter_service_codes['express'];
            }
        }

        if ( isset($all_letter_service_codes['registered']) ) {
            return $all_letter_service_codes['registered'];
        }
        return false;
    }

    protected function setAuth( $object )
    {
        if ( method_exists($object, 'setAuth') ) {
            $object->setAuth($this->username, $this->password);
        }
    }

    protected function getLabelComment( $order )
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

    private function getShipmentTypeKey( $id_carrier )
    {
        foreach ( $this->methods_types as $type => $methods ) {
            if ( empty($methods) ) {
                continue;
            }
            $carriers_ids = OmnivaltShipping::getCarrierIds($methods);
            if ( in_array((int)$id_carrier, $carriers_ids, true) ) {
                return $type;
            }
        }
        return false;
    }

    private function getShipmentTypeCode( $type_key )
    {
        $all_types = OmnivaApiServices::getShipmentTypes();

        return (isset($all_types[$type_key])) ? $all_types[$type_key] : false;
    }

    private function getShipmentChannelKey( $id_carrier )
    {
        foreach ( $this->methods_channels as $channel => $methods ) {
            if ( empty($methods) ) {
                continue;
            }
            $carriers_ids = OmnivaltShipping::getCarrierIds($methods);
            if ( in_array((int)$id_carrier, $carriers_ids, true) ) {
                return $channel;
            }
        }
        return false;
    }

    private function getShipmentChannelCode( $channel_key )
    {
        $all_channels = OmnivaApiServices::getChannels();

        return (isset($all_channels[$channel_key])) ? $all_channels[$channel_key] : false;
    }

    public static function isOmnivaMethodAllowed( $keys, $receiver_country )
    {
        if ( ! is_array($keys) ) {
            return false;
        }
        $type_key = (isset($keys['type'])) ? $keys['type'] : false;
        $channel_key = (isset($keys['channel'])) ? $keys['channel'] : false;
        if ( ! $type_key && ! $channel_key ) {
            return false;
        }

        if ( ! in_array($receiver_country, array('LT', 'LV', 'EE', 'FI')) ) {
            return false;
        }

        $api_country = Configuration::get('omnivalt_api_country');
        $ee_service_enabled = Configuration::get('omnivalt_ee_service');
        $fi_service_enabled = Configuration::get('omnivalt_fi_service');
        $sender_country = Configuration::get('omnivalt_countrycode');

        if ( empty($api_country) || empty($sender_country) ) {
            return false;
        }

        $api_country = strtoupper($api_country);
        $sender_country = strtoupper($sender_country);
        $receiver_country = strtoupper($receiver_country);

        if ( $api_country == 'EE' && $receiver_country == 'EE' && ! $ee_service_enabled ) {
            return false;
        }
        if ( $api_country == 'EE' && $receiver_country == 'FI' && ! $fi_service_enabled ) {
            return false;
        }

        if ( $type_key == 'parcel' && $channel_key == 'terminal' ) {
            if ( $api_country != 'EE' && $receiver_country == 'FI' ) {
                return false;
            }
        } else if ( $receiver_country == 'FI' ) { // For Finland when not a terminal, use international API
            return false;
        }

        if ( $type_key == 'letter' ) {
            if ( $api_country != 'EE' || $sender_country != 'EE' || $receiver_country != 'EE' ) {
                return false;
            }
        }

        return true;
    }

    protected function getPackageWeight( $omnivaOrder )
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

    protected function shouldSendReturnCode()
    {
        $value = Configuration::get('omnivalt_send_return');

        if ( in_array($value, array('dont')) ) {
            return false;
        }
        return $value ? true : false;
    }

    protected function getManifestOrders( $omnivaOrderHistoryIds )
    {
        $manifest_orders = array();

        if ( empty($omnivaOrderHistoryIds) ) {
            throw new OmnivaException('Failed to get Order history');
        }

        foreach ( $omnivaOrderHistoryIds as $omnivaOrderHistoryId ) {
            $omnivaOrderHistory = new OmnivaOrderHistory($omnivaOrderHistoryId);
            if ( \Validate::isLoadedObject($omnivaOrderHistory) ) {
                $terminal_address = '';
                $order = new \Order($omnivaOrderHistory->id_order);
                $address = new \Address($order->id_address_delivery);
                $omnivaOrder = new OmnivaOrder($omnivaOrderHistory->id_order);
                $cartTerminal = new OmnivaCartTerminal($order->id_cart);
                if ( \Validate::isLoadedObject($cartTerminal) ) {
                    $terminal_address = OmnivaltShipping::getTerminalAddress($cartTerminal->id_terminal);
                }

                $client_address = $address->firstname . ' ' . $address->lastname . ', ' . $address->address1 . ', ' . $address->postcode . ', ' . $address->city . ' ' . $address->country;

                $barcodes = json_decode($omnivaOrderHistory->tracking_numbers);
                if ( empty($barcodes) ) {
                    throw new OmnivaException('Orders do not have registered shipments');
                }

                $num_packages = count($barcodes);
                foreach ( $barcodes as $barcode ) {
                    $api_order = new ApiOrder();
                    $api_order->setOrderNumber($omnivaOrder->id);
                    $api_order->setTracking($barcode);
                    $api_order->setQuantity(1);
                    $api_order->setWeight(round($omnivaOrder->weight / $num_packages, 2));
                    $api_order->setReceiver($terminal_address ?: $client_address);
                    $manifest_orders[] = $api_order;
                }
            }
        }

        if ( empty($manifest_orders) ) {
            throw new OmnivaException('Failed to add Orders to manifest');
        }

        return $manifest_orders;
    }
}
