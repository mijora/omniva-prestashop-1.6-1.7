<?php
// test
if (!defined('_PS_VERSION_'))
    exit;

require_once __DIR__ . "/classes/OmnivaDb.php";
require_once __DIR__ . "/classes/OmnivaCartTerminal.php";
require_once __DIR__ . "/classes/OmnivaOrder.php";
require_once __DIR__ . "/classes/OmnivaOrderHistory.php";
require_once __DIR__ . "/classes/OmnivaProduct.php";
require_once __DIR__ . "/classes/OmnivaCarrier.php";
require_once __DIR__ . "/classes/OmnivaHelper.php";
require_once __DIR__ . "/classes/OmnivaData.php";
require_once __DIR__ . "/classes/OmnivaApi.php";
require_once __DIR__ . "/classes/OmnivaApiInternational.php";
require_once __DIR__ . "/classes/OmnivaApiServices.php";

require_once __DIR__ . '/vendor/autoload.php';

class OmnivaltShipping extends CarrierModule
{
    public $helper;

    public $api;

    const CONTROLLER_OMNIVA_AJAX = 'AdminOmnivaAjax';
    const CONTROLLER_OMNIVA_ORDERS = 'AdminOmnivaOrders';

    const UPDATE_URL = 'https://api.github.com/repos/mijora/omniva-prestashop-1.6-1.7/releases/latest';
    const DOWNLOAD_URL = "https://github.com/mijora/omniva-prestashop-1.6-1.7/releases/latest/download//omnivaltshipping.zip";

    const SHIPPING_SETS = array(
        'baltic' => array(
          'pt pt' => 'PA',
          'pt c' => 'PK',
          'c pt' => 'PU',
          'c c' => 'QH',
          'courier_call' => 'QH',
        ),
        'estonia' => array(
          'pt pt' => 'PA',
          'pt po' => 'PO',
          'pt c' => 'PK',
          'c pt' => 'PU',
          'c c' => 'CI',
          'c cp' => 'LX',
          'po cp' => 'LH',
          'po pt' => 'PV',
          'po po' => 'CD',
          'po c' => 'CE',
          'lc pt' => 'PP',
          'courier_call' => 'CI',
        ),
        'finland' => array(
          'c pc' => 'QB',
          'c po' => 'CD',
          'c cp' => 'CE',
          'c pt' => 'CD',
          'pt pt' => 'CD',
          'courier_call' => 'CE',
        ),
      );

    protected $_hooks = array(
        'actionCarrierUpdate', //For control change of the carrier's ID (id_carrier), the module must use the updateCarrier hook.
        'displayBeforeCarrier',
        'displayAdminProductsExtra',
        'actionProductUpdate',
        'header',
        'displayHeader',
        'orderDetailDisplayed',
        'displayAdminOrder',
        'displayBackOfficeHeader',
        'actionValidateOrder',
        'actionAdminControllerSetMedia',
        'actionObjectOrderUpdateAfter',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'actionEmailSendBefore',
        'displayCarrierExtraContent'
    );

    /**
     * COD modules
     */
    public static $_codModules = array('ps_cashondelivery', 'venipakcod', 'codpro');

    public $id_carrier;

    static $_omniva_cache = [];

    public function __construct()
    {
        $this->name = 'omnivaltshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '2.3.3';
        $this->author = 'Mijora';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Omniva Shipping');
        $this->description = $this->l('Shipping module for Omniva carrier');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!Configuration::get('omnivalt_api_user'))
            $this->warning = $this->l('Please set up module');

        $this->helper = new OmnivaHelper();
        $this->api = new OmnivaApiInternational(Configuration::get('omnivalt_api_user'), Configuration::get('omnivalt_api_pass'));

        if (!Configuration::get('omnivalt_locations_update') || (Configuration::get('omnivalt_locations_update') + 24 * 3600) < time() || !file_exists(dirname(__file__) . "/locations.json")) {
            if ($this->helper->updateTerminals()) {
                Configuration::updateValue('omnivalt_locations_update', time());
            }
        }
        
