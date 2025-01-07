<?php

class AdminOmnivaAjaxController extends ModuleAdminController
{
    private $_carrier_url_cache = [];

    public function __construct()
    {
        if (!Context::getContext()->employee->isLoggedBack()) {
            exit('Restricted.');
        }

        parent::__construct();
        if(Tools::isSubmit('noLabelsError'))
        {
            $this->errors[] = $this->module->l('Could not get label information for some orders.');
        }
        $this->parseActions();
    }

    private function parseActions()
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'saveOrderInfo':
                $this->saveOrderInfo();
                break;
            case 'generateLabels':
                $this->generateLabels();
                break;
            case 'printLabels':
                $this->printOrderLabels();
                break;
            case 'bulkPrintLabels':
                $this->printBulkLabels();
                break;
            case 'printManifest':
                $this->module->api->getManifest();
                break;
            case 'printAllManifests':
                $this->module->api->getAllManifests();
                break;
        }
    }


    protected function saveOrderInfo()
    {
        if (!empty($this->module->warning)) {
            return false;
        }

        $id_order = (int) Tools::getValue('order_id');

        $omnivaOrder = new OmnivaOrder($id_order);

        $packs = Tools::getValue('packs', 1);
        $weight = Tools::getValue('weight', 0);
        $isCod = Tools::getValue('is_cod', 0);
        $codAmount = Tools::getValue('cod_amount', 0);
        $carrier = Tools::getValue('carrier', 0);

        // Validate fields.
        if ($packs == NULL || !is_numeric($packs) || (int)$packs < 1) {
            die(json_encode(['error' => 'Bad packs number.']));
        }
        if ($weight == NULL || !Validate::isFloat($weight) || $weight <= 0) {
            die(json_encode(['error' => 'Bad weight.']));
        }
        if ($isCod != '0' && $isCod != '1') {
            die(json_encode(['error' => 'Bad COD value.']));
        }
        if ($isCod == '1' && ($codAmount == '' || !Validate::isFloat($codAmount))) {
            die(json_encode(['error' => 'Bad COD amount.']));
        }

        if (!$isCod) {
            $isCod = '0';
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            die(json_encode(['error' => 'Could not find order.']));
        }

        if(Tools::isSubmit('parcel_terminal') && ($id_terminal = Tools::getValue('parcel_terminal')))
        {
            $add_terminal = false;
            $omnivaCartTerminal = new OmnivaCartTerminal($order->id_cart);
            if(!Validate::isLoadedObject($omnivaCartTerminal))
            {
                $omnivaCartTerminal->force_id = true;
                $omnivaCartTerminal->id = $order->id_cart;
                $add_terminal = true;
            }
            $omnivaCartTerminal->id_terminal = pSQL($id_terminal);
            if ($add_terminal) {
                $omnivaCartTerminal->add();
            } else {
                $omnivaCartTerminal->save();
            }
            OmnivaHelper::printToLog('Cart #' . $order->id_cart . ' Order #' . $id_order . '. Changed terminal to ' . $id_terminal, 'admin');
        }

        $add_order = false;
        if(!Validate::isLoadedObject($omnivaOrder))
        {
            $omnivaOrder->force_id = true;
            $omnivaOrder->id = $order->id;
            $add_order = true;
        }
        $omnivaOrder->packs = $packs;
        $omnivaOrder->weight = $weight;
        $omnivaOrder->cod = $isCod;
        $omnivaOrder->cod_amount = $codAmount;

        if($add_order) {
          $result = $omnivaOrder->add();
          // Add blank history, so that order would appear new orders tab in admin.
          $omnivaOrderHistory = new OmnivaOrderHistory();
          $omnivaOrderHistory->id_order = $order->id;
          $omnivaOrderHistory->manifest = 0;
          $omnivaOrderHistory->add();
        }
        else {
          $result = $omnivaOrder->save();
        }

        if($result)
        {
            $selected_carrier = new Carrier($carrier);
            $order = new Order($id_order);
            $order_carrier = new OrderCarrier($order->getIdOrderCarrier());
            if ($selected_carrier->id != $order_carrier->id_carrier) {
                $order->id_carrier = $selected_carrier->id;
                $order_carrier->id_carrier = $selected_carrier->id;
                $order_carrier->update();
                $this->context->currency = isset($this->context->currency) ? $this->context->currency : new Currency(Configuration::get('PS_CURRENCY_DEFAULT'));
                $this->module->refreshShippingCost($order);
                $order->update();
            }
        }

        if ($result) {
            die(json_encode($this->module->l('Order info successfully saved.')));
        } else {
            die(json_encode(['error' => $this->module->l('Order info could not be saved.')]));
        }
    }

    /**
     * Call API to get register shipment.
     */
    protected function generateLabels($id_order = null, $return_on_die = false)
    {
        if(!$id_order)
        {
            if (!($id_order = (int) Tools::getValue('id_order'))) {
                $error = $this->module->l('No order ID provided.');
                return $return_on_die ? ['error' => $error] : die(json_encode(['error' => $error]));
            }
        }

        $order = new Order($id_order);
        $orderAdress = new Address($order->id_address_delivery);
        $carrier = OmnivaCarrier::getCarrierById($order->id_carrier);
        $omnivaOrder = new OmnivaOrder($id_order);
        if (!Validate::isLoadedObject($omnivaOrder)) {
            $error = $this->module->l('Order info not saved. Please save before generating labels');
            return $return_on_die ? ['error' => $error] : die(json_encode(['error' => $error]));
        }

        $status = $this->module->api->createShipment($id_order);
        if (isset($status['barcodes']) && !empty($status['barcodes']))
        {
            $order->setWsShippingNumber($status['barcodes'][0]);
            $order->update();
            $omnivaOrder->error = '';
            $omnivaOrder->tracking_numbers = json_encode($status['barcodes']);
            if($omnivaOrder->update())
            {
                $omnivaOrderHistory = OmnivaOrderHistory::getLatestOrderHistory($omnivaOrder->id);
                $method_key = OmnivaCarrier::getCarrierMethodKey($carrier->id, $carrier->id_reference);

                $international_service = false;
                if (OmnivaApiInternational::isInternationalMethod($method_key)) {
                    $international_service = OmnivaApiInternational::getPackageCode(OmnivaApiInternational::getPackageKeyFromMethodKey($method_key));
                }

                // If there is blank history, we update it with tracking info.
                if(empty($omnivaOrderHistory) || $omnivaOrderHistory->tracking_numbers)
                    $omnivaOrderHistory = new OmnivaOrderHistory();
                $omnivaOrderHistory->id_order = $omnivaOrder->id;
                $omnivaOrderHistory->tracking_numbers = json_encode($status['barcodes']);

                if ($international_service) {
                    $serviceCode = $international_service;
                } else {
                    $sendOffCountry = $this->module->api->getSendOffCountry($orderAdress);
                    $serviceCode = $this->module->api->getServiceCode($order->id_carrier, $sendOffCountry);
                }
                $omnivaOrderHistory->service_code = $serviceCode;
                $omnivaOrderHistory->manifest = (int) Configuration::get('omnivalt_manifest');
                $omnivaOrderHistory->save();
            }

            try {
                if (!isset($this->_carrier_url_cache[$order->id_carrier])) {
                    $carrier = new Carrier($order->id_carrier);
                    $this->_carrier_url_cache[$order->id_carrier] = $carrier->url;
                }
                $template_vars = array('{followup}' => str_replace('@', $status['barcodes'][0], $this->_carrier_url_cache[$order->id_carrier]));
                $this->module->changeOrderStatus($id_order, $this->module->getCustomOrderState(), $template_vars);
            } catch (\Throwable $th) {
                // silence if something not right with changing order status
            }

            if(Tools::getValue('redirect')) {
                Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
            } else {
                $return = $this->module->l('Label successfully generated');
                return $return_on_die ? ['success' => $return] : die(json_encode(['success' => $return]));
            }
        }
        else
        {
            $omnivaOrder->error = $status['msg'];
            $omnivaOrder->update();
            $this->module->changeOrderStatus($id_order, $this->module->getErrorOrderState());
            return $return_on_die ? ['error' => $status['msg']] : die(json_encode(['error' => $status['msg']]));
        }

        return null; // we never reach it
    }

    /**
     * Call API to print all labels for one order.
     */
    protected function printOrderLabels()
    {
        $id_order = $history_tracking = null;
        if(Tools::getValue('history'))
        {
            $history = new OmnivaOrderHistory((int) Tools::getValue('history'));
            if(Validate::isLoadedObject($history))
            {
                $id_order = $history->id_order;
            }
            else
            {
                die(json_encode(['error' => 'Could not load order info.']));
            }
        }
        elseif(Tools::getValue('id_order'))
        {
            $omnivaOrder = new OmnivaOrder((int) Tools::getValue('id_order'));
            if(!Validate::isLoadedObject($omnivaOrder))
            {
                die(json_encode(['error' => 'Could not load order info.']));
            }
        }

        $tracking_numbers = isset($history) ? $history->tracking_numbers : (isset($omnivaOrder) ? $omnivaOrder->tracking_numbers : '');
        if(!$tracking_numbers)
        {
            die(json_encode(['error' => 'No tracking numbers were provided.']));
        }
        if(!$this->module->api->getOrderLabels(json_decode($tracking_numbers)))
        {
            die(json_encode(['error' => 'Could not fetch labels from the API.']));
        }
    }

    protected function printBulkLabels()
    {
        $order_ids = explode(',', Tools::getValue('order_ids'));
        if (empty($order_ids))
        {
            die(json_encode(['error' => $this->module->l('No order ID\'s provided.')]));
        }

        $registered_ids = [];
        foreach($order_ids as $id_order)
        {
            $omnivaOrder = new OmnivaOrder($id_order);
            if(!Validate::isLoadedObject($omnivaOrder)) {
                continue;
            }

            // allready registered
            if ($omnivaOrder->tracking_numbers) {
                $registered_ids[] = $id_order;
                continue;
            }

            // doesnt have registered tracking number
            $result = $this->generateLabels($id_order, true);

            // found errors during registration, skip order id
            if (isset($result['error'])) {
                continue;
            }

            $registered_ids[] = $id_order;
        }

        if (empty($registered_ids)) {
            Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&noLabelsError');
            return;
        }

        try {
            $this->module->api->getBulkLabels($registered_ids);
        }
        catch(Mijora\Omniva\OmnivaException $e)
        {
            Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&noLabelsError');
            die(json_encode(['error' => 'Could not fetch labels from the API.']));
        }
    }

    public function saveManifest()
    {
        if (Tools::getValue('type') == 'new') {
            if (Tools::getValue('order_ids') == null) {
                print $this->module->l('Here is nothing to print!!!');
                exit();
            }
        }
        if (Tools::getValue('type') == 'skip') {
            $orderIds = trim(Tools::getValue('order_ids'), ',');
            $orderIds = explode(',', $orderIds);
            foreach ($orderIds as $order_id)
            {
                $omnivaOrderHistory = OmnivaOrderHistory::getLatestOrderHistory($order_id);
                if(Validate::isLoadedObject($omnivaOrderHistory))
                {
                    $omnivaOrderHistory->manifest = -1;
                    $omnivaOrderHistory->update();
                }

            }
        }
    }
}
