<?php

class OmnivaltshippingAjaxModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        if(Tools::getValue('action') == 'saveParcelTerminalDetails')
        {
            $result = true;
            $id_cart = $this->context->cart->id;
            $cartTerminal = new OmnivaCartTerminal($id_cart);
            if(Validate::isLoadedObject($cartTerminal))
            {
                $cartTerminal->id_terminal = (int) Tools::getValue('terminal');
                $result &= $cartTerminal->update();
            }
            else
            {
                $cartTerminal->id = $id_cart;
                $cartTerminal->force_id = true;
                $cartTerminal->id_terminal = (int) Tools::getValue('terminal');
                $result &= $cartTerminal->add();
            }
            $response = $result ? ['success' => 'Terminal saved'] : ['fail' => 'Failed to save terminal'];
            die(json_encode($response));
        }
    }
}