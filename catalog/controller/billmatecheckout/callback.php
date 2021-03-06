<?php
require_once(DIR_APPLICATION . 'controller/billmatecheckout/CoreBmController.php');

class ControllerBillmatecheckoutCallback extends CoreBmController {

    const ERROR_RESPONSE_CODE = 401;

    /**
     * ControllerBillmatecheckoutAccept constructor.
     *
     * @param $registry
     */
    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->load->model('checkout/order');
        $this->load->model('billmate/order');
    }

    public function index()
    {
        $responseMessage = 'OK';
        try {
            $requestData = $this->getRequestData();
            if ($this->helperBillmate->isAddLog()) {
                $this->helperBillmate->log($requestData);
            }


            $paymentInfo = $this->helperBillmate
                ->getBillmateConnection()
                ->getPaymentinfo( [
                    'number' => $requestData['data']['number']
                ]);

                for( $i = 0; $i<10; $i++ ) {
                    if (!isset($paymentInfo['PaymentInfo']['real_order_id'])) {
                        $paymentInfo = $this->helperBillmate
                        ->getBillmateConnection()
                        ->getPaymentinfo( [
                            'number' => $requestData['data']['number']
                        ]);
                        break;
                    }
                }
                
            if (!isset($requestData['data']['number'])) {
                throw new Exception('Wait to finish accept order!');
            }
            $this->getBillmateOrderModel()->updateOrderStatus($paymentInfo, $requestData['data']['status']);

        } catch (\Exception $e) {
            http_response_code(self::ERROR_RESPONSE_CODE);
            $responseMessage = $e->getMessage();
        }

        $this->response->setOutput($responseMessage);
    }

    /**
     * @return ModelBillmateOrder
     */
    protected function getBillmateOrderModel()
    {
        return $this->model_billmate_order;
    }
}