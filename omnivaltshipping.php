<?php

if (!defined('_PS_VERSION_'))
    exit;

require_once __DIR__ . "/classes/OmnivaDb.php";
require_once __DIR__ . "/classes/OmnivaCartTerminal.php";
require_once __DIR__ . "/classes/OmnivaOrder.php";

require_once __DIR__ . "/classes/OmnivaPatcher.php";
require_once __DIR__ . "/classes/OmnivaHelper.php";
require_once __DIR__ . "/classes/OmnivaApi.php";

require_once __DIR__ . '/vendor/autoload.php';

class OmnivaltShipping extends CarrierModule
{
    public $helper;

    public $api;

    const CONTROLLER_OMNIVA_AJAX = 'AdminOmnivaAjax';
    const CONTROLLER_OMNIVA_ORDERS = 'AdminOmnivaOrders';

    protected $_hooks = array(
        'actionCarrierUpdate', //For control change of the carrier's ID (id_carrier), the module must use the updateCarrier hook.
        'displayAdminOrderContentShip',
        'displayBeforeCarrier',
        'header',
        'orderDetailDisplayed',
        'displayAdminOrder',
        'displayBackOfficeHeader',
        'actionValidateOrder',
        'actionAdminControllerSetMedia'
    );

    private static $_carriers = array(
        //"Public carrier name" => "technical name",
        'Parcel terminal' => 'omnivalt_pt',
        'Courier' => 'omnivalt_c',
    );

