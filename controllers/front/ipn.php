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

class MaxredemptionpaymentIpnModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        $this->display_header = false;
        $this->display_header_javascript = false;
        $this->display_footer = false;
    }

    /**
     * This class should be use by your Instant Payment
     * Notification system to validate the order remotely
     */
    public function postProcess()
    {
        $api_key = Configuration::get('MAXREDEMPTIONPAYMENT_API_KEY');
        $api_id = Configuration::get('MAXREDEMPTIONPAYMENT_API_ID');

        $token = Tools::file_get_contents("php://input");

        try {
            $payload = eGiftCertificate_JWT::decode($token, $api_key, ['HS256']);

        } catch (\Exception $exception) {
            Tools::dieOrLog($exception->getMessage());
        }

        if (isset($payload->orderNumber)) {

            $objOrder = new Order($payload->orderNumber);

            if ($payload->iss !== $api_id
                || !$objOrder
                || $payload->amount != $objOrder->total_paid
            ) {
                Tools::dieOrLog('IPN does not match with any existent order');
            }

            if ($payload->status === 'SOLD') {
                PrestaShopLogger::addLog(sprintf('eGiftCertificate obtained: %s', $payload->pin), 1);
                die(sprintf('eGiftCertificate obtained: %s', $payload->pin));
            }
            if ($payload->status === 'USED') {
                $states = OrderState::getOrderStates($this->context->language->id);
                foreach ($states as $key => $val) {
                    if ($val['name'] === 'Processing in progress') {
                        $id_order = $val['id_order_state'];
                        break;
                    }
                }
                $history = new OrderHistory();
                $history->id_order = (int)$objOrder->id;
                $history->changeIdOrderState($id_order, (int)($objOrder->id));
                die();
            }

        } else {
            Tools::dieOrLog('Invalid IPN Payload');
        }

    }

}
