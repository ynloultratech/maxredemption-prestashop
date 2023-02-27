<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
include_once('./modules/maxredemptionpayment/libs/jwt.php');

class MaxredemptionpaymentValidationModuleFrontController extends ModuleFrontController
{
    /**
     * This class should be use by your Instant Payment
     * Notification system to validate the order remotely
     */
    public function postProcess()
    {
        $api_key = Configuration::get('MAXREDEMPTIONPAYMENT_API_KEY');
        $api_iss = Configuration::get('MAXREDEMPTIONPAYMENT_API_ID');
        $autoRedirect = Configuration::get('MAXREDEMPTIONPAYMENT_AUTO_REDIRECT');
        $autoRedeem = Configuration::get('MAXREDEMPTIONPAYMENT_AUTO_REDEEM');
        $allowShare = Configuration::get('MAXREDEMPTIONPAYMENT_ALLOW_SHARE');
        $qrCode = Configuration::get('MAXREDEMPTIONPAYMENT_QR_CODE');

        /*
         * If the module is not active anymore, no need to process anything.
         */
        if ($this->module->active == false) {
            die;
        }

        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'maxredemptionpayment') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }

        $customer = new Customer($cart->id_customer);
        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $states = OrderState::getOrderStates($this->context->language->id);
        foreach ($states as $key => $val) {
            if ($val['module_name'] === 'maxredemptionpayment') {
                $id_order_state = $val['id_order_state'];
                break;
            }
        }
        /**
         * Place the order
         */
        $this->module->validateOrder(
            (int)$this->context->cart->id,
            $id_order_state,
            (float)$this->context->cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName,
            null,
            null,
            (int)$this->context->currency->id,
            false,
            $customer->secure_key
        );

        $address = new Address((int)$cart->id_address_delivery);

        $state_id = $address->getFields()['id_state'];
        $state = new State((int)$state_id);

        $urlHandler = $this->context->link->getModuleLink('maxredemptionpayment', 'ipn', array(), Configuration::get('PS_SSL_ENABLED'));

        $orderId = $this->module->currentOrder;
        $url = $this->context->link->getBaseLink().'index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$orderId.'&key='.$customer->secure_key;

        $amount = $cart->getOrderTotal(
            $withTaxes = true,
            $type = Cart::BOTH
        );

        $payload = [
            'jti' => $orderId,
            "iss" => $api_iss,
            "iat" => time()
        ];
        $jwt = new eGiftCertificate_JWT();
        $token = $jwt::encode($payload, $api_key);

        $claim_data = [
            "iss" => $api_iss,
            "jti" => $api_key,
            "iat" => time(),
            "params" => [
                "redirectUrl" => $url,
                "autoRedirect" => $autoRedirect,
                "autoRedeem" => $autoRedeem,
                "qrCode" => $qrCode,
                "orderNumber" => $orderId,
                "receiptEmail" => $customer->email,
                "amount" => $amount,
                "customerName" => $customer->firstname . ' ' . $customer->lastname,
                "customerPhone" => $address->phone,
                "billingAddress" => $address->address1,
                "billingCity" => $address->city,
                "billingState" => $state->iso_code,
                "billingZipCode" => $address->postcode,
                "IPNHandlerUrl" => $urlHandler,
                "token" => $token
            ]
        ];

        $claim = $jwt::encode($claim_data, $api_key);
        /**
         * Redirect the customer to the order confirmation page
         */
        Tools::redirect('https://egiftcert.paynup.com?claim=' . $claim);
    }

}