    public function __construct()
    {
        $this->name = 'omnivaltshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.0';
        $this->author = 'Mijora';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.8');
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

    public function getOrderShippingCost($params, $shipping_cost)
    {
        //if ($params->id_carrier == (int)(Configuration::get('omnivalt_pt')) || $params->id_carrier == (int)(Configuration::get('omnivalt_c')))
        return $shipping_cost;
        return false; // carrier is not known
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

    /*
    ** Form Config Methods
    **
    */
    public function getContent()
    {

        if (Tools::isSubmit('patch' . $this->name)) {
            $patcher = new OmnivaPatcher();
            $this->runPatcher($patcher);
        }

        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $fields = array('omnivalt_map', 'omnivalt_api_url', 'omnivalt_api_user', 'omnivalt_api_pass', 'omnivalt_send_off', 'omnivalt_bank_account', 'omnivalt_company', 'omnivalt_address', 'omnivalt_city', 'omnivalt_postcode', 'omnivalt_countrycode', 'omnivalt_phone', 'omnivalt_pick_up_time_start', 'omnivalt_pick_up_time_finish', 'omnivalt_print_type', 'omnivalt_manifest_lang');
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

    public function cod_options()
    {
        return array(
            array('id_option' => '0', 'name' => $this->l('No')),
            array('id_option' => '1', 'name' => $this->l('Yes')),
        );
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
                    'label' => $this->l('Manifest language'),
                    'name' => 'omnivalt_manifest_lang',
                    'required' => false,
                    'options' => array(
                        'query' => $lang_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $patcher = new OmnivaPatcher();

        $installed_patches = $patcher->getInstalledPatches();
        $latest_patch = 'OmnivaPatcher Installed';
        if ($installed_patches) {
            $latest_patch = $installed_patches[count($installed_patches) - 1];
        }

        $patch_link = AdminController::$currentIndex . '&configure=' . $this->name . '&patch' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $fields_form[0]['form']['input'][] = array(
            'type' => 'html',
            'label' => 'Patch:',
            'name' => 'patcher_info',
            'html_content' => '<label class="control-label"><b>' . $latest_patch . '</b></label><br><a class="btn btn-default" href="' . $patch_link . '">Check & Install Patches</a>',
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
        $helper->fields_value['omnivalt_print_type'] = Configuration::get('omnivalt_print_type') ? Configuration::get('omnivalt_print_type') : 'four';
        $helper->fields_value['omnivalt_manifest_lang'] = Configuration::get('omnivalt_manifest_lang') ? Configuration::get('omnivalt_manifest_lang') : 'en';
        return $helper->generateForm($fields_form);
    }

    private function runPatcher(OmnivaPatcher $patcherInstance)
    {
        $patcherInstance->startUpdate(Configuration::get('omnivalt_api_user'), Configuration::get('PS_SHOP_EMAIL'));
        Configuration::updateValue('omnivalt_patcher_update', time());
    }

    private function getTerminalsOptions($selected = '', $country = "")
    {
        if (!$country) {
            $shop_country = new Country();
            $country = $shop_country->getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        }

        $terminals_json_file_dir = dirname(__file__) . "/locations.json";
        $terminals_file = fopen($terminals_json_file_dir, "r");
        $terminals = fread($terminals_file, filesize($terminals_json_file_dir) + 10);
        fclose($terminals_file);
        $terminals = json_decode($terminals, true);
        $parcel_terminals = '';
        if (is_array($terminals)) {
            $grouped_options = array();
            foreach ($terminals as $terminal) {
                # closed ? exists on EE only
                if (intval($terminal['TYPE'])) {
                    continue;
                }
                if ($terminal['A0_NAME'] != $country && in_array($country, array("LT", "EE", "LV")))
                    continue;
                if (!isset($grouped_options[$terminal['A1_NAME']]))
                    $grouped_options[(string)$terminal['A1_NAME']] = array();
                //$grouped_options[(string)$terminal['A1_NAME']][(string)$terminal['ZIP']] = $terminal['NAME'];
                $address = trim($terminal['A2_NAME'] . ' ' . ($terminal['A5_NAME'] != 'NULL' ? $terminal['A5_NAME'] : '') . ' ' . ($terminal['A7_NAME'] != 'NULL' ? $terminal['A7_NAME'] : ''));
                $grouped_options[(string)$terminal['A1_NAME']][(string)$terminal['ZIP']] = $terminal['NAME'] . ' (' . $address . ')';
            }
            ksort($grouped_options);
            foreach ($grouped_options as $city => $locs) {
                $parcel_terminals .= '<optgroup label = "' . $city . '">';
                foreach ($locs as $key => $loc) {
                    $parcel_terminals .= '<option value = "' . $key . '" ' . ($key == $selected ? 'selected' : '') . '  class="omnivaOption">' . $loc . '</option>';
                }
                $parcel_terminals .= '</optgroup>';
            }
        }
        $parcel_terminals = '<option value = "">' . $this->l('Select parcel terminal') . '</option>' . $parcel_terminals;
        return $parcel_terminals;
    }

    /**
     * Generate terminal list with coordinates info
     */
    private function getTerminalForMap($selected = '', $country = "LT")
    {
        if (!$country) {
            $shop_country = new Country();
            $country = $shop_country->getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        }

        $terminals_json_file_dir = dirname(__file__) . "/locations.json";
        $terminals_file = fopen($terminals_json_file_dir, "r");
        $terminals = fread($terminals_file, filesize($terminals_json_file_dir) + 10);
        fclose($terminals_file);
        $terminals = json_decode($terminals, true);
        if (is_array($terminals)) {
            $terminalsList = array();
            foreach ($terminals as $terminal) {
                if ($terminal['A0_NAME'] != $country && in_array($country, array("LT", "EE", "LV")) || intval($terminal['TYPE']) == 1)
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
        $terminals_json_file_dir = dirname(__file__) . "/locations.json";
        $terminals_file = fopen($terminals_json_file_dir, "r");
        $terminals = fread($terminals_file, filesize($terminals_json_file_dir) + 10);
        fclose($terminals_file);
        $terminals = json_decode($terminals, true);
        $parcel_terminals = '';
        if (is_array($terminals)) {
            $grouped_options = array();
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
        //$carriers .= '<option value = "">'.$this->l('Select carrier').'</option>';
        foreach (self::$_carriers as $key => $value) {
            $tmp_carrier_id = Configuration::get($value);
            $carrier = new Carrier($tmp_carrier_id);
            if ($carrier->active || 1 == 1) {
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
        $iso_code = $address->id_country ? Country::getIsoById($address->id_country) : $this->context->language->iso_code;

        $showMap = Configuration::get('omnivalt_map');
        $this->context->smarty->assign(array(
            'omnivalt_parcel_terminal_carrier_id' => Configuration::get('omnivalt_pt'),
            'parcel_terminals' => $this->getTerminalsOptions($selected, $iso_code),
            'terminals_list' => $this->getTerminalForMap($selected, $iso_code),
            'omniva_current_country' => $iso_code,
            'omniva_postcode' => $address->postcode ?: '',
            'omniva_map' => $showMap,
            'module_url' => $this->_path,
        ));
        return $this->display(__file__, 'displayBeforeCarrier.tpl');
    }

    public function hookDisplayBackOfficeHeader($params)
    {
        Media::addJsDef([
            'omnivalt_bulk_labels' => $this->l("Print Omnivalt labels"),
            'omnivalt_bulk_manifests' => $this->l("Print Omnivalt manifests"),
            'omnivalt_admin_action_labels' => $this->context->link->getAdminLink("AdminOmnivaOrders", true, [], array("action" => "bulklabels")),
            'omnivalt_admin_action_manifests' => $this->context->link->getAdminLink("AdminOmnivaOrders", true, [], array("action" => "bulkmanifests")),
        ]);
        $this->context->controller->addJS($this->_path . '/views/js/adminOmnivalt.js');
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
                ]
            ]);

            $this->context->controller->registerJavascript(
                'leaflet',
                'modules/' . $this->name . '/views/js/leaflet.js',
                ['priority' => 190]
            );

            $this->context->controller->registerStylesheet(
                'leaflet-style',
                'modules/' . $this->name . '/views/css/leaflet.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                ]
            );
            $this->context->controller->registerStylesheet(
                'omniva-modulename-style',
                'modules/' . $this->name . '/views/css/omniva.css',
                [
                    'media' => 'all',
                    'priority' => 200,
                ]
            );

            $this->context->controller->registerJavascript(
                'omnivalt',
                'modules/' . $this->name . '/views/js/omniva.js',
                [
                    'priority' => 200,
                ]
            );

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
        $order_number = (string)$order_number;
        $kaal = array(7, 3, 1);
        $sl = $st = strlen($order_number);
        $total = 0;
        while ($sl > 0 and substr($order_number, --$sl, 1) >= '0') {
            $total += substr($order_number, ($st - 1) - $sl, 1) * $kaal[($sl % 3)];
        }
        $kontrollnr = ((ceil(($total / 10)) * 10) - $total);
        return $order_number . $kontrollnr;
    }

    public function changeOrderStatus($id_order, $status)
    {
        $order = new Order((int)$id_order);
        if ($order->current_state != $status) // && $order->current_state != Configuration::get('PS_OS_SHIPPING'))
        {
            $history = new OrderHistory();
            $history->id_order = (int)$id_order;
            $history->id_employee = (int)$this->context->employee->id;
            $history->changeIdOrderState((int)$status, $order);
            $history->add();
        }
    }

    public function hookActionAdminControllerSetMedia($params)
    {
        if (get_class($this->context->controller) == 'AdminOrdersController' || get_class($this->context->controller) == 'AdminLegacyLayoutControllerCore') {
            {
                Media::addJsDef([
                    'printLabelsUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX, true, [], array('action' => 'generateLabels')),
                    'success_add_trans' => $this->l('Successfully added.'),
                    'moduleUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX, true, [], array('action' => 'saveorderinfo')),
                    'omnivalt_terminal_carrier' => Configuration::get('omnivalt_pt'),
                ]);
                if (version_compare(_PS_VERSION_, '1.7.7', '>='))
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

    public function hookDisplayAdminOrder($id_order)
    {
        $order = new Order((int)$id_order['id_order']);
        $cart = new OmnivaCartTerminal($order->id_cart);

        if ($order->id_carrier == Configuration::get('omnivalt_pt') || $order->id_carrier == Configuration::get('omnivalt_c')) {
            $id_terminal = $cart->id_terminal;

            $address = new Address($order->id_address_delivery);
            $countryCode = Country::getIsoById($address->id_country);

            $omnivaOrder = new OmnivaOrder($order->id);
            $printLabelsUrl = $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX, true, [], ["action" => "printLabels", "id_order" => $order->id]);

            $error_msg = $omnivaOrder->error ?: false;
            $omniva_tpl = 'blockinorder.tpl';

            if (version_compare(_PS_VERSION_, '1.7.7', '>=')) {
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
                'moduleurl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX, true, [], array('action' => 'saveorderinfo')),
                'generateLabelsUrl' => $this->context->link->getAdminLink(self::CONTROLLER_OMNIVA_AJAX, true, [], array('action' => 'generateLabels')),
                'printLabelsUrl' => $printLabelsUrl,
                'error' => $error_msg,
            ));

            return $this->display(__FILE__, $omniva_tpl);
        }
    }

    public static function call_omniva()
    {
        $service = "QH";
        $additionalService = '';

        if ($additionalService) {
            $additionalService = '<add_service>' . $additionalService . '</add_service>';
        }
        $phones = '';
        $phones .= '<mobile>' . Configuration::get('omnivalt_phone') . '</mobile>';
        $pickStart = Configuration::get('omnivalt_pick_up_time_start') ? Configuration::get('omnivalt_pick_up_time_start') : '8:00';
        $pickFinish = Configuration::get('omnivalt_pick_up_time_finish') ? Configuration::get('omnivalt_pick_up_time_finish') : '17:00';
        $pickDay = date('Y-m-d');
        if (time() > strtotime($pickDay . ' ' . $pickFinish))
            $pickDay = date('Y-m-d', strtotime($pickDay . "+1 days"));

        $shop_country = new Country();
        $shop_country_iso = $shop_country->getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'));
        $xmlRequest = '
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://service.core.epmx.application.eestipost.ee/xsd">
           <soapenv:Header/>
           <soapenv:Body>
              <xsd:businessToClientMsgRequest>
                 <partner>' . Configuration::get('omnivalt_api_user') . '</partner>
                 <interchange msg_type="info11">
                    <header file_id="' . \Date('YmdHms') . '" sender_cd="' . Configuration::get('omnivalt_api_user') . '" >                
                    </header>
                    <item_list>
                      ';
        for ($i = 0; $i < 1; $i++) :
            $xmlRequest .= '
                       <item service="' . $service . '" >
                          ' . $additionalService . '
                          <measures weight="1" />
                          <receiverAddressee >
                             <person_name>' . Configuration::get('omnivalt_company') . '</person_name>
                            ' . $phones . '
                             <address postcode="' . Configuration::get('omnivalt_postcode') . '" deliverypoint="' . Configuration::get('omnivalt_city') . '" country="' . Configuration::get('omnivalt_countrycode') . '" street="' . Configuration::get('omnivalt_address') . '" />
                          </receiverAddressee>
                          <!--Optional:-->
                          <returnAddressee>
                             <person_name>' . Configuration::get('omnivalt_company') . '</person_name>
                             <!--Optional:-->
                             <phone>' . Configuration::get('omnivalt_phone') . '</phone>
                             <address postcode="' . Configuration::get('omnivalt_postcode') . '" deliverypoint="' . Configuration::get('omnivalt_city') . '" country="' . Configuration::get('omnivalt_countrycode') . '" street="' . Configuration::get('omnivalt_address') . '" />
                          
                          </returnAddressee>';
            $xmlRequest .= '
                          <onloadAddressee>
                             <person_name>' . Configuration::get('omnivalt_company') . '</person_name>
                             <!--Optional:-->
                             <phone>' . Configuration::get('omnivalt_phone') . '</phone>
                             <address postcode="' . Configuration::get('omnivalt_postcode') . '" deliverypoint="' . Configuration::get('omnivalt_city') . '" country="' . Configuration::get('omnivalt_countrycode') . '" street="' . Configuration::get('omnivalt_address') . '" />
                            <pick_up_time start="' . date("c", strtotime($pickDay . ' ' . $pickStart)) . '" finish="' . date("c", strtotime($pickDay . ' ' . $pickFinish)) . '"/>
                          </onloadAddressee>';
            $xmlRequest .= '</item>';
        endfor;
        $xmlRequest .= '
                    </item_list>
                 </interchange>
              </xsd:businessToClientMsgRequest>
           </soapenv:Body>
        </soapenv:Envelope>';
        return self::api_request($xmlRequest);
    }

    public static function api_request($request)
    {
        $barcodes = array();;
        $errors = array();
        $url = Configuration::get('omnivalt_api_url') . "/epmx/services/messagesService.wsdl";

        $headers = array(
            "Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Content-length: " . strlen($request),
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERPWD, Configuration::get('omnivalt_api_user') . ":" . Configuration::get('omnivalt_api_pass'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $xmlResponse = curl_exec($ch);
        if ($xmlResponse === false) {
            $errors[] = curl_error($ch);
        } else {
            $errorTitle = '';
            if (strlen(trim($xmlResponse)) > 0) {
                //echo $xmlResponse; exit;
                $xmlResponse = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $xmlResponse);
                $xml = simplexml_load_string($xmlResponse);
                if (!is_object($xml)) {
                    $errors[] = $this->l('Response is in the wrong format');
                }
                if (is_object($xml) && is_object($xml->Body->businessToClientMsgResponse->faultyPacketInfo->barcodeInfo)) {
                    foreach ($xml->Body->businessToClientMsgResponse->faultyPacketInfo->barcodeInfo as $data) {
                        $errors[] = $data->clientItemId . ' - ' . $data->barcode . ' - ' . $data->message;
                    }
                }
                if (empty($errors)) {
                    if (is_object($xml) && is_object($xml->Body->businessToClientMsgResponse->savedPacketInfo->barcodeInfo)) {
                        foreach ($xml->Body->businessToClientMsgResponse->savedPacketInfo->barcodeInfo as $data) {
                            $barcodes[] = (string)$data->barcode;
                        }
                    }
                }
            }
        }
        // }
        if (!empty($errors)) {
            return array('status' => false, 'msg' => implode('. ', $errors));
        } else {
            if (!empty($barcodes))
                return array('status' => true, 'barcodes' => $barcodes);
            $errors[] = 'No saved barcodes received';
            return array('status' => false, 'msg' => implode('. ', $errors));
        }
    }

    public function hookOrderDetailDisplayed($params)
    {
        $carrier_ids = self::getCarrierIds();
        $order = $params['order'];
        if ($order->getWsShippingNumber() && (in_array($order->id_carrier, $carrier_ids))) {
            $address = new Address($order->id_address_delivery);
            $iso_code = Country::getIsoById($address->id_country);
            $tracking_info = $this->getTracking(array($order->getWsShippingNumber()));
            $this->context->smarty->assign(array(
                'tracking_info' => $tracking_info,
                'tracking_number' => $order->getWsShippingNumber(),
                'country_code' => $iso_code,
            ));
            $this->context->controller->registerJavascript(
                'omnivalt',
                'modules/' . $this->name . '/views/js/trackingURL.js',
                [
                    'media' => 'all',
                    'priority' => 200,
                ]
            );

            return $this->display(__file__, 'trackingInfo.tpl');
        }
        return '';
    }

    public function getTracking($tracking)
    {
        $url = str_ireplace('epmx/services/messagesService.wsdl', '', Configuration::get('omnivalt_api_url')) . 'epteavitus/events/from/' . date("c", strtotime("-1 week +1 day")) . '/for-client-code/' . Configuration::get('omnivalt_api_user');
        $process = curl_init();
        $additionalHeaders = '';
        curl_setopt($process, CURLOPT_URL, $url);
        curl_setopt($process, CURLOPT_HTTPHEADER, array('Content-Type: application/xml', $additionalHeaders));
        curl_setopt($process, CURLOPT_HEADER, false);
        curl_setopt($process, CURLOPT_USERPWD, Configuration::get('omnivalt_api_user') . ":" . Configuration::get('omnivalt_api_pass'));
        curl_setopt($process, CURLOPT_TIMEOUT, 30);
        curl_setopt($process, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($process, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($process, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        $return = curl_exec($process);
        curl_close($process);
        if ($process === false) {
            return false;
        }
        return $this->parseXmlTrackingResponse($tracking, $return);
    }

    public function parseXmlTrackingResponse($trackings, $response)
    {
        $errors = array();
        $resultArr = array();

        if (strlen(trim($response)) > 0) {
            $xml = simplexml_load_string($response);
            if (!is_object($xml)) {
                $errors[] = $this->l('Response is in the wrong format');
            }
            //$this->_debug($xml);
            if (is_object($xml) && is_object($xml->event)) {
                foreach ($xml->event as $awbinfo) {
                    $awbinfoData = [];

                    $trackNum = isset($awbinfo->packetCode) ? (string)$awbinfo->packetCode : '';

                    if (!in_array($trackNum, $trackings))
                        continue;
                    //$this->_debug($awbinfo);
                    $packageProgress = [];
                    if (isset($resultArr[$trackNum]['progressdetail']))
                        $packageProgress = $resultArr[$trackNum]['progressdetail'];

                    $shipmentEventArray = [];
                    $shipmentEventArray['activity'] = $this->getEventCode((string)$awbinfo->eventCode);

                    $shipmentEventArray['deliverydate'] = DateTime::createFromFormat('U', strtotime($awbinfo->eventDate));
                    $shipmentEventArray['deliverylocation'] = $awbinfo->eventSource;
                    $packageProgress[] = $shipmentEventArray;

                    $awbinfoData['progressdetail'] = $packageProgress;

                    $resultArr[$trackNum] = $awbinfoData;
                }
            }
        }

        if (!empty($errors)) {
            return false;
        }
        return $resultArr;
    }

    public function getEventCode($code)
    {
        $tracking = [
            'PACKET_EVENT_IPS_C' => $this->l("Shipment from country of departure"),
            'PACKET_EVENT_FROM_CONTAINER' => $this->l("Arrival to post office"),
            'PACKET_EVENT_IPS_D' => $this->l("Arrival to destination country"),
            'PACKET_EVENT_SAVED' => $this->l("Saving"),
            'PACKET_EVENT_DELIVERY_CANCELLED' => $this->l("Cancelling of delivery"),
            'PACKET_EVENT_IN_POSTOFFICE' => $this->l("Arrival to Omniva"),
            'PACKET_EVENT_IPS_E' => $this->l("Customs clearance"),
            'PACKET_EVENT_DELIVERED' => $this->l("Delivery"),
            'PACKET_EVENT_FROM_WAYBILL_LIST' => $this->l("Arrival to post office"),
            'PACKET_EVENT_IPS_A' => $this->l("Acceptance of packet from client"),
            'PACKET_EVENT_IPS_H' => $this->l("Delivery attempt"),
            'PACKET_EVENT_DELIVERING_TRY' => $this->l("Delivery attempt"),
            'PACKET_EVENT_DELIVERY_CALL' => $this->l("Preliminary calling"),
            'PACKET_EVENT_IPS_G' => $this->l("Arrival to destination post office"),
            'PACKET_EVENT_ON_ROUTE_LIST' => $this->l("Dispatching"),
            'PACKET_EVENT_IN_CONTAINER' => $this->l("Dispatching"),
            'PACKET_EVENT_PICKED_UP_WITH_SCAN' => $this->l("Acceptance of packet from client"),
            'PACKET_EVENT_RETURN' => $this->l("Returning"),
            'PACKET_EVENT_SEND_REC_SMS_NOTIF' => $this->l("SMS to receiver"),
            'PACKET_EVENT_ARRIVED_EXCESS' => $this->l("Arrival to post office"),
            'PACKET_EVENT_IPS_I' => $this->l("Delivery"),
            'PACKET_EVENT_ON_DELIVERY_LIST' => $this->l("Handover to courier"),
            'PACKET_EVENT_PICKED_UP_QUANTITATIVELY' => $this->l("Acceptance of packet from client"),
            'PACKET_EVENT_SEND_REC_EMAIL_NOTIF' => $this->l("E-MAIL to receiver"),
            'PACKET_EVENT_FROM_DELIVERY_LIST' => $this->l("Arrival to post office"),
            'PACKET_EVENT_OPENING_CONTAINER' => $this->l("Arrival to post office"),
            'PACKET_EVENT_REDIRECTION' => $this->l("Redirection"),
            'PACKET_EVENT_IN_DEST_POSTOFFICE' => $this->l("Arrival to receiver's post office"),
            'PACKET_EVENT_STORING' => $this->l("Storing"),
            'PACKET_EVENT_IPS_EDD' => $this->l("Item into sorting centre"),
            'PACKET_EVENT_IPS_EDC' => $this->l("Item returned from customs"),
            'PACKET_EVENT_IPS_EDB' => $this->l("Item presented to customs"),
            'PACKET_EVENT_IPS_EDA' => $this->l("Held at inward OE"),
            'PACKET_STATE_BEING_TRANSPORTED' => $this->l("Being transported"),
            'PACKET_STATE_CANCELLED' => $this->l("Cancelled"),
            'PACKET_STATE_CONFIRMED' => $this->l("Confirmed"),
            'PACKET_STATE_DELETED' => $this->l("Deleted"),
            'PACKET_STATE_DELIVERED' => $this->l("Delivered"),
            'PACKET_STATE_DELIVERED_POSTOFFICE' => $this->l("Arrived at post office"),
            'PACKET_STATE_HANDED_OVER_TO_COURIER' => $this->l("Transmitted to courier"),
            'PACKET_STATE_HANDED_OVER_TO_PO' => $this->l("Re-addressed to post office"),
            'PACKET_STATE_IN_CONTAINER' => $this->l("In container"),
            'PACKET_STATE_IN_WAREHOUSE' => $this->l("At warehouse"),
            'PACKET_STATE_ON_COURIER' => $this->l("At delivery"),
            'PACKET_STATE_ON_HANDOVER_LIST' => $this->l("In transition sheet"),
            'PACKET_STATE_ON_HOLD' => $this->l("Waiting"),
            'PACKET_STATE_REGISTERED' => $this->l("Registered"),
            'PACKET_STATE_SAVED' => $this->l("Saved"),
            'PACKET_STATE_SORTED' => $this->l("Sorted"),
            'PACKET_STATE_UNCONFIRMED' => $this->l("Unconfirmed"),
            'PACKET_STATE_UNCONFIRMED_NO_TARRIF' => $this->l("Unconfirmed (No tariff)"),
            'PACKET_STATE_WAITING_COURIER' => $this->l("Awaiting collection"),
            'PACKET_STATE_WAITING_TRANSPORT' => $this->l("In delivery list"),
            'PACKET_STATE_WAITING_UNARRIVED' => $this->l("Waiting, hasn't arrived"),
            'PACKET_STATE_WRITTEN_OFF' => $this->l("Written off"),
        ];
        if (isset($tracking[$code]))
            return $tracking[$code];
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
            $omnivaOrder->cod_amount = $order->total_paid_tax_incl;
            $omnivaOrder->add();
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
}
