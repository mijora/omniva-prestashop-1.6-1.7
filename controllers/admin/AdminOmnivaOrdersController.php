<?php

class AdminOmnivaOrdersController extends ModuleAdminController
{
    private $_carriers = '';
    private $_path;

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        $this->addJS($this->_path . 'views/js/omniva-orders.js');
        Media::addJsDef([
            'check_orders' => $this->module->l('Please select orders'),
            'carrier_cal_url' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&callCourier=1',
            'cancel_courier_call' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&cancelCourier=',
            'finished_trans' => $this->module->l('Finished.'),
            'message_sent_trans' => $this->module->l('Message successfully sent.'),
            'courier_call_success' => $this->module->l('Registered courier call'),
            'courier_arrival_between' => $this->module->l('The courier will arrive between'),
            'incorrect_response_trans' => $this->module->l('Incorrect response.'),
            'ajaxCall' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&ajax=1',
            'orderLink' => $this->context->link->getAdminLink('AdminOrders') . '&vieworder',
            'manifestLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . "&action=printManifest",
            'labelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . "&action=printLabels",
            'bulkLabelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . "&action=bulkPrintLabels",
            'labels_trans' => $this->module->l('Labels'),
            'not_found_trans' => $this->module->l('Nothing found', 'adminomnivaorderscontroller'),
        ]);
    }

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        if (empty($this->_path)) {
            $this->_path = __PS_BASE_URI__ . 'modules/' . $this->module->name . '/';
        }

        $this->_carriers = $this->getCarrierIds();
        if (Tools::getValue('orderSkip') != null) {
            $this->skipOrder();
            die();
        } else if (Tools::getValue('cancelSkip') != null) {
            $this->cancelSkip();
            die();
        } else if (Tools::getValue('callCourier')) {
            $result = $this->module->api->callCarrier();
            die(json_encode($result));
        } else if (Tools::getValue('cancelCourier')) {
            $result = OmnivaHelper::removeScheduledCourierCall(Tools::getValue('cancelCourier'));
            die(json_encode($result));
        }
    }

    private function getCarrierIds()
    {
        return implode(',', OmnivaltShipping::getCarrierIds());
    }

    public function displayAjax()
    {
        $customer = Tools::getValue('customer');
        $tracking = Tools::getValue('tracking_nr');
        $date = Tools::getValue('input-date-added');
        $where = '';

        if ($tracking != '' and $tracking != null and $tracking != 'undefined')
            $where .= ' AND ooh.tracking_numbers LIKE "%' . $tracking . '%" ';

        if ($customer != '' and $customer != null and $customer != 'undefined')
            $where .= ' AND CONCAT(oh.firstname, " ",oh.lastname) LIKE "%' . $customer . '%" ';

        if ($date != null and $date != 'undefined' and $date != '')
            $where .= ' AND oc.date_add LIKE "%' . $date . '%" ';


        if ($where == '')
            die(json_encode([]));


        $orders = "SELECT a.id_order, oc.date_add, a.date_upd, a.total_paid_tax_incl, CONCAT(oh.firstname, ' ',oh.lastname) as full_name, ooh.tracking_numbers, ooh.id as history
            FROM " . _DB_PREFIX_ . "orders a
			INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
			LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
			JOIN " . _DB_PREFIX_ . "omniva_order oo ON a.id_order = oo.id AND a.id_carrier IN (" . $this->_carriers . ")
            INNER JOIN " . _DB_PREFIX_ . "omniva_order_history ooh ON ooh.id_order = a.id_order AND ooh.tracking_numbers IS NOT NULL AND ooh.tracking_numbers != '' " . $where . "
			ORDER BY ooh.manifest DESC, a.id_order DESC";
        $searchResponse = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($orders);

        array_walk($searchResponse, function (&$value, $key) {
            $value['tracking_numbers'] = implode(', ', json_decode($value['tracking_numbers']));
        });

        die(json_encode($searchResponse));
    }

    public function initContent()
    {
        parent::initContent();

        $ordersCount = $this->newOrdersNumb();
        $finishedCount = $this->finishedOrdersNumb();
        $perPage = 30;

        $pagesToShow = intval(ceil($ordersCount / $perPage));
        if(Tools::getValue('tab') == 'completed')
            $pagesToShow = intval(ceil($finishedCount / $perPage));

        $page = 1;
        if (Tools::getValue('p') && Tools::getValue('p') != null)
            $page = intval(Tools::getValue('p'));
        if ($page <= 0 || $page > $pagesToShow)
            $page = 1;

        if ($pagesToShow <= 5) {
            $endGroup = $pagesToShow;
        } else {
            if ($pagesToShow - $page > 2) {
                $endGroup = $page + 2;
            } else {
                $endGroup = $pagesToShow;
            }
        }
        if ($endGroup - 4 > 0) {
            $startGroup = $endGroup - 4;
        } else {
            $startGroup = 1;
        }

        $courier_calls = OmnivaHelper::getScheduledCourierCalls();

        $this->context->smarty->assign(array(
            'orders' => $this->getOrders($page - 1, $perPage),
            'sender' => Configuration::get('omnivalt_company'),
            'phone' => Configuration::get('omnivalt_phone'),
            'postcode' => Configuration::get('omnivalt_postcode'),
            'address' => Configuration::get('omnivalt_address'),

            'skippedOrders' => $this->getSkippedOrders(),
            'newOrders' => $this->getNewOrders($page - 1, $perPage),
            'orderLink' => $this->context->link->getAdminLink('AdminOrders') . '&vieworder',
            'orderSkip' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&orderSkip=',
            'cancelSkip' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&cancelSkip=',
            'page' => $page,
            'manifestLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . "&action=printManifest",
            'labelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . "&action=printLabels",
            'generateLabelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . '&action=generateLabels&redirect=1&id_order=',
            'bulkLabelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX) . "&action=bulkPrintLabels",

            'manifestNum' => strval(Configuration::get('omnivalt_manifest')),
            'total' => $this->_listTotal,
            'courier_calls' => OmnivaHelper::splitScheduledCourierCalls($courier_calls),
        ));
        $this->context->smarty->assign(
            array(
                'nb_products' => $ordersCount,
                'products_per_page' => $perPage,
                'pages_nb' => $pagesToShow,
                'prev_p' => (int)$page != 1 ? $page - 1 : 1,
                'next_p' => (int)$page + 1 > $pagesToShow ? $pagesToShow : $page + 1,
                'requestPage' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=new',
                'current_url' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=new',
                'requestNb' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=new',
                'p' => $page,
                'n' => $perPage,
                'start' => $startGroup,
                'stop' => $endGroup,
            )
        );
        $this->context->smarty->assign(
            array(
                'finished_pagination_content' => $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/pagination.tpl'),
            )
        );

        $pagesToShow = intval(ceil($finishedCount / $perPage));
        if ($pagesToShow <= 5) {
            $endGroup = $pagesToShow;
        } else {
            if ($pagesToShow - $page > 2) {
                $endGroup = $page + 2;
            } else {
                $endGroup = $pagesToShow;
            }
        }
        if ($endGroup - 4 > 0) {
            $startGroup = $endGroup - 4;
        } else {
            $startGroup = 1;
        }
        $this->context->smarty->assign(
            array(
                'next_p' => (int)$page + 1 > $pagesToShow ? $pagesToShow : $page + 1,
                'nb_products' => $finishedCount,
                'pages_nb' => $pagesToShow,
                'requestPage' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=completed',
                'current_url' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=completed',
                'requestNb' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=completed',
                'start' => $startGroup,
                'stop' => $endGroup,
            )
        );
        $this->context->smarty->assign(
            array(
                'generated_pagination_content' => $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/pagination.tpl'),
            )
        );
        $content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/omnivaOrders.tpl');

        $this->context->smarty->assign(
            array(
                'content' => $this->content . $content,
            )
        );
    }

    public function getOrders($page = 1, $perPage = 30)
    {
        $newOrder = (int) Configuration::get('omnivalt_manifest');
        $from = $page * $perPage;
        $orders = "SELECT *, ooh.id as history FROM " . _DB_PREFIX_ . "orders a
            INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
            LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
            INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
            INNER JOIN " . _DB_PREFIX_ . "omniva_order_history ooh ON ooh.id_order = a.id_order AND ooh.manifest != 0 AND ooh.manifest != " . $newOrder . "  AND ooh.manifest != -1
            ORDER BY ooh.manifest DESC, a.id_order DESC
            LIMIT $perPage OFFSET $from";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($orders);
    }

    public function getSkippedOrders()
    {
        $orders = "SELECT * FROM " . _DB_PREFIX_ . "orders a
            INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
            LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
            INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
            INNER JOIN " . _DB_PREFIX_ . "omniva_order_history ooh ON ooh.id_order = a.id_order AND ooh.manifest IS NOT NULL AND ooh.manifest = -1
            ORDER BY ooh.manifest DESC, a.id_order DESC";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($orders);
    }

    public function getNewOrders($page = 1, $perPage = 30)
    {
        $newOrderNum = (int) Configuration::get('omnivalt_manifest');
        $from = $page * $perPage;
        $newOrder = "SELECT * FROM " . _DB_PREFIX_ . "orders a
            INNER JOIN " . _DB_PREFIX_ . "customer c ON a.id_customer = c.id_customer
            INNER JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
            INNER JOIN " . _DB_PREFIX_ . "order_state os ON a.current_state = os.id_order_state AND os.deleted = 0 AND os.shipped = 0
            INNER JOIN " . _DB_PREFIX_ . "order_state_lang osl ON a.current_state = osl.id_order_state AND a.id_lang = osl.id_lang AND (osl.template IN ('', 'preparation', 'cashondelivery', 'bankwire', 'cheque', 'payment_error', 'in_transit') OR (os.paid = 1 AND osl.template = 'payment'))
            INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
            INNER JOIN " . _DB_PREFIX_ . "omniva_order_history ooh ON ooh.id_order = a.id_order AND (ooh.manifest=" . $newOrderNum . " OR ooh.manifest = 0)
            ORDER BY a.id_order DESC
            LIMIT $perPage OFFSET $from";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($newOrder);
    }

    public function newOrdersNumb()
    {
        $newOrder = (int) Configuration::get('omnivalt_manifest');

        $ordersCount = "SELECT COUNT(*) 
            FROM " . _DB_PREFIX_ . "omniva_order_history ooh
            WHERE ooh.manifest IS NOT NULL AND ooh.manifest = 0 OR ooh.manifest = " . $newOrder . "
            ORDER BY ooh.id_order DESC";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($ordersCount);
    }

    public function finishedOrdersNumb()
    {
        $newOrder = (int) Configuration::get('omnivalt_manifest');

        $ordersCount = "SELECT COUNT(*) 
            FROM " . _DB_PREFIX_ . "omniva_order_history ooh
            WHERE ooh.manifest IS NOT NULL AND ooh.manifest != " . $newOrder . "
            ORDER BY ooh.id_order DESC";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($ordersCount);
    }

    public function skipOrder()
    {
        if (Tools::getValue('orderSkip')) {
            $id_order = (int) Tools::getValue('orderSkip');

            $omnivaOrderHistory = OmnivaOrderHistory::getLatestOrderHistory($id_order);
            if (Validate::isLoadedObject($omnivaOrderHistory)) {
                $omnivaOrderHistory->manifest = -1;
                $omnivaOrderHistory->update();
            }
        }
        Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
    }

    public function cancelSkip()
    {
        if (Tools::getValue('cancelSkip')) {
            $id_order = (int) Tools::getValue('cancelSkip');
            $omnivaOrderHistory = OmnivaOrderHistory::getLatestOrderHistory($id_order);

            if (Validate::isLoadedObject($omnivaOrderHistory)) {
                $omnivaOrderHistory->manifest = 0;
                $omnivaOrderHistory->update();
            }
        }
        Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
    }
}
