<?php

class ModelCheckoutBillmateOrder extends Model
{
    public function createOrder($payment_data)
    {
        $this->load->model('checkout/order');
        $this->load->model('checkout/billmate/country');

        $order_data = $this->buildOrder();

        if (!empty($payment_data['Customer'])) {
            $shipping_address = !empty($payment_data['Customer']['Shipping'])
                ? $payment_data['Customer']['Shipping']
                : $payment_data['Customer']['Billing'];

            array_walk($shipping_address, function (&$value) {
                $value = Encoding::fixUTF8($value);
            });

            $order_data['shipping_firstname']  = $shipping_address['firstname'];
            $order_data['shipping_lastname']   = $shipping_address['lastname'];
            $order_data['shipping_company']    = !empty($shipping_address['company']) ? $shipping_address['company'] : null;
            $order_data['shipping_address_1']  = $shipping_address['street'];
            $order_data['shipping_address_2']  = !empty($shipping_address['street2']) ? $shipping_address['street2'] : null;
            $order_data['shipping_city']       = $shipping_address['city'];
            $order_data['shipping_postcode']   = $shipping_address['zip'];
            $order_data['shipping_country']    = $shipping_address['country'];
            $order_data['shipping_country_id'] = $this->model_checkout_billmate_country->getCountryIdByCode(
                $shipping_address['country']
            );

            $payment_address = $payment_data['Customer']['Billing'];

            array_walk($payment_address, function (&$value) {
                $value = Encoding::fixUTF8($value);
            });

            $order_data['payment_firstname']  = $payment_address['firstname'];
            $order_data['payment_lastname']   = $payment_address['lastname'];
            $order_data['payment_company']    = !empty($payment_address['company']) ? $payment_address['company'] : null;
            $order_data['payment_address_1']  = $payment_address['street'];
            $order_data['payment_address_2']  = !empty($payment_address['street2']) ? $payment_address['street2'] : null;
            $order_data['payment_city']       = $payment_address['city'];
            $order_data['payment_postcode']   = $payment_address['zip'];
            $order_data['payment_country']    = $payment_address['country'];
            $order_data['payment_country_id'] = $this->model_checkout_billmate_country->getCountryIdByCode(
                $payment_address['country']
            );

            if (!empty($payment_address['firstname'])) {
                $order_data['firstname'] = $payment_address['firstname'];
            }

            if (!empty($payment_address['lastname'])) {
                $order_data['lastname'] = $payment_address['lastname'];
            }

            if (!empty($payment_data['Customer']['Billing']['email'])) {
                $order_data['email'] = $payment_data['Customer']['Billing']['email'];
            }

            if (!empty($payment_data['Customer']['Billing']['phone'])) {
                $order_data['telephone'] = $payment_data['Customer']['Billing']['phone'];
            }
        }

        if (!empty($payment_data['PaymentData'])) {
            $billmate_method = (int)$payment_data['PaymentData']['method'];

            switch ($billmate_method) {
                case 1:
                    $payment_method = 'Billmate Checkout - Invoice';
                    break;

                case 2:
                    $payment_method = 'Billmate Checkout - Invoiceservice';
                    break;

                case 4:
                    $payment_method = 'Billmate Checkout - Partpay';
                    break;

                case 8:
                    $payment_method = 'Billmate Checkout - Cardpay';
                    break;

                case 16:
                    $payment_method = 'Billmate Checkout - Bankpay';
                    break;

                case 1024:
                    $payment_method = 'Billmate Checkout - Swish';
                    break;

                default:
                    $payment_method = 'Billmate Checkout';
                    break;

            }

            $order_data['payment_method'] = $payment_method;
        }

        $order_id = $this->model_checkout_order->addOrder($order_data);

        if (!empty($payment_data['Cart'])) {
            $total = intval($payment_data['Cart']['Total']['withtax']) / 100;
            // @todo Check if total is equal to order
        }

        $this->session->data['order_id'] = $order_id;

        return $order_id;
    }

    public function addInvoice($order_id, $invoice_id)
    {
        $this->db->query('
            INSERT INTO ' . DB_PREFIX . 'billmate_order_invoice (`order_id`, `invoice_id`)
            VALUES (' . (int)$order_id . ',"' . $this->db->escape($invoice_id) . '")
            ON DUPLICATE KEY UPDATE `invoice_id` = "' . $this->db->escape($invoice_id) . '"'
        );
    }