        try {
            $this->sendStatistics();
        } catch(Exception $e) {
            OmnivaHelper::printToLog('Failed to send statistics. Error: ' . $e->getMessage(), 'powerbi');
        }
    }

    /**
     * Provides list of Admin controllers info
     *
     * @return array BackOffice Admin controllers
     */
    private function getModuleTabs()
    {
        return [
            self::CONTROLLER_OMNIVA_AJAX => [
                'title' => $this->l('Omniva Admin Ajax'),
                'parent_tab' => null,
            ],
            self::CONTROLLER_OMNIVA_ORDERS => [
                'title' => $this->l('Omniva Orders'),
                'parent_tab' => 'AdminParentShipping',
            ],
        ];
    }

    /**
     * Registers module Admin tabs (controllers)
     */
    private function registerTabs()
    {
        $tabs = $this->getModuleTabs();

        if (empty($tabs)) {
            return true; // Nothing to register
        }

        foreach ($tabs as $controller => $tabData) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $controller;
            $tab->name = [];
            $languages = Language::getLanguages(false);

            foreach ($languages as $language) {
                $tab->name[$language['id_lang']] = $tabData['title'];
            }

            $tab->id_parent = Tab::getIdFromClassName($tabData['parent_tab']);
            $tab->module = $this->name;
            if (!$tab->save()) {
                $this->displayError($this->l('Error while creating tab ') . $tabData['title']);
                return false;
            }
        }
        return true;
    }

    private function sendStatistics( $force = false, $test_mode = false )
    {
        $send_now = ($force) ? true : false;
        $last_send = Configuration::get('omnivalt_last_statistics_send');
        $last_try = Configuration::get('omnivalt_last_statistics_try');
        $date_minus_month = strtotime('-1 month', strtotime(date('Y-m-d')));
        $date_minus_day = strtotime('-1 day', strtotime(date('Y-m-d')));

        if ( date('j') == 2 && ( ! $last_send || ($last_send && $date_minus_month > $last_send) ) && ( ! $last_try || ($last_try && $date_minus_day > $last_try) ) ) {
            $send_now = true;
        }
        if ( $send_now ) {
            $result = $this->api->sendStatistics($this->collectShipmentsData(), $test_mode);
            if ( $result ) {
                Configuration::updateValue('omnivalt_last_statistics_send', time());
            }
            Configuration::updateValue('omnivalt_last_statistics_try', time()); // Try to send 1 time per day, if result false
        }
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        $id_product = Tools::getValue('id_product');

        if ($this->isPs17()) {
            $id_product = (int) $params['id_product'];
        }

        $is18Plus = OmnivaProduct::get18PlusStatus($id_product, true);
        $isFragile = OmnivaProduct::getFragileStatus($id_product, true);

        $this->context->smarty->assign([
            'is18Plus' => $is18Plus,
            'isFragile' => $isFragile,
        ]);

        if ($this->isPs17()) {
            return $this->display(__FILE__, 'views/templates/admin/productTab-1.7.tpl');
        }

        return $this->display(__FILE__, 'views/templates/admin/productTab.tpl');
    }

    public function hookActionProductUpdate($params)
    {
        $productID = (int) Tools::getValue('id_product');

        if ($this->isPs17()) {
            $productID = (int) $params['id_product'];
        }

        $is18Plus = (bool) Tools::getValue('omnivaltshipping_is_18_plus');
        $isFragile = (bool) Tools::getValue('omnivaltshipping_is_fragile');

        if (!OmnivaProduct::isExists($productID)) {
            DB::getInstance()->insert(
                OmnivaProduct::$definition['table'],
                [
                    'id_product' => $productID,
                    'is_18_plus' => (int) $is18Plus,
                    'is_fragile' => (int) $isFragile
                ]
            );

            return;
        }

        DB::getInstance()->update(
            OmnivaProduct::$definition['table'],
            [
                'is_18_plus' => (int) $is18Plus,
                'is_fragile' => (int) $isFragile
            ],
            'id_product = ' . $productID
        );
    }

    public function getCustomOrderState()
    {
        $omnivalt_order_state = (int)Configuration::get('omnivalt_order_state');
        $order_status = new OrderState((int)$omnivalt_order_state, (int)$this->context->language->id);
        if (!$order_status->id || !$omnivalt_order_state) {
            $orderState = new OrderState();
            $orderState->name = array();
            foreach (Language::getLanguages() as $language) {
                if (strtolower($language['iso_code']) == 'lt')
                    $orderState->name[$language['id_lang']] = 'Paruošta siųsti su Omniva';
                else
                    $orderState->name[$language['id_lang']] = 'Shipment ready for Omniva';
            }
            $orderState->send_email = false;
            $orderState->color = '#DDEEFF';
            $orderState->hidden = false;
            $orderState->delivery = false;
            $orderState->logable = true;
            $orderState->invoice = false;
            $orderState->unremovable = false;
            if ($orderState->add()) {
                Configuration::updateValue('omnivalt_order_state', $orderState->id);
                return $orderState->id;
            }
        }
        return $omnivalt_order_state;
    }

    public function getErrorOrderState()
    {
        $omnivalt_order_state = (int)Configuration::get('omnivalt_error_state');
        $order_status = new OrderState((int)$omnivalt_order_state, (int)$this->context->language->id);
        if (!$order_status->id || !$omnivalt_order_state) {
            $orderState = new OrderState();
            $orderState->name = array();
            foreach (Language::getLanguages() as $language) {
                if (strtolower($language['iso_code']) == 'lt')
                    $orderState->name[$language['id_lang']] = 'Omnivos siuntos klaida';
                else
                    $orderState->name[$language['id_lang']] = 'Error with Omniva parcel';
            }
            $orderState->send_email = false;
            $orderState->color = '#F22323';
            $orderState->hidden = false;
            $orderState->delivery = false;
            $orderState->logable = true;
            $orderState->invoice = true;
            $orderState->unremovable = false;
            if ($orderState->add()) {
                Configuration::updateValue('omnivalt_error_state', $orderState->id);
                return $orderState->id;
            }
        }
        return $omnivalt_order_state;
    }

    public function install()
    {
        if (parent::install()) {
            foreach ($this->_hooks as $hook) {
                if (!$this->registerHook($hook)) {
                    return false;
                }
            }

            if (!$this->createDbTables()) {
                $this->_errors[] = $this->l('Failed to create tables.');
                return false;
            }

            $this->registerTabs();
            Configuration::updateValue('omnivalt_manifest', 1);

            //install of custom state
            $this->getCustomOrderState();
            $this->getErrorOrderState();
            return true;
        }

        return false;
    }

    protected function createCarriers()
    {
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $this->createCarrier($key);
        }

        return true;
    }

    protected function createCarrier($method_key)
    {
        $carrier_title = (isset(OmnivaCarrier::getAllMethods()[$method_key])) ? OmnivaCarrier::getAllMethods()[$method_key] : 'Omniva carrier';
        return OmnivaCarrier::createCarrier($method_key, $carrier_title, $this->name, dirname(__FILE__) . '/views/img/omnivalt-logo.jpg');
    }

    protected function deleteCarriers()
    {
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            OmnivaCarrier::removeCarrier($key);
        }

        return true;
    }

    /**
     * Deletes module Admin controllers
     * Used for module uninstall
     *
     * @return bool Module Admin controllers deleted successfully
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function deleteTabs()
    {
        $tabs = $this->getModuleTabs();

        if (empty($tabs)) {
            return true; // Nothing to remove
        }

        foreach (array_keys($tabs) as $controller) {
            $idTab = (int) Tab::getIdFromClassName($controller);
            $tab = new Tab((int) $idTab);

            if (!Validate::isLoadedObject($tab)) {
                continue; // Nothing to remove
            }

            if (!$tab->delete()) {
                $this->displayError($this->l('Error while uninstalling tab') . ' ' . $tab->name);
                return false;
            }
        }

        return true;
    }

    public function uninstall()
    {
        if (parent::uninstall()) {

            $this->deleteTabs();

            if (Configuration::get('omnivalt_uninstall_tables')) {
                $cDb = new OmnivaDb();
                $cDb->deleteTables();
            }

            if (Configuration::get('omnivalt_uninstall_carriers')) {
                $this->deleteCarriers();
            }

            Configuration::deleteByName('omnivalt_uninstall_tables');
            Configuration::deleteByName('omnivalt_uninstall_carriers');

            return true;
        }

        return false;
    }

    public function getPsVersion()
    {
        $version_parts = explode('.', _PS_VERSION_);
        return $version_parts[0] . '.' . $version_parts[1];
    }

    public function isPs16()
    {
        return version_compare(_PS_VERSION_, '1.7.0', '<');
    }

    public function isPs17()
    {
        return version_compare(_PS_VERSION_, '1.7.0', '>=');
    }

    public function isPs177()
    {
        return version_compare(_PS_VERSION_, '1.7.7', '>=');
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        $carrier = isset(self::$_omniva_cache[(int) $this->id_carrier]) ? self::$_omniva_cache[(int) $this->id_carrier] : new Carrier((int) $this->id_carrier);

        $omniva_references = array();
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $omniva_references[$key] = (int) OmnivaCarrier::getReference($key);
        }

        if (isset($this->context->cart->id_address_delivery)) {
            $address = new Address($this->context->cart->id_address_delivery);

            $shipment_codes = $this->api->getShipmentCodes($carrier->id);
            $shipment_keys = array(
                'type' => $shipment_codes->type_key,
                'channel' => $shipment_codes->channel_key
            );
            
            $default_iso_code = Configuration::get('omnivalt_default_receiver_countrycode');
            if (!$default_iso_code) $default_iso_code = $this->context->language->iso_code;
            $iso_code = $address->id_country ? Country::getIsoById($address->id_country) : $default_iso_code;
            $iso_code = strtoupper($iso_code);

            if ((int) $carrier->id_reference === $omniva_references['omnivalt_pt']) {
                $terminals_type = OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key);
                if (!$terminals_type || !OmnivaApiServices::haveTerminals($iso_code)) {
                    return false;
                }

                if ( (float) $carrier->max_depth > 0 && (float) $carrier->max_width > 0 && (float) $carrier->max_height ) {
                    $products = OmnivaHelper::getCartItems($params->getProducts(false, false), true);
                    $cart_size = OmnivaHelper::predictOrderSize($products, array(
                        'length' => (float) $carrier->max_depth,
                        'width' => (float) $carrier->max_width,
                        'height' => (float) $carrier->max_height,
                    ));
                    if ( ! $cart_size ) {
                        return false;
                    }
                }
            } else if (in_array((int) $carrier->id_reference, $omniva_references)) {
                $method_key = array_search((int) $carrier->id_reference, $omniva_references);
                $method_short_key = str_replace('omnivalt_', '', $method_key);
                $shipment_keys['method'] = $method_key;
                if (!OmnivaApiInternational::isOmnivaMethodAllowed($shipment_keys, $iso_code)) {
                    return false;
                }
                if (OmnivaApiInternational::isInternationalMethod($method_key)) {
                    $package_key = OmnivaApiInternational::getPackageKeyFromMethodKey($method_key);
                    $products = OmnivaHelper::getCartItems($params->getProducts(false, false), true);
                    if (!OmnivaApiInternational::isPackageAvailableForItems($package_key, $iso_code, $products)) {
                        return false;
                    }
                }
            }
        }

        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    public function hookUpdateCarrier($params)
    {
        $id_carrier_old = (int)($params['id_carrier']);
        $id_carrier_new = (int)($params['carrier']->id);

        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            if ($id_carrier_old == (int)OmnivaCarrier::getId($key))
                OmnivaCarrier::updateMappingValues($key, $id_carrier_new);
        }
    }

    public function checkForUpdate()
    {
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, self::UPDATE_URL);
        curl_setopt($ch, CURLOPT_USERAGENT,'Awesome-Octocat-App');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        $response_data = json_decode(curl_exec($ch)); 
        curl_close($ch);
    
        $update_info = null;
        if (isset($response_data->tag_name)) {
            $update_info = [
                'version' => str_replace('v', '', $response_data->tag_name),
                'url' => (isset($response_data->html_url)) ? $response_data->html_url : '#',
            ];
        }
        return $update_info;
    }

    /*
    ** Form Config Methods
    **
    */
    public function getContent()
    {
        $output = null;
        $updateData = $this->checkForUpdate();
        if ($updateData && version_compare($this->version, $updateData['version'], '<'))
        {
            $this->context->smarty->assign([
                'update_url' => self::UPDATE_URL,
                'release_url' => $updateData['url'],
                'download_url' => self::DOWNLOAD_URL,
                'version' => $updateData['version'],
            ]);
            $output .= $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name .'/views/templates/admin/update.tpl');
        }

        if (Tools::getValue('forceUpdateTerminals')) {
            if ($this->helper->updateTerminals()) {
                $output .= $this->displayConfirmation($this->l('Terminals updated'));
                Configuration::updateValue('omnivalt_locations_update', time());
            }
        }

        if (Tools::getValue('forceSendStatistics')) {
            $this->sendStatistics(true, true);
        }

        if (Tools::isSubmit($this->name . '_submit_settings')) {
            $fields = $this->getSettingsFields();
            $required = array(
                'omnivalt_api_user', 'omnivalt_api_pass', 'omnivalt_api_country',
                'omnivalt_company', 'omnivalt_address', 'omnivalt_city', 'omnivalt_postcode',
                'omnivalt_countrycode', 'omnivalt_phone', 'omnivalt_pick_up_time_start',
                'omnivalt_pick_up_time_finish', 'omnivalt_send_off'
            );
            $values = array();
            $all_filled = true;
            $missing_fields = array();
            foreach ($fields as $field_key => $title) {
                $values[$field_key] = trim(strval(Tools::getValue($field_key)));
                if ($values[$field_key] === '' && in_array($field_key, $required)) {
                    $all_filled = false;
                    $missing_fields[] = $title;
                }
            }

            if (!$all_filled)
                $output .= $this->displayError(sprintf($this->l('Failed to save. These fields are required: %s'), '<br/><b>' .implode('<br/>', $missing_fields) . '</b>'));
            else {
                foreach ($values as $key => $val) {
                    Configuration::updateValue($key, $val);
                }
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        if (Tools::isSubmit($this->name . '_submit_refresh_carriers')) {
            foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
                $field_value = strval(Tools::getValue($key));
                if ($field_value) {
                    $restored = OmnivaCarrier::unmarkAsDeleted($key);
                    if (!$restored) {
                        $created = $this->createCarrier($key);
                    }
                } else {
                    $deleted = OmnivaCarrier::markAsDeleted($key);
                }
            }
            $output .= $this->displayConfirmation($this->l('Carriers updated'));
        }
        if (Tools::isSubmit($this->name . '_submit_uninstall')) {
            $fields = array(
                'omnivalt_uninstall_tables', 'omnivalt_uninstall_carriers'
            );

            foreach ($fields as $field) {
                Configuration::updateValue($field, strval(Tools::getValue($field)));
            }
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output . $this->displayForm();
    }

    private function getSettingsFields()
    {
        return array(
            'omnivalt_map' => $this->l('Display map'),
            'send_delivery_email' => $this->l('Send delivery email'),
            'omnivalt_api_url' => $this->l('Api URL'),
            'omnivalt_api_user' => $this->l('API login user'),
            'omnivalt_api_pass' => $this->l('API login password'),
            'omnivalt_api_country' => $this->l('API login country'),
            'omnivalt_ee_service' => $this->l('Estonia Carrier Service'),
            'omnivalt_fi_service' => $this->l('Finland Carrier Service'),
            'omnivalt_send_off' => $this->l('Send off type'),
            'omnivalt_bank_account' => $this->l('Bank account'),
            'omnivalt_company' => $this->l('Company name'),
            'omnivalt_address' => $this->l('Company address'),
            'omnivalt_city' => $this->l('Company city'),
            'omnivalt_postcode' => $this->l('Company postcode'),
            'omnivalt_countrycode' => $this->l('Company country code'),
            'omnivalt_phone' => $this->l('Company phone number'),
            'omnivalt_pick_up_time_start' => $this->l('Pick up time start'),
            'omnivalt_pick_up_time_finish' => $this->l('Pick up time finish'),
            'omnivalt_print_type' => $this->l('Labels print type'),
            'omnivalt_send_return' => $this->l('Send return code'),
            'omnivalt_manifest_lang' => $this->l('Manifest language'),
            'omnivalt_label_comment_type' => $this->l('Label comment'),
            'omnivalt_autoselect' => $this->l('Autoselect terminal'),
            'omnivalt_default_receiver_countrycode' => $this->l('Default country of delivery')
        );
    }

    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $countries_list = OmnivaHelper::getEuCountriesList(Context::getContext()->language->id);

        $lang_options = array(
            array(
                'id_option' => 'en',
                'name' => $this->l('English')
            ),
            array(
                'id_option' => 'ee',
                'name' => $this->l('Estonian') . ' (' . $this->l('English') . ')'
            ),
            array(
                'id_option' => 'lv',
                'name' => $this->l('Latvian')
            ),
            array(
                'id_option' => 'lt',
                'name' => $this->l('Lithuanian')
            ),
        );
        $methods_options = array(
            array(
                'id_option' => 'pt',
                'name' => $this->l('Parcel terminal')
            ),
            array(
                'id_option' => 'c',
                'name' => $this->l('Courier')
            ),
            array(
                'id_option' => 'po',
                'name' => $this->l('Post Office')
            ),
            array(
                'id_option' => 'lc',
                'name' => $this->l('Logistics Center')
            ),
        );
        $print_options = array(
            array(
                'id_option' => 'single',
                'name' => $this->l('Original (single label)')
            ),
            array(
                'id_option' => 'four',
                'name' => $this->l('A4 (4 labels)')
            ),
        );
        $label_comment_options = array(
            array(
                'id_option' => OmnivaApi::LABEL_COMMENT_TYPE_NONE,
                'name' => $this->l('No comment')
            ),
            array(
                'id_option' => OmnivaApi::LABEL_COMMENT_TYPE_ORDER_ID,
                'name' => $this->l('Order ID')
            ),
            array(
                'id_option' => OmnivaApi::LABEL_COMMENT_TYPE_ORDER_REF,
                'name' => $this->l('Order reference')
            ),
        );

        $features = Feature::getFeatures(
            Context::getContext()->language->id
        );

        $featuresOptions = array_map(function ($feature) {
            return [
                'id_option' => $feature['id_feature'],
                'name' => $this->l($feature['name'])
            ];
        }, $features);

        $last_update_timestamp = Configuration::get('omnivalt_locations_update');
        $last_update_formated = !$last_update_timestamp ? '--' : date('Y-m-d H:i:s', (int) $last_update_timestamp);
        $last_statistics_timestamp = Configuration::get('omnivalt_last_statistics_send');
        $last_statistics_formated = !$last_statistics_timestamp ? '--' : date('Y-m-d H:i:s', (int) $last_statistics_timestamp);

        $settings_fields = $this->getSettingsFields();

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'hidden', //Temporary hidden, after some time need remove this field
                    'label' => $settings_fields['omnivalt_api_url'],
                    'name' => 'omnivalt_api_url',
                    'size' => 20,
                    'required' => false
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_api_user'],
                    'name' => 'omnivalt_api_user',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_api_pass'],
                    'name' => 'omnivalt_api_pass',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $settings_fields['omnivalt_api_country'],
                    'name' => 'omnivalt_api_country',
                    'desc' => $this->l('Select the Omniva department country, from which you got the logins.'),
                    'required' => true,
                    'options' => array(
                        'query' => array(
                            array(
                                'id_option' => 'lt',
                                'name' => $this->l('Lithuania')
                            ),
                            array(
                                'id_option' => 'lv',
                                'name' => $this->l('Latvia')
                            ),
                            array(
                                'id_option' => 'ee',
                                'name' => $this->l('Estonia')
                            ),
                        ),
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'switch',
                    'label' => $settings_fields['omnivalt_ee_service'],
                    'name' => 'omnivalt_ee_service',
                    'desc' => $this->l('Activate this service, if your e-shop clients want to receive parcels in Estonia. Only available for Estonia API country.'),
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'switch',
                    'label' => $settings_fields['omnivalt_fi_service'],
                    'name' => 'omnivalt_fi_service',
                    'desc' => $this->l('Activate this service, if you want to send parcels to Finland. Only available for Estonia API country.'),
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'html',
                    'name' => 'omnivalt_separator_sender',
                    'html_content' => '<hr/>',
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_company'],
                    'name' => 'omnivalt_company',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_bank_account'],
                    'name' => 'omnivalt_bank_account',
                    'size' => 20,
                    'required' => false
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_address'],
                    'name' => 'omnivalt_address',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_city'],
                    'name' => 'omnivalt_city',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_postcode'],
                    'name' => 'omnivalt_postcode',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'select',
                    'label' => $settings_fields['omnivalt_countrycode'],
                    'name' => 'omnivalt_countrycode',
                    'required' => true,
                    'options' => array(
                        'query' => $this->buildCountriesFieldOptions($countries_list),
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_phone'],
                    'name' => 'omnivalt_phone',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_pick_up_time_start'],
                    'name' => 'omnivalt_pick_up_time_start',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $settings_fields['omnivalt_pick_up_time_finish'],
                    'name' => 'omnivalt_pick_up_time_finish',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $settings_fields['omnivalt_send_off'],
                    'name' => 'omnivalt_send_off',
                    'desc' => $this->l('Please select send off from store type'),
                    'required' => true,
                    'options' => array(
                        'query' => $methods_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'html',
                    'name' => 'omnivalt_separator_front',
                    'html_content' => '<hr/>',
                ),
                array(
                    'type' => 'select',
                    'label' => $settings_fields['omnivalt_default_receiver_countrycode'],
                    'name' => 'omnivalt_default_receiver_countrycode',
                    'options' => array(
                        'query' => $this->buildCountriesFieldOptions($countries_list, $this->l('Not specified')),
                        'id' => 'id_option',
                        'name' => 'name'
                    ),
                    'desc' => $this->l('You can specify a default customer country that will be used until the delivery address is entered. This allows shipping methods to be loaded on the Cart and Checkout pages until the entered address is saved in the Shipping Address step.'),
                ),
                array(
                    'type' => 'switch',
                    'label' => $settings_fields['omnivalt_map'],
                    'name' => 'omnivalt_map',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'switch',
                    'label' => $settings_fields['omnivalt_autoselect'],
                    'name' => 'omnivalt_autoselect',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'html',
                    'name' => 'omnivalt_separator_label',
                    'html_content' => '<hr/>',
                ),
                array(
                    'type' => 'switch',
                    'label' => $settings_fields['send_delivery_email'],
                    'name' => 'send_delivery_email',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'switch',
                    'label' => $settings_fields['omnivalt_send_return'],
                    'name' => 'omnivalt_send_return',
                    'desc' => $this->l("Please note that extra charges may apply. For more information, contact your Omniva`s business customer support."),
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $settings_fields['omnivalt_print_type'],
                    'name' => 'omnivalt_print_type',
                    'required' => false,
                    'options' => array(
                        'query' => $print_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $settings_fields['omnivalt_label_comment_type'],
                    'name' => 'omnivalt_label_comment_type',
                    'required' => false,
                    'options' => array(
                        'query' => $label_comment_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'html',
                    'name' => 'omnivalt_separator_manifest',
                    'html_content' => '<hr/>',
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $settings_fields['omnivalt_manifest_lang'],
                    'name' => 'omnivalt_manifest_lang',
                    'required' => false,
                    'options' => array(
                        'query' => $lang_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right',
                'name' => $this->name . '_submit_settings',
            ),
            'buttons' => [
                [
                    'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                    'js' => 'omivaltshippingForceTerminalUpdate(this); return false;',
                    'class' => '',
                    'type' => 'button',
                    'id'   => 'omniva-update-terminals',
                    'name' => 'updateTerminals',
                    'icon' => 'process-icon-refresh',
                    'title' => $this->l('Updated Terminals:') . ' ' . $last_update_formated
                ],
                [
                    'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                    'js' => 'omivaltshippingForceSendStatistics(this); return false;',
                    'class' => 'omniva-devtool hidden',
                    'type' => 'button',
                    'id'   => 'omniva-send-statistics',
                    'name' => 'sendStatistics',
                    'icon' => 'icon-suitcase',
                    'title' => $this->l('Send statistics:') . ' ' . $last_statistics_formated
                ],
            ]
        );

        $fields_form[1]['form'] = array(
            'legend' => array(
                'title' => $this->l('Carriers'),
            ),
            'description' => $this->l('After activating the shipping method below, a new Carrier is created in the Prestashop shipping carriers list. After deactivating the shipping method below, the shipping carrier is marked as "Deleted" in the Prestashop shipping carriers list and reactivating the shipping method removes mark for the "Deleted" parameter (the carrier is displayed again with the parameters it had before).'),
            'input' => array(
                //Carriers are added below
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right',
                'name' => $this->name . '_submit_refresh_carriers',
            ),
        );

        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $fields_form[1]['form']['input'][] = array(
                'type' => 'switch',
                'label' => $title,
                'name' => $key,
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'carrier_on',
                        'value' => 1,
                        'label' => $this->l('Added')
                    ),
                    array(
                        'id' => 'carrier_off',
                        'value' => 0,
                        'label' => $this->l('Removed')
                    )
                ),
            );
            $carrier = OmnivaCarrier::getCarrier($key);
        }

        $fields_form[2]['form'] = array(
            'legend' => array(
                'title' => $this->l('Module uninstall'),
            ),
            'warning' => $this->l('The enabled actions in this section will be performed when uninstalling the module. We recommend enabling this section parameters only when you intend to no longer use the module or planing a clean reinstallation of it.'),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Delete database tables'),
                    'desc' => $this->l('Delete tables created by the module from the database.'),
                    'name' => 'omnivalt_uninstall_tables',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Completely delete carriers'),
                    'desc' => $this->l('Completely delete carriers created by this module from the database. After a carrier is completely deleted, it will no longer show in existing Orders.'),
                    'name' => 'omnivalt_uninstall_carriers',
                    'is_bool' => true,
                    'values' => array(
                        array(
                            'id' => 'label2_on',
                            'value' => 1,
                            'label' => $this->l('Enabled')
                        ),
                        array(
                            'id' => 'label2_off',
                            'value' => 0,
                            'label' => $this->l('Disabled')
                        )
                    )
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right',
                'name' => $this->name . '_submit_uninstall',
            ),
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' =>
                array(
                    'desc' => $this->l('Save'),
                    'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                        '&token=' . Tools::getAdminTokenLite('AdminModules'),
                ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        $helper->fields_value['omnivalt_api_url'] = Configuration::get('omnivalt_api_url');
        if ($helper->fields_value['omnivalt_api_url'] == "") {
            $helper->fields_value['omnivalt_api_url'] = "https://edixml.post.ee";
        }
        $helper->fields_value['omnivalt_api_user'] = Configuration::get('omnivalt_api_user');
        $helper->fields_value['omnivalt_api_pass'] = Configuration::get('omnivalt_api_pass');
        $helper->fields_value['omnivalt_api_country'] = Configuration::get('omnivalt_api_country');
        $helper->fields_value['omnivalt_ee_service'] = Configuration::get('omnivalt_ee_service');
        $helper->fields_value['omnivalt_fi_service'] = Configuration::get('omnivalt_fi_service');
        $helper->fields_value['omnivalt_send_off'] = Configuration::get('omnivalt_send_off');
        $helper->fields_value['omnivalt_company'] = Configuration::get('omnivalt_company');
        $helper->fields_value['omnivalt_address'] = Configuration::get('omnivalt_address');
        $helper->fields_value['omnivalt_city'] = Configuration::get('omnivalt_city');
        $helper->fields_value['omnivalt_postcode'] = Configuration::get('omnivalt_postcode');
        $helper->fields_value['omnivalt_countrycode'] = Configuration::get('omnivalt_countrycode');
        $helper->fields_value['omnivalt_phone'] = Configuration::get('omnivalt_phone');
        $helper->fields_value['omnivalt_bank_account'] = Configuration::get('omnivalt_bank_account');
        $helper->fields_value['omnivalt_pick_up_time_start'] = Configuration::get('omnivalt_pick_up_time_start') ? Configuration::get('omnivalt_pick_up_time_start') : '8:00';
        $helper->fields_value['omnivalt_pick_up_time_finish'] = Configuration::get('omnivalt_pick_up_time_finish') ? Configuration::get('omnivalt_pick_up_time_finish') : '17:00';
        $helper->fields_value['omnivalt_default_receiver_countrycode'] = Configuration::get('omnivalt_default_receiver_countrycode');
        $helper->fields_value['omnivalt_map'] = Configuration::get('omnivalt_map');
        $helper->fields_value['omnivalt_autoselect'] = Configuration::get('omnivalt_autoselect');
        $helper->fields_value['send_delivery_email'] = Configuration::get('send_delivery_email');
        $helper->fields_value['omnivalt_send_return'] = Configuration::get('omnivalt_send_return');
        $helper->fields_value['omnivalt_print_type'] = Configuration::get('omnivalt_print_type') ? Configuration::get('omnivalt_print_type') : 'four';
        $helper->fields_value['omnivalt_label_comment_type'] = Configuration::get('omnivalt_label_comment_type') ? Configuration::get('omnivalt_label_comment_type') : OmnivaApi::LABEL_COMMENT_TYPE_NONE;
        $helper->fields_value['omnivalt_manifest_lang'] = Configuration::get('omnivalt_manifest_lang') ? Configuration::get('omnivalt_manifest_lang') : 'en';
        foreach (OmnivaCarrier::getAllMethods() as $key => $title) {
            $carrier = OmnivaCarrier::getCarrier($key);
            $helper->fields_value[$key] = ($carrier && !$carrier->deleted) ? 1 : 0;
        }
        $helper->fields_value['omnivalt_uninstall_tables'] = Configuration::get('omnivalt_uninstall_tables');
        $helper->fields_value['omnivalt_uninstall_carriers'] = Configuration::get('omnivalt_uninstall_carriers');

        return $helper->generateForm($fields_form);
    }

    private function buildCountriesFieldOptions($countries_list, $empty_value_label = false)
    {
        $countries_options = array();
        if ( $empty_value_label ) {
            $countries_options[] = array(
                'id_option' => '',
                'name' => '- ' . $empty_value_label . ' -'
            );
        }
        foreach ( $countries_list as $country_code => $country_name ) {
            $countries_options[] = array(
                'id_option' => $country_code,
                'name' => $country_name
            );
        }

        return $countries_options;
    }

    private function getTerminalsList($carrier_id, $country = "LT")
    {
        if (!$country) {
            $country = Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        }

        if ( ! OmnivaApiServices::haveTerminals($country) ) {
            return [];
        }

        $shipment_codes = $this->api->getShipmentCodes($carrier_id);
        if ( ! $shipment_codes->_exists ) {
            return [];
        }

        $terminals_type = OmnivaApiServices::getTerminalsType($shipment_codes->type_key, $shipment_codes->channel_key);
        if ( ! $terminals_type ) {
            return [];
        }
        $terminals_type_code = ($terminals_type === 'post') ? 1 : 0;

        $terminals = file_get_contents(__DIR__ . "/locations.json");
        $terminals = json_decode($terminals, true);
        $terminals_list = array();
        if ( is_array($terminals) ) {
            foreach ( $terminals as $terminal ) {
                if ( $terminal['A0_NAME'] != $country || intval($terminal['TYPE']) != $terminals_type_code )
                    continue;

                // Remove unnecessary info
                unset($terminal['TYPE']);
                unset($terminal['TEMP_SERVICE_HOURS']);
                unset($terminal['TEMP_SERVICE_HOURS_UNTIL']);
                unset($terminal['TEMP_SERVICE_HOURS_2']);
                unset($terminal['TEMP_SERVICE_HOURS_2_UNTIL']);
                unset($terminal['MODIFIED']);

                $terminals_list[] = $terminal;
            }
        }
        return $terminals_list;
    }

    private function getTerminalsOptions( $terminals_list, $selected = '' )
    {
        if ( empty($terminals_list) || ! is_array($terminals_list) ) {
            return;
        }

        $grouped_options = array();
        foreach ( $terminals_list as $terminal ) {
            $group_name = (string) $terminal['A1_NAME'];
            if ( ! isset($grouped_options[$group_name]) ) {
                $grouped_options[$group_name] = array();
            }
            $address = trim($terminal['A2_NAME'] . ' ' . ($terminal['A5_NAME'] != 'NULL' ? $terminal['A5_NAME'] : '') . ' ' . ($terminal['A7_NAME'] != 'NULL' ? $terminal['A7_NAME'] : ''));
            $grouped_options[$group_name][(string)$terminal['ZIP']] = $terminal['NAME'] . ' (' . $address . ')';
        }
        ksort($grouped_options);
        
        $this->context->smarty->assign([
            'grouped_options' => $grouped_options,
            'selected' => $selected,
        ]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name .'/views/templates/front/omniva-terminals.tpl');
    }

    /**
     * Generate terminal list with coordinates info
     */
    private function getTerminalForMap( $terminals_list, $selected = '', $country = "LT" )
    {
        if ( empty($terminals_list) || ! is_array($terminals_list) ) {
            return;
        }

        $map_terminals = array();
        foreach ( $terminals_list as $terminal ) {
            switch ( $country ) {
                case 'LT':
                    $comment = $terminal['comment_lit'];
                    break;
                case 'LV':
                    $comment = $terminal['comment_lav'];
                    break;
                case 'EE':
                    $comment = $terminal['comment_est'];
                    break;
                default:
                    $comment = $terminal['comment_lit'];
            }
            $map_terminals[] = [
                $terminal['NAME'],
                $terminal['Y_COORDINATE'],
                $terminal['X_COORDINATE'],
                $terminal['ZIP'],
                $terminal['A1_NAME'],
                $terminal['A2_NAME'],
                $comment
            ];
        }

        return $map_terminals;
    }

    public static function getTerminalAddress($code)
    {
        $terminals = file_get_contents(__DIR__ . "/locations.json");
        $terminals = json_decode($terminals, true);

        if (is_array($terminals)) {
            foreach ($terminals as $terminal) {
                if ($terminal['ZIP'] == $code) {
                    return $terminal['NAME'] . ', ' . $terminal['A2_NAME'] . ', ' . $terminal['A0_NAME'];
                }
            }
        }
        return '';
    }

    private function getCarriersOptions($selected = '')
    {
        $carriers = '';
        foreach ( OmnivaCarrier::getAllMethods() as $key => $title ) {
            $carrier = OmnivaCarrier::getCarrier($key);
            if ( ! empty($carrier->id) ) {
                $carriers .= '<option value = "' . $carrier->id . '" ' . ($carrier->id == $selected ? 'selected' : '') . '>' . $this->l($title) . '</option>';
            }
        }
        return $carriers;
    }

    public function hookDisplayBeforeCarrier($params)
    {
        $address = new Address($params['cart']->id_address_delivery);
        $iso_code = $this->getCartCountryCode($params['cart']);

        $showMap = Configuration::get('omnivalt_map');
        $autoselect = Configuration::get('omnivalt_autoselect');
        $this->context->smarty->assign(array(
            'omniva_current_country' => $iso_code,
            'omniva_postcode' => $address->postcode ?: '',
            'omniva_map' => $showMap,
            'autoselect' => (int)$autoselect,
            'ps_version' => $this->getPsVersion(),
        ));
        
        $tpl1 = $this->display(__FILE__, 'displayBeforeCarrier.tpl');
        $tpl2 = $this->display(__FILE__, 'modalMap.tpl');
        return $tpl1 . $tpl2;
    }

    public function hookDisplayCarrierExtraContent($params)
    {
        if ( is_object($params['carrier']) ) {
            $carrier_id = (int) $params['carrier']->id;
        } else if ( is_array($params['carrier']) && isset($params['carrier']['id']) ) {
            $carrier_id = (int) $params['carrier']['id'];
        } else {
            return '';
        }

        $selected = '';
        if ( isset($params['cart']->id) ) {
            $omnivaCart = new OmnivaCartTerminal($params['cart']->id);
            $selected = $omnivaCart->id_terminal;
        }

        $address = new Address($params['cart']->id_address_delivery);
        $iso_code = $this->getCartCountryCode($params['cart']);

        $terminals = $this->getTerminalsList($carrier_id, $iso_code);
        if ( empty($terminals) ) {
            return '';
        }

        $marker_img = 'sasi.png';
        if ($iso_code == 'FI') {
            $marker_img = 'sasi_mh.svg';
        }

        $showMap = Configuration::get('omnivalt_map');

        $this->context->smarty->assign(array(
            'module_url' => $this->_path,
            'parcel_terminals' => $this->getTerminalsOptions($terminals, $selected),
            'terminals_list' => $this->getTerminalForMap($terminals, $selected, $iso_code),
            'marker_img' => $marker_img,
            'select_block_theme' => ($iso_code == 'FI') ? 'matkahuolto' : 'omniva',
            'omniva_map' => $showMap
        ));

        return $this->display(__file__, 'displayCarrierExtraContent.tpl');
    }

    private function getCartCountryCode( $cart )
    {
        $default_country = (isset($this->context->country->iso_code)) ? $this->context->country->iso_code : $this->context->language->iso_code;

        $address = new Address($cart->id_address_delivery);
        $iso_code = $address->id_country ? Country::getIsoById($address->id_country) : $default_country;

        return strtoupper($iso_code);
    }

    public function hookDisplayBackOfficeHeader($params)
    {

    }

    public function hookActionAdminControllerSetMedia()
    {
        if (get_class($this->context->controller) == 'AdminOrdersController' || get_class($this->context->controller) == 'AdminLegacyLayoutControllerCore'
            || (isset($this->context->controller->module) && $this->context->controller->module == $this) || Tools::getValue('configure') == $this->name) {
                Media::addJsDef([
                    'omnivalt_bulk_labels' => $this->l("Print Omnivalt labels"),
                    'omnivalt_bulk_manifests' => $this->l("Print Omnivalt manifests"),
                    'omnivalt_admin_action_labels' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . "&action=bulkPrintLabels",
                    'omnivalt_admin_action_manifests' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . "&action=printAllManifests",
                    'printLabelsUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=generateLabels',
                    'success_add_trans' => $this->l('Successfully added.'),
                    'moduleUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=saveOrderInfo',
                    'omnivalt_terminal_carrier' => OmnivaCarrier::getId('omnivalt_pt'),
                    'omnivalt_methods' => OmnivaCarrier::getAllMethodsData(),
                    'omnivalt_text' => array(
                        'ajax_parsererror' => $this->l("An invalid response was received"),
                        'ajax_unknownerror' => $this->l("Unknown error"),
                        'save_success' => $this->l('Successfully saved'),
                    ),
                    'omnivaltIsPS177Plus' => $this->isPs177(),
                ]);
                $this->context->controller->addJS($this->_path . '/views/js/adminOmnivalt.js');

            if(Tools::getValue('configure') !== $this->name)
            {
                if ($this->isPs177())
                {
                    $this->context->controller->addJS($this->_path . 'views/js/omniva-admin-order-177.js');
                }
                else
                {
                    $this->context->controller->addJS($this->_path . 'views/js/omniva-admin-order.js');
                }
            }
        }
    }

    public function hookHeader($params)
    {
        if (in_array(Context::getContext()->controller->php_self, array('order-opc', 'order'))) {
            Media::addJsDef([
                'omnivalt_params' => [
                    'url' => [
                        'plugin' => Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/',
                        'images' => Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/views/img/',
                        'controller_ajax' => $this->context->link->getModuleLink('omnivaltshipping', 'ajax'),
                    ],
                    'methods' => [
                        'omniva_terminal' => OmnivaCarrier::getId('omnivalt_pt')
                    ],
                    'prestashop' => [
                        'is_16' => $this->isPs16(),
                        'is_17' => $this->isPs17(),
                        'is_177' => $this->isPs177(),
                    ],
                ],
                'omnivalt_text' => [
                    'select_terminal' => $this->l('Select terminal'),
                    'select_terminal_desc' => $this->l('Please select a parcel terminal'),
                    'select_terminal_error' => $this->l('Please select parcel terminal'),
                    'search_placeholder' => $this->l('Enter postcode'),
                    'search_desc' => $this->l('Enter an address, if you want to find terminals'),
                    'not_found' => $this->l('Place not found'),
                    'enter_address' => $this->l('Enter postcode/address'),
                    'show_in_map' => $this->l('Show in map'),
                    'show_more' => $this->l('Show more'),
                    'variables' => [
                        'omniva' => [
                            'modal_title' => $this->l('Omniva parcel terminals'),
                        ],
                        'matkahuolto' => [
                            'modal_title' => $this->l('Matkahuolto parcel terminals'),
                        ],
                    ],
                ],
            ]);
            if ($this->isPs17()) {
                $this->context->controller->registerJavascript(
                    'leaflet',
                    'modules/' . $this->name . '/views/js/leaflet.js',
                    ['priority' => 190]
                );

                $this->context->controller->registerJavascript(
                    'omnivalt',
                    'modules/' . $this->name . '/views/js/omniva.js',
                    [
                        'priority' => 200,
                    ]
                );
            } else {
                $this->context->controller->addJS($this->_path . '/views/js/leaflet.js');
                $this->context->controller->addJS($this->_path . '/views/js/omniva.js');
            }
            $this->context->controller->addCSS($this->_path . '/views/css/leaflet.css');
            $this->context->controller->addCSS($this->_path . '/views/css/omniva.css');
        }
    }

    public function hookDisplayHeader($params)
    {
        return $this->hookHeader($params);
    }

    public static function getCarrierIds($carriers = [])
    {
        // use only supplied or all
        $carriers = count($carriers) > 0 ? $carriers : array_keys(OmnivaCarrier::getAllMethods());
        $ref = [];
        foreach ($carriers as $value) {
            $carrier_ref_id = OmnivaCarrier::getReference($value);
            if ($carrier_ref_id) {
                $ref[] = OmnivaCarrier::getReference($value);
            }
        }
        $data = [];
        if ($ref) {
            $sql = 'SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE id_reference IN(' . implode(',', $ref) . ')';
            $result = Db::getInstance()->executeS($sql);
            foreach ($result as $value) {
                $data[] = (int)$value['id_carrier'];
            }
            sort($data);
        }
        return $data;
    }

    public function changeOrderStatus($id_order, $status, $template_vars = false)
    {
        $order = new Order((int)$id_order);
        if ($order->current_state != $status) // && $order->current_state != Configuration::get('PS_OS_SHIPPING'))
        {
            $history = new OrderHistory();
            $history->id_order = (int)$id_order;
            $history->id_employee = (int)$this->context->employee->id;
            $history->changeIdOrderState((int)$status, $order);
            $history->addWithEmail(true, $template_vars);
        }
    }

    public function hookDisplayAdminOrder($id_order)
    {
        $order = new Order((int)$id_order['id_order']);
        $cart = new OmnivaCartTerminal($order->id_cart);
        $carrier = self::getCarrierById($order->id_carrier);

        if ( ! $carrier ) {
            return '';
        }

        $method_key = OmnivaCarrier::getCarrierMethodKey($order->id_carrier, $carrier->id_reference);

        if ( $method_key ) {
            $international_package_key = (OmnivaApiInternational::isInternationalMethod($method_key)) ? OmnivaApiInternational::getPackageKeyFromMethodKey($method_key) : false;
            $id_terminal = $cart->id_terminal;

            $address = new Address($order->id_address_delivery);
            $countryCode = Country::getIsoById($address->id_country);

            $omnivaOrder = new OmnivaOrder($order->id);
            $printLabelsUrl = $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . "&action=printLabels&id_order=" . $order->id;

            $error_msg = $omnivaOrder->error ?: false;
            $omniva_tpl = 'blockinorder.tpl';

            if ($this->isPs177()) {
                $omniva_tpl = 'blockinorder_1_7_7.tpl';
                $error_msg = $error_msg ? $this->displayError($error_msg) : false;
            }

            $shipment_additional_services_names = array();

            try {
                if ( ! $international_package_key ) {
                    $shipment_additional_services = OmnivaApi::getAdditionalServices($order);
                    foreach ($shipment_additional_services as $key => $service) {
                        $shipment_additional_services_names[$key] = $service['title'];
                    }
                }
            } catch(Exception $e) {
                $error_msg = $this->displayError($e->getMessage());
            }

            $terminals = $this->getTerminalsList($order->id_carrier, $countryCode);

            $this->smarty->assign(array(
                'is_international' => ($international_package_key) ? true : false,
                'total_weight' => $omnivaOrder->weight,
                'packs' => $omnivaOrder->packs,
                'total_paid_tax_incl' => $omnivaOrder->cod_amount,
                'is_cod' => $omnivaOrder->cod,
                'parcel_terminals' => $this->getTerminalsOptions($terminals, $id_terminal),
                'active_additional_services' => implode(', ', $shipment_additional_services_names),
                'carriers' => $this->getCarriersOptions($order->id_carrier),
                'order_id' => $order->id,
                'moduleurl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=saveOrderInfo',
                'generateLabelsUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX) . '&action=generateLabels',
                'printLabelsUrl' => $printLabelsUrl,
                'is_tracked' => count((array)json_decode($omnivaOrder->tracking_numbers)) > 0,
                'error' => $error_msg,
                'orderHistory' => OmnivaOrderHistory::getHistoryByOrder($omnivaOrder->id),
            ));

            return $this->display(__FILE__, $omniva_tpl);
        }
    }

    public static function getCarrierById($carrier_id)
    {
        $carrier = new Carrier((int)$carrier_id);

        return (! empty($carrier->id)) ? $carrier : false;
    }

    public function hookOrderDetailDisplayed($params)
    {
        $order = $params['order'];
        $order = new Order($order);
        $omnivaOrder = new OmnivaOrder($order->id);
        if (Validate::isLoadedObject($omnivaOrder) && $omnivaOrder->tracking_numbers)
        {
            $address = new Address($order->id_address_delivery);
            $iso_code = Country::getIsoById($address->id_country);
            $tracking_info = $this->api->getTracking(json_decode($omnivaOrder->tracking_numbers));
            $this->context->smarty->assign([
                'tracking_info' => $tracking_info,
                'tracking_number' => $order->getWsShippingNumber(),
                'country_code' => $iso_code,
            ]);
            if($this->isPs17())
            {
                $this->context->controller->registerJavascript(
                    'omnivalt',
                    'modules/' . $this->name . '/views/js/trackingURL.js',
                    [
                        'media' => 'all',
                        'priority' => 200,
                    ]
                );   
            }
            else
            {
                $this->context->controller->addJS('modules/' . $this->name . '/views/js/trackingURL.js');   
            }

            return $this->display(__file__, 'trackingInfo.tpl');
        }
        return '';
    }

    public function hookDisplayOrderConfirmation($params)
    {
        if ( ! Validate::isLoadedObject($params['order']) ||
             ! OmnivaCarrier::isOmnivaTerminalCarrier($params['order']->id_carrier)
        ) {
            return '';
        }

        $cartTerminal = new OmnivaCartTerminal($params['order']->id_cart);
        if ( ! Validate::isLoadedObject($cartTerminal) ) {
            return '';
        }

        $terminal_address = self::getTerminalAddress($cartTerminal->id_terminal);

        $this->context->smarty->assign([
            'terminal_address' => $terminal_address
        ]);

        return $this->display(__FILE__, 'views/templates/hook/orderconfirmation.tpl');
    }

    public function hookDisplayOrderDetail($params)
    {
        if ( ! Validate::isLoadedObject($params['order']) ||
             ! OmnivaCarrier::isOmnivaCarrier($params['order']->id_carrier)
        ) {
            return '';
        }

        $terminal_address = '';
        if ( OmnivaCarrier::isOmnivaTerminalCarrier($params['order']->id_carrier) ) {
            $cartTerminal = new OmnivaCartTerminal($params['order']->id_cart);
            if ( Validate::isLoadedObject($cartTerminal) ) {
                $terminal_address = self::getTerminalAddress($cartTerminal->id_terminal);
            }
        }

        $tracking_info = array();
        $omnivaOrder = new OmnivaOrder($params['order']->id);
        if ( Validate::isLoadedObject($omnivaOrder) && $omnivaOrder->tracking_numbers) {
            $tracking_info = $this->api->getTracking(json_decode($omnivaOrder->tracking_numbers));
        }

        $address = new Address($params['order']->id_address_delivery);
        $iso_code = (Validate::isLoadedObject($address)) ? Country::getIsoById($address->id_country) : 'LT';

        $this->context->controller->addCSS($this->_path . 'views/css/omniva-front.css');

        $this->context->smarty->assign([
            'logo' => $this->_path . 'views/img/omnivalt-logo-horizontal.png',
            'country_code' => $iso_code,
            'terminal_address' => $terminal_address,
            'tracking_info' => $tracking_info,
            'tracking_url' => OmnivaApi::getTrackingUrl($iso_code),
            'show' => (! empty($terminal_address) || ! empty($tracking_info))
        ]);

        return $this->display(__FILE__, 'views/templates/hook/orderdetail.tpl');
    }

    public function hookActionEmailSendBefore($params)
    {
        $params['templateVars']['{omniva_terminal_name}'] = '';
        $params['templateVars']['{omniva_terminal_text}'] = '';

        if ( empty($params['templateVars']['{id_order}']) ) {
            return;
        }

        $order_id = (int)$params['templateVars']['{id_order}'];
        $order = new Order($order_id);
        if ( ! Validate::isLoadedObject($order) ||
             ! OmnivaCarrier::isOmnivaTerminalCarrier($order->id_carrier)
         ) {
            return;
        }

        $cartTerminal = new OmnivaCartTerminal($order->id_cart);
        if ( ! Validate::isLoadedObject($cartTerminal) ) {
            return '';
        }

        $terminal_address = self::getTerminalAddress($cartTerminal->id_terminal);

        if ( ! empty($terminal_address) ) {
            $params['templateVars']['{omniva_terminal_name}'] = $terminal_address;
            $params['templateVars']['{omniva_terminal_text}'] = '<span class="label" style="font-weight: bold;">' . $this->l('Omniva parcel terminal') . ':</span> ' . $terminal_address;
        }
    }

    /**
     * Create module database tables
     */
    public function createDbTables()
    {
        try {
            $cDb = new OmnivaDb();

            $result = $cDb->createTables();
        } catch (Exception $e) {
            $result = false;
        }
        return $result;
    }

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'];
        $carrier = new Carrier($order->id_carrier);
        if($carrier->external_module_name == $this->name)
        {
            $log_prefix = 'Cart #' . $order->id_cart . ' Order #' . $order->id . '. ';
            OmnivaHelper::printToLog($log_prefix . 'Validating Order...', 'order');
            $omnivaOrder = new OmnivaOrder();
            $omnivaOrder->force_id = true;
            $omnivaOrder->packs = 1;
            $omnivaOrder->id = $order->id;
            $omnivaOrder->weight = $order->getTotalWeight();

            $is_cod = 0;
            if(in_array($order->module, self::$_codModules))
                $is_cod = 1;
            $omnivaOrder->cod = $is_cod;

            if($omnivaOrder->weight == 0)
                $omnivaOrder->weight = 1;
            $omnivaOrder->cod_amount = $order->total_paid_tax_incl;
            $omnivaOrder->add();
            OmnivaHelper::printToLog($log_prefix . print_r(get_object_vars($omnivaOrder), true), 'order');

            // Add blank history, so that order would appear new orders tab in admin.
            $omnivaOrderHistory = new OmnivaOrderHistory();
            $omnivaOrderHistory->id_order = $order->id;
            $omnivaOrderHistory->manifest = 0;
            $omnivaOrderHistory->add();
        }
    }

    /**
     * Re calculate shipping cost. Cloned from 1.7, as 1.6 does not have this.
     *
     * @return object $order
     */
    public function refreshShippingCost($order)
    {
        if (empty($order->id)) {
            return false;
        }

        if (!Configuration::get('PS_ORDER_RECALCULATE_SHIPPING')) {
            return $order;
        }

        $fake_cart = new Cart((int) $order->id_cart);
        $new_cart = $fake_cart->duplicate();
        $new_cart = $new_cart['cart'];

        // assign order id_address_delivery to cart
        $new_cart->id_address_delivery = (int) $order->id_address_delivery;

        // assign id_carrier
        $new_cart->id_carrier = (int) $order->id_carrier;

        //remove all products : cart (maybe change in the meantime)
        foreach ($new_cart->getProducts() as $product) {
            $new_cart->deleteProduct((int) $product['id_product'], (int) $product['id_product_attribute']);
        }

        // add real order products
        foreach ($order->getProducts() as $product) {
            $new_cart->updateQty(
                $product['product_quantity'],
                (int) $product['product_id'],
                null,
                false,
                'up',
                0,
                null,
                true,
                true
            ); // - skipAvailabilityCheckOutOfStock
        }

        // get new shipping cost
        $base_total_shipping_tax_incl = (float) $new_cart->getPackageShippingCost((int) $new_cart->id_carrier, true, null);
        $base_total_shipping_tax_excl = (float) $new_cart->getPackageShippingCost((int) $new_cart->id_carrier, false, null);

        // calculate diff price, then apply new order totals
        $diff_shipping_tax_incl = $order->total_shipping_tax_incl - $base_total_shipping_tax_incl;
        $diff_shipping_tax_excl = $order->total_shipping_tax_excl - $base_total_shipping_tax_excl;

        $order->total_shipping_tax_excl -= $diff_shipping_tax_excl;
        $order->total_shipping_tax_incl -= $diff_shipping_tax_incl;
        $order->total_shipping = $order->total_shipping_tax_incl;
        $order->total_paid_tax_excl -= $diff_shipping_tax_excl;
        $order->total_paid_tax_incl -= $diff_shipping_tax_incl;
        $order->total_paid = $order->total_paid_tax_incl;
        $order->update();

        // save order_carrier prices, we'll save order right after this in update() method
        $orderCarrierId = (int) $order->getIdOrderCarrier();
        if ($orderCarrierId > 0) {
            $order_carrier = new OrderCarrier($orderCarrierId);
            $order_carrier->shipping_cost_tax_excl = $order->total_shipping_tax_excl;
            $order_carrier->shipping_cost_tax_incl = $order->total_shipping_tax_incl;
            $order_carrier->update();
        }

        // remove fake cart
        $new_cart->delete();

        return $order;
    }

    public function hookActionObjectOrderUpdateAfter($params)
    {
        $order = $params['object'];
        if(!Validate::isLoadedObject($order))
        {
            return;
        }

        $omnivaOrder = new OmnivaOrder($order->id);
        if(!Validate::isLoadedObject($omnivaOrder))
        {
            return;
        }

        $omnivaOrder->weight = $order->getTotalWeight();

        $is_cod = 0;
        if(in_array($order->module, self::$_codModules))
            $is_cod = 1;
        $omnivaOrder->cod = $is_cod;

        if($omnivaOrder->weight == 0)
            $omnivaOrder->weight = 1;
        
        $omnivaOrder->cod_amount = $order->total_paid_tax_incl;
        $omnivaOrder->update();
    }

    public function collectShipmentsData()
    {
        $_methods_keys = array(
            'terminal' => array('omnivalt_pt'),
            'courier' => array('omnivalt_c'),
        );
        OmnivaHelper::printToLog('Collecting orders data...', 'powerbi');

        // Get Orders
        $_orders = $this->getShipmentsDataFromDb('orders');

        // Count Orders
        $total_orders = array();
        foreach ( $_methods_keys as $method_name => $method_keys ) {
            $total_orders[$method_name] = count($_orders[$method_name]);
        }

        // Get Orders info
        $oldest_order = date('Y-m-d H:i:s');
        $total_shipments = array();
        $shipments_income = array();
        foreach ( $_methods_keys as $method_name => $method_keys ) {
            if ( ! isset($total_shipments[$method_name]) ) $total_shipments[$method_name] = 0;
            if ( ! isset($shipments_income[$method_name]) ) $shipments_income[$method_name] = 0;
            foreach ( $_orders[$method_name] as $order ) {
                if ( $order['omnivahistory_date_upd'] < $oldest_order ) {
                    $oldest_order = $order['omnivahistory_date_upd'];
                }
                $barcodes = json_decode($order['omnivahistory_tracking_numbers']);
                $barcodes = (is_array($barcodes)) ? $barcodes : array();
                $total_shipments[$method_name] += count($barcodes);
                $shipments_income[$method_name] += $order['shipping_cost_tax_incl'];
            }
        }

        // Get Carriers Ids
        $_carriers = $this->getShipmentsDataFromDb('carriers');

        // Get Carriers prices
        $_carriers_prices = $this->getShipmentsDataFromDb('prices', $_carriers);

        // Get Carriers prices ranges
        $_carriers_prices_ranges = $this->getShipmentsDataFromDb('prices_ranges', $_carriers);

        // Get Carriers zones
        $_carriers_zones = array();
        foreach ( $_methods_keys as $method_name => $method_keys ) {
            if ( ! isset($_carriers_prices[$method_name]) ) {
                continue;
            }
            foreach ( $_carriers_prices[$method_name] as $carrier_price ) {
                if ( empty($carrier_price) || ! isset($carrier_price['id_zone']) ) {
                    continue;
                }
                if ( ! in_array($carrier_price['id_zone'], $_carriers_zones) ) {
                    $_carriers_zones[] = $carrier_price['id_zone'];
                }
            }
        }

        // Get Carriers zones countries
        $_carriers_zones_countries = $this->getShipmentsDataFromDb('zones_countries', $_carriers_zones);

        // Prepare shipping prices
        $_shipping_prices = array();
        foreach ( $_carriers_zones_countries as $zone_id => $countries ) {
            foreach ( $countries as $country ) {
                $_shipping_prices[$country['iso_code']] = array();
                foreach ( $_methods_keys as $method_name => $method_keys ) {
                    $_shipping_prices[$country['iso_code']][$method_name] = array();
                    $carrier_id = 0;
                    $ranges = array();
                    $range_type = null;
                    foreach ( $_carriers_prices[$method_name] as $prices ) {
                        $range_id = (! empty($prices['id_range_price'])) ? $prices['id_range_price'] : $prices['id_range_weight'];
                        if ( empty($range_id) ) {
                            continue;
                        }
                        $carrier_id = $prices['id_carrier'];
                        if ( $prices['id_zone'] == $zone_id ) {
                            $ranges[$range_id] = $prices['price'];
                        }
                        foreach ( $_carriers_prices_ranges as $prices_range_type => $prices_ranges ) {
                            if ( isset($prices_ranges[$carrier_id]) ) {
                                $range_type = $prices_range_type;
                            }
                        }
                    }
                    $prices = array();
                    foreach ( $ranges as $range_id => $price ) {
                        foreach ( $_carriers_prices_ranges[$range_type][$carrier_id] as $range ) {
                            if ( $range['id_range_' . $range_type] == $range_id ) {
                                $minus = ($range_type == 'price') ? 0.01 : 0.001;
                                $prices[] = array(
                                    'from' => (float) $range['delimiter1'],
                                    'to' => (float) $range['delimiter2'] - $minus,
                                    'price' => (float) $price
                                );
                            }
                        }
                    }
                    $_shipping_prices[$country['iso_code']][$method_name] = array(
                        'carrier_id' => $carrier_id,
                        'type' => $range_type,
                        'enabled' => (bool) $_carriers[$method_name]['active'],
                        'prices' => $prices
                    );
                }
            }
        }

        // Add tracking date to orders
        OmnivaHelper::printToLog('Marking orders as sended...', 'powerbi');
        foreach ( $_methods_keys as $method_name => $method_keys ) {
            foreach ( $_orders[$method_name] as $order ) {
                $omnivaOrder = new OmnivaOrder($order['id_order']);
                $omnivaOrder->date_track = date('Y-m-d H:i:s');
                $omnivaOrder->update();
                OmnivaHelper::printToLog(print_r(get_object_vars($omnivaOrder), true), 'powerbi');
            }
        }

        return array(
            'platform_version' => _PS_VERSION_,
            'module_version' => $this->version,
            'client_api_user' => Configuration::get('omnivalt_api_user'),
            'client_name' => Configuration::get('omnivalt_company'),
            'client_country' => Configuration::get('omnivalt_countrycode'),
            'total_orders' => $total_orders,
            'track_since' => $oldest_order,
            'total_shipments' => $total_shipments,
            'shipments_income' => $shipments_income,
            'shipping_prices' => $_shipping_prices,
        );
    }

    private function getShipmentsDataFromDb( $data_type, $additional_data = null )
    {
        $methods_keys = array(
            'terminal' => array('omnivalt_pt'),
            'courier' => array('omnivalt_c'),
        );

        if ( $data_type == 'orders' ) {
            $orders = array();
            foreach ( $methods_keys as $method_name => $method_keys ) {
                $sql_query = "
                    SELECT
                        a.*,
                        oc.id_order_invoice AS id_order_invoice,
                        oc.weight AS weight,
                        oc.shipping_cost_tax_excl AS shipping_cost_tax_excl,
                        oc.shipping_cost_tax_incl AS shipping_cost_tax_incl,
                        oc.tracking_number AS tracking_number,
                        oo.packs AS omniva_packs,
                        oo.cod AS omniva_cod,
                        oo.cod_amount AS omniva_cod_amount,
                        oo.weight AS omniva_weight,
                        oo.error AS omniva_error,
                        oo.tracking_numbers AS omniva_tracking_numbers,
                        oo.date_track AS omniva_date_track,
                        oo.date_add AS omniva_date_add,
                        oo.date_upd AS omniva_date_upd,
                        loh.service_code AS omnivahistory_service_code,
                        loh.tracking_numbers AS omnivahistory_tracking_numbers,
                        loh.manifest AS omnivahistory_manifest_id,
                        loh.date_add AS omnivahistory_date_add,
                        loh.date_upd AS omnivahistory_date_upd
                    FROM " . _DB_PREFIX_ . "orders a
                    LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
                    INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . implode(',', self::getCarrierIds($method_keys)) . ") AND oo.date_track IS NULL
                    INNER JOIN (
                        SELECT ooh.*
                        FROM " . _DB_PREFIX_ . "omniva_order_history ooh
                        INNER JOIN (
                            -- collecting latest data for each order
                            SELECT id_order, MAX(date_add) AS max_date_add
                            FROM " . _DB_PREFIX_ . "omniva_order_history
                            WHERE manifest IS NOT NULL 
                              AND manifest != 0 
                              AND manifest != -1
                            GROUP BY id_order
                        ) looh ON ooh.id_order = looh.id_order AND ooh.date_add = looh.max_date_add
                        WHERE ooh.manifest IS NOT NULL 
                          AND ooh.manifest != 0 
                          AND ooh.manifest != -1
                    ) loh ON loh.id_order = a.id_order
                    ORDER BY loh.manifest DESC, a.id_order DESC";
                $sql_result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql_query);
                $orders[$method_name] = (is_array($sql_result)) ? $sql_result : array();
            }
            return $orders;
        }

        if ( $data_type == 'carriers' ) {
            $carriers = array();
            foreach ( $methods_keys as $method_name => $method_keys ) {
                $carriers_ref_ids = array();
                foreach ( $method_keys as $method_key ) {
                    $carriers_ref_ids[] = OmnivaCarrier::getReference($method_key);
                }
                $sql_query = "SELECT id_carrier AS id, active FROM " . _DB_PREFIX_ . "carrier WHERE id_reference IN(" . implode(',', $carriers_ref_ids) . ") AND deleted = 0";
                $sql_result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql_query);
                $sql_result = (is_array($sql_result)) ? $sql_result : array();
                $carriers[$method_name] = $sql_result;
            }
            return $carriers;
        }

        if ( $data_type == 'prices' ) {
            $prices = array();
            foreach ( $methods_keys as $method_name => $method_keys ) {
                if ( empty($additional_data[$method_name]) || ! isset($additional_data[$method_name]['id']) ) {
                    continue;
                }
                $sql_query = "SELECT * FROM " . _DB_PREFIX_ . "delivery WHERE id_carrier = " . $additional_data[$method_name]['id'];
                $sql_result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql_query);
                $prices[$method_name] = (is_array($sql_result)) ? $sql_result : array();
            }
            return $prices;
        }

        if ( $data_type == 'prices_ranges' ) {
            $prices_ranges = array();
            foreach ( $additional_data as $carrier ) {
                if ( empty($carrier) || ! isset($carrier['id']) ) {
                    continue;
                }
                $sql_query = "SELECT * FROM " . _DB_PREFIX_ . "range_price WHERE id_carrier = " . $carrier['id'];
                $sql_result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql_query);
                if ( ! empty($sql_result) ) {
                    $prices_ranges['price'][$carrier['id']] = $sql_result;
                }

                $sql_query = "SELECT * FROM " . _DB_PREFIX_ . "range_weight WHERE id_carrier = " . $carrier['id'];
                $sql_result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql_query);
                if ( ! empty($sql_result) ) {
                    $prices_ranges['weight'][$carrier['id']] = $sql_result;
                }
            }
            return $prices_ranges;
        }

        if ( $data_type == 'zones_countries' ) {
            $zones_countries = array();
            foreach ( $additional_data as $zone_id ) {
                $sql_query = "SELECT id_country, iso_code FROM " . _DB_PREFIX_ . "country WHERE id_zone = " . $zone_id . " AND active != 0";
                $sql_result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql_query);
                $zones_countries[$zone_id] = (is_array($sql_result)) ? $sql_result : array();
            }
            return $zones_countries;
        }

        return array();
    }
}
