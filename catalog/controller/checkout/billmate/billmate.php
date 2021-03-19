<?php

class ControllerCheckoutBillmateBillmate extends Controller
{
    public function __construct($registry)
    {
        parent::__construct($registry);

        $this->billmate  = new Helperbm($registry);
    }

    public function accept()
    {
        if ($this->request->server['REQUEST_METHOD'] != 'POST') {
            return $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }

        $this->load->language('checkout/billmate/accept');

        $this->load->model('checkout/billmate/order');

        if (!$request = $this->getRequestBody()) {
            $this->log->write($this->language->get('error_no_request'));

            return $this->failure();
        }

        $this->writeToCustomLog($request);

        if (!$connection = $this->getConnection()) {
            $this->log->write($this->language->get('error_no_connection'));

            return $this->failure();
        }

        if (!$payment_data = $this->getPaymentInfo($connection, $request['data']['number'])) {
            $this->log->write($this->language->get('error_no_payment'));

            return $this->failure();
        }

        if (!$this->cart->hasProducts()) {
            $this->log->write($this->language->get('error_no_session'));

            return $this->failure();
        }

        if (!$order = $this->model_checkout_billmate_order->createOrder($payment_data)) {
            $this->log->write($this->language->get('error_create_order'));

            return $this->failure();
        }

        return $this->success();
    }

    public function success()
    {
        return $this->response->redirect($this->url->link('checkout/billmate/success', '', true));
    }

    public function failure() {
        return $this->response->redirect($this->url->link('checkout/billmate/failure', '', true));
    }

    public function callback()
    {
        if ($this->request->server['REQUEST_METHOD'] != 'POST') {
            return $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }

        $this->load->language('checkout/billmate/callback');

        $this->load->model('checkout/order');
        $this->load->model('checkout/billmate/cart');
        $this->load->model('checkout/billmate/order');

        if (!$request = $this->getRequestBody()) {
            return $this->halt($this->language->get('error_no_request'));
        }

        $this->writeToCustomLog($request);

        if (!$connection = $this->getConnection()) {
            return $this->halt($this->language->get('error_no_connection'));
        }

        if (!$payment_data = $this->getPaymentInfo($connection, $request['data']['number'])) {
            return $this->halt($this->language->get('error_no_payment'));
        }

        if (empty($payment_data['PaymentInfo']['real_order_id'])) {
            return $this->halt($this->language->get('error_no_order_id'));
        }

        if (!$order = $this->model_checkout_order->getOrder($payment_data['PaymentInfo']['real_order_id'])) {
            return $this->halt($this->language->get('error_no_order'));
        }

        try {
            switch ($payment_data['PaymentData']['status']) {
                case 'Paid':
                    $order_status = 2;
                    break;

                case 'Cancelled':
                    $order_status = 7;
                    break;

                case 'Created':
                    $order_status = 1;
                    break;

                default:
                    $order_status = $this->config->get('payment_billmate_checkout_order_status_id');
                    break;
            }

            $this->model_checkout_order->addOrderHistory($order['id'], $order_status);
        } catch (Exception $e) {
            return $this->halt($this->language->get('error_order_history'));
        }

        try {
            $this->model_checkout_billmate_order->addInvoice(
                $order['id'],
                $request['data']['number']
            );
        } catch (Exception $e) {
            return $this->halt($this->language->get('error_add_invoice'));
        }

        if (!$this->updatePaymentData($connection, $order['id'], $request['data']['number'])) {
            return $this->halt($this->language->get('error_update_payment'));
        }

        $this->model_checkout_billmate_cart->clearCustomCart($payment_data['PaymentData']['orderid']);

        http_response_code(200);
        exit;
    }

    private function halt($message)
    {
        $this->log->write($message);

        http_response_code(500);
        exit($message);
    }

    private function getConnection()
    {
        try {
            return $this->billmate->getBillmateConnection();
        } catch (Exception $e) {
            return null;
        }
    }

    private function getPaymentInfo($connection, $number)
    {
        try {
            return $connection->getPaymentinfo([
                'number' => $number,
            ]);
        } catch (Exception $e) {
            return null;
        }
    }

    private function updatePaymentData($connection, $order_id, $payment_number)
    {
        try {
            return $connection->updatePayment([
                'PaymentInfo' => [
                    'real_order_id' => $order_id,
                ],
                'PaymentData' => [
                    'number'  => $payment_number,
                    'orderid' => $order_id,
                ],
            ]);
        } catch (Exception $e) {
            return null;
        }
    }

    private function getRequestBody()
    {
        if (!empty($this->request->request['data']) && !empty($this->request->request['credentials'])) {
            return [
                'data'        => json_decode($this->request->request['data'], true),
                'credentials' => json_decode($this->request->request['credentials'], true),
            ];
        }

        try {
            return json_decode(file_get_contents('php://input'));
        } catch (Exception $e) {
            return null;
        }
    }

    private function writeToCustomLog($data)
    {
        if (!$this->config->get('payment_billmate_checkout_log_enabled')) {
            return;
        }

        $customLog = new Log('billmate.log');
        $customLog->write($data);

        return true;
    }
}
