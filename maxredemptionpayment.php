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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Maxredemptionpayment extends PaymentModule
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'maxredemptionpayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'YNLO ULtratech INC';
        $this->need_instance = 1;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Max Redemption Payment');
        $this->description = $this->l('Max Redemption Gateway Payment Module');

        $this->confirmUninstall = $this->l('');

        $this->limited_countries = array('US');

        $this->limited_currencies = array('USD');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        if (in_array($iso_code, $this->limited_countries) == false) {
            $this->_errors[] = $this->l('This module is not available in your country');
            return false;
        }

        Configuration::updateValue('MAXREDEMPTIONPAYMENT_API_KEY', '');
        Configuration::updateValue('MAXREDEMPTIONPAYMENT_API_ID', '');
        Configuration::updateValue('MAXREDEMPTIONPAYMENT_AUTO_REDIRECT', true);
        Configuration::updateValue('MAXREDEMPTIONPAYMENT_AUTO_REDEEM', true);
        Configuration::updateValue('MAXREDEMPTIONPAYMENT_ALLOW_SHARE', true);
        Configuration::updateValue('MAXREDEMPTIONPAYMENT_QR_CODE', true);


        return parent::install() &&
            $this->addOrderState($this->l('Awaiting Max Redemption payment')) &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('paymentReturn') &&
            $this->registerHook('paymentOptions') ;
    }

    public function uninstall()
    {

        return parent::uninstall();
    }
    public function addOrderState($name)
    {
        $state_exist = false;
        $states = OrderState::getOrderStates((int)$this->context->language->id);

        // check if order state exist
        foreach ($states as $state) {
            if (in_array($name, $state)) {
                $state_exist = true;
                break;
            }
        }

        // If the state does not exist, we create it.
        if (!$state_exist) {
            // create new order state
            $order_state = new OrderState();
            $order_state->color = '#00ffff';
            $order_state->send_email = false;
            $order_state->module_name = 'maxredemptionpayment';
            $order_state->name = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $language)
                $order_state->name[ $language['id_lang'] ] = $name;

            // Update object
            $order_state->add();
        }

        return true;
    }
    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitMaxredemptionpaymentModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMaxredemptionpaymentModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('non-secure value that should be passed within the JWT under the iss claim.'),
                        'required' => true,
                        'name' => 'MAXREDEMPTIONPAYMENT_API_ID',
                        'label' => $this->l('API ID'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('a SECURE value that should only ever be known between you and PaynUp.'),
                        'required' => true,
                        'name' => 'MAXREDEMPTIONPAYMENT_API_KEY',
                        'label' => $this->l('API KEY'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Auto Redirect'),
                        'name' => 'MAXREDEMPTIONPAYMENT_AUTO_REDIRECT',
                        'is_bool' => true,
                        'desc' => $this->l('Enable autoRedirect'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Auto Redeem'),
                        'name' => 'MAXREDEMPTIONPAYMENT_AUTO_REDEEM',
                        'is_bool' => true,
                        'desc' => $this->l('Enable autoRedeem'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow Share'),
                        'name' => 'MAXREDEMPTIONPAYMENT_ALLOW_SHARE',
                        'is_bool' => true,
                        'desc' => $this->l('Enable allowShare'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('QR Code'),
                        'name' => 'MAXREDEMPTIONPAYMENT_QR_CODE',
                        'is_bool' => true,
                        'desc' => $this->l('Enable qrCode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'MAXREDEMPTIONPAYMENT_API_ID' => Configuration::get('MAXREDEMPTIONPAYMENT_API_ID', null),
            'MAXREDEMPTIONPAYMENT_API_KEY' => Configuration::get('MAXREDEMPTIONPAYMENT_API_KEY', null),
            'MAXREDEMPTIONPAYMENT_AUTO_REDIRECT' => Configuration::get('MAXREDEMPTIONPAYMENT_AUTO_REDIRECT', true),
            'MAXREDEMPTIONPAYMENT_AUTO_REDEEM' => Configuration::get('MAXREDEMPTIONPAYMENT_AUTO_REDEEM', true),
            'MAXREDEMPTIONPAYMENT_ALLOW_SHARE' => Configuration::get('MAXREDEMPTIONPAYMENT_ALLOW_SHARE', true),
            'MAXREDEMPTIONPAYMENT_QR_CODE' => Configuration::get('MAXREDEMPTIONPAYMENT_QR_CODE', true)
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }


    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if ($this->active == false)
            return;

        return $this->fetch('module:maxredemptionpayment/views/templates/hook/confirmation.tpl');

    }

    /**
     * Return payment options available for PS 1.7+
     *
     * @param array Hook parameters
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {

        if (!$this->active) {
            return;
        }
        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $option = new PaymentOption();
//        $option->setLogo(_MODULE_DIR_.'maxredemptionpayment/logo.png')
        $option->setCallToActionText($this->l('Pay with Max Redemption'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), Configuration::get('PS_SSL_ENABLED')));


        return [
            $option
        ];
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

}