    public function getProducts()
    {
        $products = [];

        foreach ($this->cart->getProducts() as $product) {
            $option_data = array();

            foreach ($product['option'] as $option) {
                $option_data[] = array(
                    'product_option_id'       => $option['product_option_id'],
                    'product_option_value_id' => $option['product_option_value_id'],
                    'option_id'               => $option['option_id'],
                    'option_value_id'         => $option['option_value_id'],
                    'name'                    => $option['name'],
                    'value'                   => $option['value'],
                    'type'                    => $option['type']
                );
            }

            $products[] = array(
                'product_id' => $product['product_id'],
                'name'       => $product['name'],
                'model'      => $product['model'],
                'option'     => $option_data,
                'download'   => $product['download'],
                'quantity'   => $product['quantity'],
                'subtract'   => $product['subtract'],
                'price'      => $product['price'],
                'total'      => $product['total'],
                'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
                'reward'     => $product['reward']
            );
        }

        return $products;
    }

    public function getVouchers()
    {
        $vouchers = [];

        if (!empty($this->session->data['vouchers'])) {
            foreach ($this->session->data['vouchers'] as $voucher) {
                $vouchers[] = array(
                    'description'      => $voucher['description'],
                    'code'             => token(10),
                    'to_name'          => $voucher['to_name'],
                    'to_email'         => $voucher['to_email'],
                    'from_name'        => $voucher['from_name'],
                    'from_email'       => $voucher['from_email'],
                    'voucher_theme_id' => $voucher['voucher_theme_id'],
                    'message'          => $voucher['message'],
                    'amount'           => $voucher['amount']
                );
            }
        }

        return $vouchers;
    }

    public function getShippingTitle()
    {
        return !empty($this->session->data['shipping_method']['title'])
            ? $this->session->data['shipping_method']['title']
            : null;
    }

    public function getShippingCode()
    {
        return !empty($this->session->data['shipping_method']['code'])
            ? $this->session->data['shipping_method']['code']
            : null;
    }

    public function getAffiliateId()
    {
        $this->load->model('affiliate/affiliate');

        $affiliate_info = $this->model_affiliate_affiliate->getAffiliateByCode($this->request->cookie['tracking']);

        return ($affiliate_info) ? $affiliate_info['affiliate_id'] : 0;
    }

    public function getMarketingId()
    {
        $this->load->model('checkout/marketing');

        $marketing_info = $this->model_checkout_marketing->getMarketingByCode($this->request->cookie['tracking']);

        return ($marketing_info) ? $marketing_info['marketing_id'] : 0;
    }

    public function getForwardedIp() {
        if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
            return $this->request->server['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
            return $this->request->server['HTTP_CLIENT_IP'];
        }

        return null;
    }

    public function getCommission()
    {
        $this->load->model('affiliate/affiliate');

        $subtotal = $this->cart->getSubTotal();

        $affiliate_info = $this->model_affiliate_affiliate->getAffiliateByCode($this->request->cookie['tracking']);

        if (!$affiliate_info) {
            return 0;
        }

        return (($subtotal / 100) * $affiliate_info['commission']);
    }

    public function getUserAgent()
    {
        return !empty($this->request->server['HTTP_USER_AGENT'])
            ? $this->request->server['HTTP_USER_AGENT']
            : null;
    }

    public function getAcceptLanguage()
    {
        return !empty($this->request->server['HTTP_ACCEPT_LANGUAGE'])
            ? $this->request->server['HTTP_ACCEPT_LANGUAGE']
            : null;
    }

