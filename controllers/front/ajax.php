<?php
class OmnivaltshippingAjaxModuleFrontController extends ModuleFrontController
{
 
	public function initContent()
	{
		$this->ajax = true;
		parent::initContent();
	}
 
	public function displayAjax()
	{
        $context = Context::getContext();
        if ($terminal = Tools::getValue('terminal'))
        {
          $context->cart->setOmnivaltTerminal($terminal);
          die(json_encode('OK'));
        }
        die(json_encode('not_changed'));
	}
 
}