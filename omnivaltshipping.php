<?php
// test
if (!defined('_PS_VERSION_'))
    exit;

require_once __DIR__ . "/classes/OmnivaDb.php";
require_once __DIR__ . "/classes/OmnivaCartTerminal.php";
require_once __DIR__ . "/classes/OmnivaOrder.php";
require_once __DIR__ . "/classes/OmnivaOrderHistory.php";
require_once __DIR__ . "/classes/Omniva18PlusProduct.php";

require_once __DIR__ . "/classes/OmnivaHelper.php";
require_once __DIR__ . "/classes/OmnivaApi.php";

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
        'displayAdminOrderContentShip',
        'displayBeforeCarrier',
        'displayAdminProductsExtra',
        'actionProductUpdate',
        'header',
        'orderDetailDisplayed',
        'displayAdminOrder',
        'displayBackOfficeHeader',
        'actionValidateOrder',
        'actionAdminControllerSetMedia',
        'actionObjectOrderUpdateAfter'
    );

    private static $_carriers = array(
        //"Public carrier name" => "technical name",
        'Parcel terminal' => 'omnivalt_pt',
        'Courier' => 'omnivalt_c',
    );

    /**
     * COD modules
     */
    public static $_codModules = array('ps_cashondelivery', 'venipakcod');

    public $id_carrier;

    static $_omniva_cache = [];

    public function __construct()
    {
        $this->name = 'omnivaltshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.17';
        $this->author = 'Mijora';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Omniva Shipping');
        $this->description = $this->l('Shipping module for Omniva carrier');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->helper = new OmnivaHelper();
        $this->api = new OmnivaApi(Configuration::get('omnivalt_api_user'), Configuration::get('omnivalt_api_pass'));
        if (!Configuration::get('omnivalt_api_url'))
            $this->warning = $this->l('Please set up module');
        if (!Configuration::get('omnivalt_locations_update') || (Configuration::get('omnivalt_locations_update') + 24 * 3600) < time() || !file_exists(dirname(__file__) . "/locations.json")) {
            if ($this->helper->updateTerminals()) {
                Configuration::updateValue('omnivalt_locations_update', time());
            }
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

    public function hookDisplayAdminProductsExtra($params)
    {
        $id_product = Tools::getValue('id_product');

        if ($this->isPs17()) {
            $id_product = (int) $params['id_product'];
        }

        $is18Plus = Omniva18PlusProduct::get18PlusStatus($id_product, true);

        $this->context->smarty->assign([
            'is18Plus' => $is18Plus
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

        $result = Omniva18PlusProduct::get18PlusStatus($productID);

        if (!$result) {
            DB::getInstance()->insert(
                Omniva18PlusProduct::$definition['table'],
                [
                    'id_product' => $productID,
                    'is_18_plus' => (int) $is18Plus
                ]
            );

            return;
        }

        DB::getInstance()->update(
            Omniva18PlusProduct::$definition['table'],
            [
                'is_18_plus' => (int) $is18Plus
            ]
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

            if (!$this->createCarriers()) {
                return false;
            }

            //install of custom state
            $this->getCustomOrderState();
            $this->getErrorOrderState();
            return true;
        }

        return false;
    }

    protected function createCarriers()
    {
        foreach (self::$_carriers as $key => $value) {
            //Create new carrier
            $carrier = new Carrier();
            $carrier->name = $key;
            $carrier->active = true;
            $carrier->deleted = 0;
            $carrier->shipping_handling = true;
            $carrier->range_behavior = 0;
            $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = '1-2 business days';
            $carrier->shipping_external = true;
            $carrier->is_module = true;
            $carrier->external_module_name = $this->name;
            $carrier->need_range = true;
            $carrier->url = "https://www.omniva.lt/verslo/siuntos_sekimas?barcode=@";

            if ($carrier->add()) {
                $groups = Group::getGroups(true);
                foreach ($groups as $group) {
                    Db::getInstance()->insert('carrier_group', array(
                        'id_carrier' => (int)$carrier->id,
                        'id_group' => (int)$group['id_group']
                    ));
                }

                $rangePrice = new RangePrice();
                $rangePrice->id_carrier = $carrier->id;
                $rangePrice->delimiter1 = '0';
                $rangePrice->delimiter2 = '1000';
                $rangePrice->add();

                $rangeWeight = new RangeWeight();
                $rangeWeight->id_carrier = $carrier->id;
                $rangeWeight->delimiter1 = '0';
                $rangeWeight->delimiter2 = '1000';
                $rangeWeight->add();

                $zones = Zone::getZones(true);
                foreach ($zones as $z) {
                    Db::getInstance()->insert(
                        'carrier_zone',
                        array('id_carrier' => (int)$carrier->id, 'id_zone' => (int)$z['id_zone'])
                    );
                    Db::getInstance()->insert(
                        'delivery',
                        array('id_carrier' => $carrier->id, 'id_range_price' => (int)$rangePrice->id, 'id_range_weight' => NULL, 'id_zone' => (int)$z['id_zone'], 'price' => '0'),
                        true
                    );
                    Db::getInstance()->insert(
                        'delivery',
                        array('id_carrier' => $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int)$rangeWeight->id, 'id_zone' => (int)$z['id_zone'], 'price' => '0'),
                        true
                    );
                }

                copy(dirname(__FILE__) . '/views/img/omnivalt-logo.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int)$carrier->id . '.jpg'); //assign carrier logo

                Configuration::updateValue($value, $carrier->id);
                Configuration::updateValue($value . '_reference', $carrier->id);
            }
        }

        return true;
    }

    protected function deleteCarriers()
    {
        foreach (self::$_carriers as $value) {
            $tmp_carrier_id = Configuration::get($value);
            $carrier = new Carrier($tmp_carrier_id);
            $carrier->delete();
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

            $cDb = new OmnivaDb();
            $cDb->deleteTables();
            $this->deleteTabs();

            if (!$this->deleteCarriers()) {
                return false;
            }

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
        $omniva_ref = (int) Configuration::get('omnivalt_pt_reference');

        if (isset($this->context->cart->id_address_delivery) && (int) $carrier->id_reference === $omniva_ref) {
            $address = new Address($this->context->cart->id_address_delivery);
            $iso_code = $address->id_country ? Country::getIsoById($address->id_country) : $this->context->language->iso_code;
            $iso_code = strtoupper($iso_code);
            $contract_origin = Configuration::get('omnivalt_api_country');
            if ($contract_origin !== 'ee' && $iso_code === 'FI') {
                return false;
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

        foreach (self::$_carriers as $value) {
            if ($id_carrier_old == (int)(Configuration::get($value)))
                Configuration::updateValue($value, $id_carrier_new);
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

        if (Tools::isSubmit('submit' . $this->name)) {
            $fields = array(
                'omnivalt_map', 'send_delivery_email', 'omnivalt_api_url', 'omnivalt_api_user', 'omnivalt_api_pass',
                'omnivalt_api_country', 'omnivalt_ee_service', 'omnivalt_fi_service', 'omnivalt_send_off',
                'omnivalt_bank_account', 'omnivalt_company', 'omnivalt_address', 'omnivalt_city',
                'omnivalt_postcode', 'omnivalt_countrycode', 'omnivalt_phone', 'omnivalt_pick_up_time_start',
                'omnivalt_pick_up_time_finish', 'omnivalt_send_return', 'omnivalt_print_type', 'omnivalt_manifest_lang',
                'omnivalt_label_comment_type'
            );
            $not_required = array('omnivalt_bank_account');
            $values = array();
            $all_filled = true;
            foreach ($fields as $field) {
                $values[$field] = strval(Tools::getValue($field));
                if ($values[$field] == '' && !in_array($field, $not_required)) {
                    $all_filled = false;
                }
            }

            if (!$all_filled)
                $output .= $this->displayError($this->l('All fields required'));
            else {
                foreach ($values as $key => $val)
                    Configuration::updateValue($key, $val);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
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
        $options = array(
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
        $send_return_options = array(
            array(
                'id_option' => 'all',
                'name' => $this->l('Add to SMS and email')
            ),
            array(
                'id_option' => 'sms',
                'name' => $this->l('Add to SMS')
            ),
            array(
                'id_option' => 'email',
                'name' => $this->l('Add to email')
            ),
            array(
                'id_option' => 'dont',
                'name' => $this->l('Do not send')
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

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Api URL'),
                    'name' => 'omnivalt_api_url',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Api login user'),
                    'name' => 'omnivalt_api_user',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Api login password'),
                    'name' => 'omnivalt_api_pass',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $this->l('Api login country'),
                    'name' => 'omnivalt_api_country',
                    'desc' => $this->l('Select the Omniva department country, from which you got the logins.'),
                    'required' => true,
                    'options' => array(
                        'query' => array(
                            array(
                                'id_option' => 'lt',
                                'name' => $this->l('Lithuania / Latvia')
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
                    'label' => $this->l('Estonia Carrier Service'),
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
                    'label' => $this->l('Finland Carrier Service'),
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
                    'type' => 'text',
                    'label' => $this->l('Company name'),
                    'name' => 'omnivalt_company',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Bank account'),
                    'name' => 'omnivalt_bank_account',
                    'size' => 20,
                    'required' => false
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Company address'),
                    'name' => 'omnivalt_address',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Company city'),
                    'name' => 'omnivalt_city',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Company postcode'),
                    'name' => 'omnivalt_postcode',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Company country code'),
                    'name' => 'omnivalt_countrycode',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Company phone number'),
                    'name' => 'omnivalt_phone',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Pick up time start'),
                    'name' => 'omnivalt_pick_up_time_start',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Pick up time finish'),
                    'name' => 'omnivalt_pick_up_time_finish',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $this->l('Send off type'),
                    'name' => 'omnivalt_send_off',
                    'desc' => $this->l('Please select send off from store type'),
                    'required' => true,
                    'options' => array(
                        'query' => $options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Display map'),
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
                    'label' => $this->l('Send delivery email'),
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
                    'type' => 'select',
                    'lang' => true,
                    'label' => $this->l('Send return code'),
                    'name' => 'omnivalt_send_return',
                    'desc' => $this->l('Choose how to send the return code to the customer'),
                    'required' => false,
                    'options' => array(
                        'query' => $send_return_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $this->l('Labels print type'),
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
                    'label' => $this->l('Label comment'),
                    'name' => 'omnivalt_label_comment_type',
                    'required' => false,
                    'options' => array(
                        'query' => $label_comment_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
                array(
                    'type' => 'select',
                    'lang' => true,
                    'label' => $this->l('Manifest language'),
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
                'class' => 'btn btn-default pull-right'
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
            ]
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
        $helper->fields_value['omnivalt_map'] = Configuration::get('omnivalt_map');
        $helper->fields_value['send_delivery_email'] = Configuration::get('send_delivery_email');
        $helper->fields_value['omnivalt_send_return'] = Configuration::get('omnivalt_send_return') ? Configuration::get('omnivalt_send_return') : 'all';
        $helper->fields_value['omnivalt_print_type'] = Configuration::get('omnivalt_print_type') ? Configuration::get('omnivalt_print_type') : 'four';
        $helper->fields_value['omnivalt_label_comment_type'] = Configuration::get('omnivalt_label_comment_type') ? Configuration::get('omnivalt_label_comment_type') : OmnivaApi::LABEL_COMMENT_TYPE_NONE;
        $helper->fields_value['omnivalt_manifest_lang'] = Configuration::get('omnivalt_manifest_lang') ? Configuration::get('omnivalt_manifest_lang') : 'en';

        return $helper->generateForm($fields_form);
    }

    private function getTerminalsOptions($selected = '', $country = "")
    {
        if (!$country) {
            $country = Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        }

        $contract_origin = Configuration::get('omnivalt_api_country');
        $origin_allows = true;
        if ($contract_origin !== 'ee' && $country === 'FI') {
            $origin_allows = false;
        }

        $terminals = file_get_contents(__DIR__ . "/locations.json");
        $terminals = json_decode($terminals, true);
        $parcel_terminals = '';
        if ($origin_allows && is_array($terminals)) {
            $grouped_options = array();
            foreach ($terminals as $terminal) {
                # closed ? exists on EE only
                if (intval($terminal['TYPE'])) {
                    continue;
                }
                if ($terminal['A0_NAME'] != $country)
                    continue;
                if (!isset($grouped_options[$terminal['A1_NAME']]))
                    $grouped_options[(string)$terminal['A1_NAME']] = array();
                $address = trim($terminal['A2_NAME'] . ' ' . ($terminal['A5_NAME'] != 'NULL' ? $terminal['A5_NAME'] : '') . ' ' . ($terminal['A7_NAME'] != 'NULL' ? $terminal['A7_NAME'] : ''));
                $grouped_options[(string)$terminal['A1_NAME']][(string)$terminal['ZIP']] = $terminal['NAME'] . ' (' . $address . ')';
            }
            ksort($grouped_options);
            $this->context->smarty->assign([
                'grouped_options' => $grouped_options,
                'selected' => $selected,
            ]);
        }
        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . $this->name .'/views/templates/front/omniva-terminals.tpl');
    }

    /**
     * Generate terminal list with coordinates info
     */
    private function getTerminalForMap($selected = '', $country = "LT")
    {
        if (!$country) {
            $country = Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        }
     
        $contract_origin = Configuration::get('omnivalt_api_country');
        if ($contract_origin !== 'ee' && $country === 'FI') {
            return [];
        }

        $terminals = file_get_contents(__DIR__ . "/locations.json");
        $terminals = json_decode($terminals, true);
        $terminalsList = array();
        if (is_array($terminals)) {
            foreach ($terminals as $terminal) {
                if ($terminal['A0_NAME'] != $country || intval($terminal['TYPE']) == 1)
                    continue;
                if (!isset($grouped_options[$terminal['A1_NAME']]))
                    $grouped_options[(string)$terminal['A1_NAME']] = array();
                $grouped_options[(string)$terminal['A1_NAME']][(string)$terminal['ZIP']] = $terminal['NAME'];

                $terminalsList[] = [$terminal['NAME'], $terminal['Y_COORDINATE'], $terminal['X_COORDINATE'], $terminal['ZIP'], $terminal['A1_NAME'], $terminal['A2_NAME'], $terminal['comment_lit']];
            }
        }
        return $terminalsList;
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
        foreach ( self::$_carriers as $key => $value ) {
            $tmp_carrier_id = Configuration::get($value);
            $carrier = new Carrier($tmp_carrier_id);
            if ( ! empty($carrier->id) ) {
                $carriers .= '<option value = "' . Configuration::get($value) . '" ' . (Configuration::get($value) == $selected ? 'selected' : '') . '>' . $this->l($key) . '</option>';
            }
        }
        return $carriers;
    }

    public function hookDisplayBeforeCarrier($params)
    {
        $selected = '';
        if (isset($params['cart']->id)) {
            $omnivaCart = new OmnivaCartTerminal($params['cart']->id);
            $selected = $omnivaCart->id_terminal;
        }
        $address = new Address($params['cart']->id_address_delivery);
        $iso_code = $this->getCartCountryCode($params['cart']);

        $showMap = Configuration::get('omnivalt_map');
        $this->context->smarty->assign(array(
            'omnivalt_parcel_terminal_carrier_id' => Configuration::get('omnivalt_pt'),
            'parcel_terminals' => $this->getTerminalsOptions($selected, $iso_code),
            'terminals_list' => $this->getTerminalForMap($selected, $iso_code),
            'omniva_current_country' => $iso_code,
            'omniva_postcode' => $address->postcode ?: '',
            'omniva_map' => $showMap,
            'module_url' => $this->_path,
            'ps_version' => $this->getPsVersion(),
        ));
        return $this->display(__file__, 'displayBeforeCarrier.tpl');
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
                    'omnivalt_terminal_carrier' => Configuration::get('omnivalt_pt'),
                    'omnivalt_text' => array(
                        'ajax_parsererror' => $this->l("An invalid response was received"),
                        'ajax_unknownerror' => $this->l("Unknown error"),
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
                'omniva_img_url' => Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/views/img/',
                'omnivalt_parcel_terminal_carrier_id' => Configuration::get('omnivalt_pt'),
                'omnivaltdelivery_controller' => $this->context->link->getModuleLink('omnivaltshipping', 'ajax'),
                'omnivadata' => [
                    'text_select_terminal' => $this->l('Select terminal'),
                    'text_search_placeholder' => $this->l('Enter postcode'),
                    'not_found' => $this->l('Place not found'),
                    'text_enter_address' => $this->l('Enter postcode/address'),
                    'text_show_in_map' => $this->l('Show in map'),
                    'text_show_more' => $this->l('Show more'),
                    'omniva_plugin_url' => Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/',
                    'omnivalt_parcel_terminal_error' => $this->l('Please select parcel terminal'),
                    'select_terminal' => $this->l('Please select a parcel terminal'),
                    'omnivaSearch' => $this->l('Enter an address, if you want to find terminals'),

                ],
                'omnivalt_ps_version' => [
                    'is_16' => $this->isPs16(),
                    'is_17' => $this->isPs17(),
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

            return $this->display(__FILE__, 'header.tpl');
        }
    }

    public static function getCarrierIds($carriers = [])
    {
        // use only supplied or all
        $carriers = count($carriers) > 0 ? $carriers : self::$_carriers;
        $ref = [];
        foreach ($carriers as $value) {
            $ref[] = Configuration::get($value . '_reference');
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

    public static function getReferenceNumber($order_number)
    {
        $order_number = str_pad((string)$order_number, 2, '0', STR_PAD_LEFT);
        $kaal = array(7, 3, 1);
        $sl = $st = strlen($order_number);
        $total = 0;
        while ($sl > 0 and substr($order_number, --$sl, 1) >= '0') {
            $total += substr($order_number, ($st - 1) - $sl, 1) * $kaal[($sl % 3)];
        }
        $kontrollnr = ((ceil(($total / 10)) * 10) - $total);
        return $order_number . $kontrollnr;
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

        if ( self::isOmnivaCarrier($order->id_carrier, $carrier->id_reference) ) {
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

            $this->smarty->assign(array(
                'total_weight' => $omnivaOrder->weight,
                'packs' => $omnivaOrder->packs,
                'total_paid_tax_incl' => $omnivaOrder->cod_amount,
                'is_cod' => $omnivaOrder->cod,
                'parcel_terminals' => $this->getTerminalsOptions($id_terminal, $countryCode),
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

    public static function isOmnivaCarrier($carrier_id = false, $carrier_ref_id = false)
    {
        foreach ( self::$_carriers as $key => $value ) {
            if ( $carrier_id && $carrier_id == Configuration::get($value) ) {
                return true;
            }
            if ( $carrier_ref_id && $carrier_ref_id == Configuration::get($value . '_reference') ) {
                return true;
            }
        }

        return false;
    }

    public function hookOrderDetailDisplayed($params)
    {
        $order = $params['order'];
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
}