    public function getTotals()
    {
        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

        $this->load->model('setting/extension');

        $sort_order = array();

        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);

                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        $sort_order = array();

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);

        return array($totals, $taxes, $total);
    }

    public function getLoggedCustomerInfo()
    {
        $this->load->model('account/customer');

        $customer = $this->model_account_customer->getCustomer($this->customer->getId());

        return [
            'customer_id'       => $this->customer->getId(),
            'customer_group_id' => $customer['customer_group_id'],
            'firstname'         => $customer['firstname'],
            'lastname'          => $customer['lastname'],
            'email'             => $customer['email'],
            'telephone'         => $customer['telephone'],
            'custom_field'      => json_decode($customer['custom_field'], true),
        ];
    }

    public function updateOrderStatus()
    {
        $this->model_checkout_order->addOrderHistory($klarna_checkout_order['order_id'], $order_status_id);
    }

    private function buildOrder()
    {
        $order_data = [
            'invoice_no'              => null,
            'invoice_prefix'          => $this->config->get('config_invoice_prefix'),
            'store_id'                => $this->config->get('config_store_id'),
            'store_name'              => $this->config->get('config_name'),
            'store_url'               => $this->config->get('config_url'),
            'customer_id'             => 0,
            'customer_group_id'       => 0,
            'firstname'               => null,
            'lastname'                => null,
            'email'                   => null,
            'telephone'               => null,
            'fax'                     => null,
            'custom_field'            => null,
            'payment_firstname'       => null,
            'payment_lastname'        => null,
            'payment_company'         => null,
            'payment_address_1'       => null,
            'payment_address_2'       => null,
            'payment_city'            => null,
            'payment_postcode'        => null,
            'payment_country'         => null,
            'payment_country_id'      => 0,
            'payment_zone'            => null,
            'payment_zone_id'         => 0,
            'payment_address_format'  => null,
            'payment_custom_field'    => null,
            'payment_method'          => 'Billmate Checkout',
            'payment_code'            => 'billmate_checkout',
            'shipping_firstname'      => null,
            'shipping_lastname'       => null,
            'shipping_company'        => null,
            'shipping_address_1'      => null,
            'shipping_address_2'      => null,
            'shipping_city'           => null,
            'shipping_postcode'       => null,
            'shipping_country'        => null,
            'shipping_country_id'     => 0,
            'shipping_zone'           => null,
            'shipping_zone_id'        => 0,
            'shipping_address_format' => null,
            'shipping_custom_field'   => null,
            'shipping_method'         => null,
            'shipping_code'           => null,
            'comment'                 => !empty($this->session->data['comment']) ? $this->session->data['comment'] : null,
            'total'                   => null,
            'order_status_id'         => 0,
            'affiliate_id'            => 0,
            'commission'              => 0,
            'marketing_id'            => 0,
            'tracking'                => null,
            'language_id'             => $this->config->get('config_language_id'),
            'currency_id'             => $this->currency->getId($this->session->data['currency']),
            'currency_code'           => $this->session->data['currency'],
            'currency_value'          => $this->currency->getValue($this->session->data['currency']),
            'ip'                      => $this->request->server['REMOTE_ADDR'],
            'forwarded_ip'            => $this->getForwardedIp(),
            'user_agent'              => $this->getUserAgent(),
            'accept_language'         => $this->getAcceptLanguage(),
            'products'                => [],
            'totals'                  => [],
            'vouchers'                => [],
        ];

        if ($this->cart->hasShipping()) {
            $order_data['shipping_method'] = $this->getShippingTitle();
            $order_data['shipping_code']   = $this->getShippingCode();
        }

        if ($this->customer->isLogged()) {
            $this->load->model('account/customer');

            $customer = $this->model_account_customer->getCustomer($this->customer->getId());

            $order_data['customer_id']       = $this->customer->getId();
            $order_data['customer_group_id'] = $customer['customer_group_id'];
            $order_data['firstname']         = $customer['firstname'];
            $order_data['lastname']          = $customer['lastname'];
            $order_data['email']             = $customer['email'];
            $order_data['telephone']         = $customer['telephone'];
            $order_data['custom_field']      = json_decode($customer['custom_field'], true);
        }

        if (!empty($this->request->cookie['tracking'])) {
            $order_data['affiliate_id'] = $this->getAffiliateId();
            $order_data['commission']   = $this->getCommission();
            $order_data['marketing_id'] = $this->getMarketingId();
            $order_data['tracking']     = $this->request->cookie['tracking'];
        }

        list($totals, $taxes, $total) = $this->getTotals();

        $order_data['total'] = $total;
        $order_data['totals'] = $totals;

        $order_data['products'] = $this->getProducts();
        $order_data['vouchers'] = $this->getVouchers();

        return $order_data;
    }
}
