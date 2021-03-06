<?php

/**
 * Class ModelBillmateCheckoutRequest
 */
class ModelBillmateCheckoutRequest extends Model
{
    const METHOD_CODE = 93;

    const WINDOW_MODE = 'iframe';

    const SEND_RECIEPT = 'yes';

    const REDIRECT_ON_SUCCESS = 'true';

    const COMPANY_VIEW_FLAG = 'true';

    /**
     * @var array
     */
    protected $requestData = [];

    /**
     * @var HelperBillmate
     */
    protected $helperBillmate;

    /**
     * @var bool
     */
    protected $isUpdated = false;

    /**
     * @var int
     */
    protected $discountAmount = 0;

    /**
     * @var \Billmate\Bmcart
     */
    protected $bmcart ;

    /**
     * ModelBillmateCheckoutRequest constructor.
     *
     * @param $registry
     */
    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->helperBillmate  = new Helperbm($registry);
        $this->bmcart  = new \Billmate\Bmcart($registry);
        $this->load->model('extension/total/coupon');
        $this->load->model('extension/total/shipping');
        $this->load->model('setting/extension');
        $this->load->model('billmate/checkout/handling/invoice_fee');
        if ($this->config->get('config_tax_default') == 'shipping') {
            $this->tax->setShippingAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));
        }

        if ($this->config->get('config_tax_default') == 'payment') {
            $this->tax->setPaymentAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));
        }
        $this->tax->setStoreAddress($this->config->get('config_country_id'), $this->config->get('config_zone_id'));

    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        $billmateConnection = $this->getBmHelper()->getBillmateConnection();
        $billmateHash = $this->getBmHelper()->getSessionBmHash();
        $requestCartData = $this->getCartData();
        if (!$billmateHash) {
            return $billmateConnection->initCheckout($requestCartData);
        }

        $requestData = [
            'PaymentData' => ['hash' => $billmateHash]
        ];
        $bmCheckoutData = $billmateConnection->getCheckout($requestData);

        if (!$this->isSameCartUsed($requestCartData, $bmCheckoutData)) {
            return $billmateConnection->initCheckout($requestCartData);
        }

        $updateCheckoutData = $this->getUpdateDataFromComparison($bmCheckoutData, $requestCartData);
        if ($updateCheckoutData) {
            return $billmateConnection->updateCheckout($updateCheckoutData);
        }

        return $bmCheckoutData;
    }

    /**
     * @param $requestCartData
     * @param $bmCheckoutData
     *
     * @return bool
     */
    protected function isSameCartUsed($requestCartData, $bmCheckoutData)
    {
        return ($requestCartData['PaymentData']['orderid'] ==
        $bmCheckoutData['PaymentData']['orderid']);
    }

    /**
     * @param $bmCheckoutData
     * @param $requestCartData
     *
     * @return array
     */
    protected function getUpdateDataFromComparison($bmCheckoutData, $requestCartData)
    {
        $updateData = [];

        if (
        ($bmCheckoutData['Cart']['Total']['withouttax']
            != $requestCartData['Cart']['Total']['withouttax']) || $this->isUpdated()
        ) {
            unset($requestCartData['PaymentData']);
            $requestCartData['PaymentData']['number']  = $bmCheckoutData['PaymentData']['number'];
            $requestCartData['PaymentData']['orderid'] = $bmCheckoutData['PaymentData']['orderid'];
            $updateData = $requestCartData;
        }

        return $updateData;
    }

    /**
     * @return array
     */
    public function getCartData()
    {
        $this->initPaymentData();
        $this->initCheckoutData();
        $this->addArticlesData();
        $this->addDiscountData();
        $this->addCartTotalsData();

        return $this->getRequestData();
    }

    /**
     * @return $this
     */
    protected function initPaymentData()
    {
        $this->requestData['PaymentData'] = [
                'method' => self::METHOD_CODE,
                'currency' => strtoupper($this->session->data['currency']),
                'currency_value' => $this->currency->getValue($this->session->data['currency']),
                'language' => 'sv',
                'country' => 'SE',
                'orderid' => $this->generateBillmateOrderId(),
                'sessionid' => $this->generateBillmateOrderId(),
                'logo' => $this->getBmHelper()->getLogoName(),
                'accepturl' => $this->url->link(
                    'checkout/billmate/billmate/accept',
                    '',
                    $this->request->server['HTTPS']
                ),
                'cancelurl' => $this->url->link(
                    'billmatecheckout/cancel',
                    '',
                    $this->request->server['HTTPS']
                ),
                'callbackurl' => $this->url->link(
                    'checkout/billmate/billmate/callback',
                    '',
                    $this->request->server['HTTPS']
                ),
                'returnmethod' => 'POST',
            ];

        return $this;
    }

    /**
     * @return $this
     */
    protected function initCheckoutData()
    {
        $this->requestData['CheckoutData'] = [
            'terms' => $this->getBmHelper()->getStoreTermsLink(),
            'windowmode' => self::WINDOW_MODE,
            'sendreciept' => self::SEND_RECIEPT,
            'redirectOnSuccess' => self::REDIRECT_ON_SUCCESS,
        ];

        $privacyPolicyLink = $this->getBmHelper()->getPrivacyPolicyLink();
        $getBillmateLogo = $this->getBmHelper()->getInvoiceLogo();

        if ($privacyPolicyLink) {
            $this->requestData['CheckoutData']['privacyPolicy'] = $privacyPolicyLink;
        }

        if ($getBillmateLogo) {
            $this->requestData['PaymentData']['logo'] = $getBillmateLogo;
        }

        if ($this->getBmHelper()->isCompanyView()) {
            $this->requestData['CheckoutData']['companyView'] = self::COMPANY_VIEW_FLAG;
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function addArticlesData()
    {
        $data['products'] = array();
        $products = $this->cart->getProducts();
        foreach ($products as $product) {
         $prices = $this->getProductPrices($product);
                $extraOption = null;
                foreach ($product['option'] as $option) {
                    $extraOption .=$option['value'];

                    if (isset($extraOption) && $extraOption !== '') {
                        $product['name'].= ' ';
                        $product['name'].= $extraOption;
                    }
                }

            $this->requestData['Articles'][] = [
                'quantity' => $product['quantity'],
                'title' => $product['name'],
                'artnr' => $product['model'],
                'aprice' => $this->toCents($prices['unit_price']),
                'taxrate' => $this->calculateGroupRate($prices),
                'discount' => 0,
                'withouttax' => $this->toCents($prices['total_without_tax']),
                'total_article' => $this->toCents($prices['total_with_tax']),
                'product_id' => $product['product_id'],
                'tax_class_id' => $product['tax_class_id'],
                'reward' => $product['reward'],
                'points' => $product['points'],
                'subtract' => $product['subtract'],
                'option' => json_encode($product['option']),
                'download' => json_encode($product['download']),
            ];

}
        return $this;
    }

    /**
     * @return $this
     */
    protected function addDiscountData()
    {
        if (isset($this->session->data['coupon'])) {
            $couponCode = $this->session->data['coupon'];
            $couponDiscount = $this->model_extension_total_coupon->getCoupon($couponCode);

            $discountAmount = $this->getDiscountAmount($couponDiscount);
            $this->requestData['Articles'][] = [
                'quantity' => 1,
                'title' => $couponDiscount['name'],
                'artnr' => 'discount-item',
                'aprice' => $this->toCents(-$discountAmount),
                'taxrate' => 0,
                'discount' => 0,
                'withouttax' => $this->toCents(-$discountAmount),
                'total_article' => $this->toCents(-$discountAmount),
            ];
        }
        return $this;
    }

    /**
     * @return $this
     */
    protected function addCartTotalsData()
    {
        $cartTotals = $this->getCartTotals();
        $rounding = 0.0;
        $invoiceFeeData = $this->handleInvoiceFee($cartTotals);

        $this->requestData['Cart'] = [
            'Shipping' => [
                'withouttax' => $this->toCents($cartTotals['total_shipping']),
                'taxrate' => $cartTotals['shipping_rate'],
                'method' => $this->getShippingMethodName(),
                'method_code' => $this->getShippingMethodCode()
            ],
            'Handling' => [
                'withouttax' => $this->toCents($invoiceFeeData['fee_without_tax']),
                'taxrate'    => $invoiceFeeData['fee_tax_rate']
            ],
            'Total' => [
                'withouttax' => $this->toCents($cartTotals['total_without_tax']),
                'sub_total' => $this->toCents($cartTotals['sub_total']),
                'tax' => $this->toCents($cartTotals['total_tax']),
                'rounding' => $rounding,
                'withtax' => $this->toCents($cartTotals['total_with_tax']),
            ]
        ];
        return $this;
    }

    /**
     * @return array
     */
    protected function getCartTotals()
    {
        $shippingPrice = 0;
        $cartTotals = [];
        $cartTotals['shipping_rate'] = 0;
        $cartTotals['total_tax'] = 0;

        $total_data = $this->getTotalData();
        $cartHasShipping = $this->getBmCartModel()->hasShipping();

        if (isset($this->session->data['shipping_method']) && $cartHasShipping) {
            $shippingWithTax = $this->tax->calculate(
                $this->session->data['shipping_method']['cost'],
                $this->session->data['shipping_method']['tax_class_id']
            );
            $shippingPrice = $this->session->data['shipping_method']['cost'];
            if ($shippingPrice) {
                $cartTotals['shipping_rate'] = ($shippingWithTax / $shippingPrice) * 100;
                $cartTotals['shipping_rate']  = substr($cartTotals['shipping_rate'], 1);
            }
        }

        foreach ($total_data['taxes'] as $_tax) {
            $cartTotals['total_tax'] += $this->convert($_tax);
        }

        $subtotal = $this->cart->getSubTotal();

        $cartTotals['total_shipping'] = $this->convert($shippingPrice);
        $cartTotals['total_without_tax'] = $this->convert(
            $subtotal + $shippingPrice - $this->discountAmount
        );
        $cartTotals['total_with_tax'] = $this->convert($total_data['total']);
        $cartTotals['sub_total'] = $this->convert($subtotal);

        return $cartTotals;
    }

    /**
     * @return array
     */
    protected function getTotalData()
    {
        $totals = [];
        $taxes = $this->cart->getTaxes();
        $total = 0;
        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

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

        return $total_data;
    }

    /**
     * @param $cartTotals
     *
     * @return array
     */
    protected function handleInvoiceFee(&$cartTotals)
    {
        $invoiceFeeData = $this->model_billmate_checkout_handling_invoice_fee->getData();
        $cartTotals['total_without_tax'] += $invoiceFeeData['fee_without_tax'];
        $cartTotals['total_tax'] += $invoiceFeeData['fee_tax_value'];
        $cartTotals['total_with_tax'] += $invoiceFeeData['fee_tax_value'] + $invoiceFeeData['fee_without_tax'];
        return $invoiceFeeData;
    }

    /**
     * @return array
     */
    protected function getRequestData()
    {
        return $this->requestData;
    }

    /**
     * @param $product
     */
    protected function getProductPrices($product)
    {
        $productPrices = [];
        $unit_price = $this->tax->calculate(
            $product['price'],
            $product['tax_class_id'],
            $this->config->get('config_tax')
        );

        $convertedPrice = $this->convert($product['quantity'] * $product['price']);
        $productPrices['total_without_tax'] = $convertedPrice;

        $productPrices['unit_price'] = $this->convert($product['price']);
        $productPrices['total_with_tax'] = $product['quantity'] * $unit_price;
        return $productPrices;
    }

    /**
     * @param $prices
     *
     * @return false|float
     */
    protected function calculateGroupRate($prices)
    {
        return round(
            (($prices['total_with_tax'] - $prices['total_without_tax'])/$prices['total_without_tax'])*100,
            2
        );
    }

    /**
     * @param $couponCode
     *
     * @return float|int
     */
    protected function getDiscountAmount($couponDiscount)
    {
        $subTotal = $this->cart->getSubTotal();
        $discountAmount = 0;
        switch ($couponDiscount['type']) {
            case 'P':
                $discountAmount = ($subTotal/100) * $couponDiscount['discount'];
                break;
            case 'F':
                $discountAmount = $couponDiscount['discount'];
                break;
        }
        $this->discountAmount = $discountAmount;
        return $this->convert($discountAmount);
    }

    /**
     * @param $value
     *
     * @return int
     */
    protected function toCents($value)
    {
        return (int)((string)((float)($value * 100)));
    }

    /**
     * @param $amount
     *
     * @return float
     */
    protected function convert($amount)
    {
        return $this->currency->format(
            $amount,
            $this->session->data['currency'],
            '',
            false
        );
    }

    /**
     * @return string
     */
    protected function generateBillmateOrderId()
    {
        return $this->getBmCartModel()->getCartIdentifier(
            $this->getCurrentSessionId()
        );

    }

    /**
     * @return string
     */
    protected function getCurrentSessionId()
    {
        return $this->session->getId();
    }

    /**
     * @return string
     */
    protected function getShippingMethodCode()
    {
        if (isset($this->session->data['shipping_method']['code'])) {
           return $this->session->data['shipping_method']['code'];
        }
        return '';
    }

    /**
     * @return string
     */
    protected function getShippingMethodComment()
    {
        if (isset($this->session->data['shipping_method']['comment'])) {
            return $this->session->data['shipping_method']['comment'];
        }
        return '';
    }

    /**
     * @return string
     */
    public function getShippingMethodName()
    {
       if (isset($this->session->data['shipping_method']['title'])) {
           return $this->session->data['shipping_method']['title'];
       }
       return '';
    }

    /**
     * @return bool
     */
    protected function isUpdated()
    {
        return $this->isUpdated;
    }

    /**
     * @param $value
     *
     * @return $this
     */
    public function setIsUpdated($value)
    {
        $this->isUpdated = $value;
        return $this;
    }

    /**
     * @return \Billmate\Bmcart
     */
    public function getBmCartModel()
    {
        return $this->bmcart;
    }

    /**
     * @return HelperBillmate|Helperbm
     */
    public function getBmHelper()
    {
        return $this->helperBillmate;
    }
}
