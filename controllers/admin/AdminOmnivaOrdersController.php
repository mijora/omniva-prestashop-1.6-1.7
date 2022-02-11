<?php

class AdminOmnivaOrdersController extends ModuleAdminController
{
    private $_carriers = '';

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addJS('modules/' . $this->module->name . '/views/js/omniva-orders.js');
        Media::addJsDef([
            'check_orders' => $this->module->l('Please select orders'),
            'carrier_cal_url' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&callCourier=1',
            'finished_trans' => $this->module->l('Finished.'),
            'message_sent_trans' => $this->module->l('Message successfully sent.'),
            'incorrect_response_trans' => $this->module->l('Incorrect response.'),
            'ajaxCall' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&ajax',
            'orderLink' => $this->context->link->getAdminLink('AdminOrders') . '&vieworder',
            'manifestLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX, true, [], ["action" => "printManifest"]),
            'bulkLabelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX, true, [], ["action" => "bulkPrintLabels"]),
            'labels_trans' => $this->module->l('Labels'),
            'not_found_trans' => $this->module->l('Nothing found'),
        ]);
    }

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->_carriers = $this->getCarrierIds();
        if (Tools::getValue('orderSkip') != null) {
            $this->skipOrder();
            die();
        } else if (Tools::getValue('cancelSkip') != null) {
            $this->cancelSkip();
            die();
        } else if (Tools::getValue('callCourier')) {
            die($this->module->api->callCarrier());
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
            $where .= ' AND oo.tracking_numbers LIKE "%' . $tracking . '%" ';

        if ($customer != '' and $customer != null and $customer != 'undefined')
            $where .= ' AND CONCAT(oh.firstname, " ",oh.lastname) LIKE "%' . $customer . '%" ';

        if ($date != null and $date != 'undefined' and $date != '')
            $where .= ' AND oc.date_add LIKE "%' . $date . '%" ';


        if ($where == '')
            die(json_encode([]));


        $orders = "SELECT a.id_order, oc.date_add, a.date_upd, a.total_paid_tax_incl, CONCAT(oh.firstname, ' ',oh.lastname) as full_name, oc.tracking_number
            FROM " . _DB_PREFIX_ . "orders a
			INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
			LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
			JOIN " . _DB_PREFIX_ . "omniva_order oo ON a.id_order = oo.id AND a.id_carrier IN (" . $this->_carriers . ")
			WHERE oo.tracking_numbers IS NOT NULL AND oo.tracking_numbers != '' " . $where . " 
			ORDER BY oo.manifest DESC, a.id_order DESC";

        $searchResponse = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($orders);
        die(json_encode($searchResponse));
    }

    public function initContent()
    {
        parent::initContent();

        $ordersCount = $this->ordersNumb();
        $perPage = 10;
        $pagesToShow = intval(ceil($ordersCount / $perPage));
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

        $this->context->smarty->assign(array(
            'orders' => $this->getOrders($page - 1, $perPage),

            'sender' => Configuration::get('omnivalt_company'),
            'phone' => Configuration::get('omnivalt_phone'),
            'postcode' => Configuration::get('omnivalt_postcode'),
            'address' => Configuration::get('omnivalt_address'),

            'skippedOrders' => $this->getSkippedOrders(),
            'newOrders' => $this->getNewOrders(),
            'orderLink' => $this->context->link->getAdminLink('AdminOrders') . '&vieworder',
            'orderSkip' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&orderSkip=',
            'cancelSkip' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&cancelSkip=',
            'page' => $page,
            'manifestLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX, true, [], array("action" => "printManifest")),
            'labelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX, true, [], ["action" => "printLabels"]),
            'bulkLabelsLink' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_AJAX, true, [], ["action" => "bulkPrintLabels"]),

            'manifestNum' => strval(Configuration::get('omnivalt_manifest')),
            'total' => $this->_listTotal,

            'nb_products' => $ordersCount,
            'products_per_page' => $perPage,
            'pages_nb' => $pagesToShow,
            'prev_p' => (int)$page != 1 ? $page - 1 : 1,
            'next_p' => (int)$page + 1 > $pagesToShow ? $pagesToShow : $page + 1,
            'requestPage' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=completed',
            'current_url' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=completed',
            'requestNb' => $this->context->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS) . '&tab=completed',
            'p' => $page,
            'n' => $perPage,
            'start' => $startGroup,
            'stop' => $endGroup,
        ));
        $this->context->smarty->assign(
            array(
                'pagination_content' => $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/pagination.tpl'),
                'pagination_file' => _PS_THEME_DIR_ . 'templates/_partials/pagination.tpl',
                'pagination' => array('items_shown_from' => 1, 'items_shown_to' => 1, 'total_items' => $ordersCount, 'should_be_displayed' => 1, 'pages' => 3)
        ));
        $content = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'omnivaltshipping/views/templates/admin/omnivaOrders.tpl');

        $this->context->smarty->assign(
            array(
                'content' => $this->content . $content,
            )
        );

    }

    public function getOrders($page = 1, $perPage = 10)
    {
        $newOrder = (int) Configuration::get('omnivalt_manifest');
        $from = $page * $perPage;
        $orders = "SELECT * FROM " . _DB_PREFIX_ . "orders a
		INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
		LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
		INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
		WHERE oo.manifest != 0 AND oo.manifest != " . $newOrder . "  AND oo.manifest != -1
		ORDER BY oo.manifest DESC, a.id_order DESC
		LIMIT $perPage OFFSET $from";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($orders);
    }

    public function getSkippedOrders()
    {
        $orders = "SELECT * FROM " . _DB_PREFIX_ . "orders a
		INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
		LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
		INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
		WHERE oo.manifest IS NOT NULL AND oo.manifest = -1
		ORDER BY oo.manifest DESC, a.id_order DESC";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($orders);
    }

    public function getNewOrders()
    {
        $newOrderNum = (int) Configuration::get('omnivalt_manifest');
        $newOrder = "SELECT * FROM " . _DB_PREFIX_ . "orders a
		INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
		INNER JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
		INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
		WHERE oo.manifest = 0 OR oo.manifest=" . $newOrderNum . "
		ORDER BY oo.manifest DESC, a.id_order DESC";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($newOrder);
    }

    public function ordersNumb()
    {
        $newOrder = (int) Configuration::get('omnivalt_manifest');

        $ordersCount = "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "orders a
				INNER JOIN " . _DB_PREFIX_ . "customer oh ON a.id_customer = oh.id_customer
				LEFT JOIN " . _DB_PREFIX_ . "order_carrier oc ON a.id_order = oc.id_order
				INNER JOIN " . _DB_PREFIX_ . "omniva_order oo ON oo.id = a.id_order AND a.id_carrier IN (" . $this->_carriers . ")
				WHERE oo.manifest != 0 AND oo.manifest != " . $newOrder . " AND oo.manifest != -1
				ORDER BY a.id_order DESC";

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($ordersCount);
    }

    public function skipOrder()
    {
        if (Tools::getValue('orderSkip')) {
            $id_order = (int) Tools::getValue('orderSkip');
            $omnivaOrder = new OmnivaOrder($id_order);

            if(Validate::isLoadedObject($omnivaOrder))
            {
                $omnivaOrder->manifest = -1;
                $omnivaOrder->update();
            }
        }
        Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));

    }

    public function cancelSkip()
    {
        if (Tools::getValue('cancelSkip')) {
            $id_order = (int) Tools::getValue('cancelSkip');
            $omnivaOrder = new OmnivaOrder($id_order);

            if(Validate::isLoadedObject($omnivaOrder))
            {
                $omnivaOrder->manifest = 0;
                $omnivaOrder->update();
            }
        }
        Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
    }
}
