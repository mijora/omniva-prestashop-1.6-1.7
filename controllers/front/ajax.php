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
            OmnivaHelper::printToLog('Cart #' . $id_cart . '. Saving terminal...', 'cart');
            $cartTerminal = new OmnivaCartTerminal($id_cart);
            if(Validate::isLoadedObject($cartTerminal))
            {
                $cartTerminal->id_terminal = pSQL(Tools::getValue('terminal'));
                $result &= $cartTerminal->update();
                OmnivaHelper::printToLog('Cart #' . $id_cart . '. Terminal updated to ' . $cartTerminal->id_terminal, 'cart');
            }
            else
            {
                $cartTerminal->id = $id_cart;
                $cartTerminal->force_id = true;
                $cartTerminal->id_terminal = pSQL(Tools::getValue('terminal'));
                $result &= $cartTerminal->add();
                OmnivaHelper::printToLog('Cart #' . $id_cart . '. Terminal ' . $cartTerminal->id_terminal . ' added', 'cart');
            }
            $response = $result ? ['success' => 'Terminal saved'] : ['fail' => 'Failed to save terminal'];
            die(json_encode($response));
        }
    }
}