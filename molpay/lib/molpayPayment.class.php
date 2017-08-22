<?php

/**
 *
 * @author Webasyst_shop
 * @name MOLPay
 * @description MOLPay Payments Standard Integration
 *
 * Plugin settings parameters must be specified in file lib/config/settings.php
 */
class molpayPayment extends waPayment implements waIPayment
{
    /**
     * @var string
     */
    private $order_id;

    /**
     * Returns array of ISO3 codes of enabled currencies (from settings) supported by payment gateway.
     *
     * @return string[]
     */
    public function allowedCurrency()
    {
        return array_keys(array_filter($this->currency));
    }

    /**
     * Returns array of transaction operations supported by payment gateway.
     *
     * See available list of operation types as OPERATION_*** constants of waPayment.
     * @return array
     */
    public function supportedOperations()
    {
        return array(
            self::OPERATION_AUTH_CAPTURE,
        );
    }

    /**
     * Returns array or currency selection options for plugin settings.
     *
     * @see waPayment::settingCurrencySelect
     * @return array
     */
    public static function settingCurrencySelect()
    {
        $options = parent::settingCurrencySelect();
        /**
         * Currencies supported by MOLPay
         */
        $allowed = array(
            'USD', //U.S. Dollar
            'AUD', //Australian Dollar
            'SGD', //Singapore Dollar
            'MYR', //Malaysian Ringgit (only for Malaysian members)
            'PHP', //Philippine Peso
            'THB', //Thai Baht
            'IDR', //Indonesia
            'VND', //Vietnam            
        );

        /**
         * Filtering available currencies to leave only those supported by payment gateway
         */
        foreach ($options as $code => $option) {
            if (!in_array($code, $allowed)) {
                unset($options[$code]);
            }
        }
        return $options;

    }

    /**
     * Generates payment form HTML code.
     *
     * Payment form can be displayed during checkout or on order-viewing page.
     * Form "action" URL can be that of the payment gateway or of the current page (empty URL).
     * In the latter case, submitted data are passed again to this method for processing, if needed;
     * e.g., verification, saving, forwarding to payment gateway, etc.
     * @param array $payment_form_data Array of POST request data received from payment form
     * (if no "action" URL is specified for the form)
     * @param waOrder $order_data Object containing all available order-related information
     * @param bool $auto_submit Whether payment form data must be automatically submitted (useful during checkout)
     * @return string Payment form HTML
     * @throws waException
     */
    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        // using order wrapper class to ensure use of correct data object
        $order = waOrder::factory($order_data);

        // verifying order currency support
        if (!in_array($order->currency, $this->allowedCurrency())) {
            throw new waException('Unsupported currency');
        }

        $merchant_id = $this->merchantID;
        $verify_key = $this->verifyKey;
        
        // adding all necessary form fields as required by MOLPay
        $amount = number_format($order->total, 2, '.', '');
        $orderid = $this->app_id.'_'.$this->merchant_id.'_'.$order->id;
        $vcode = md5($amount.$merchant_id.$orderid.$verify_key);
        
        $hidden_fields = array(
            'merchant_id'	=> $merchant_id,
            'amount'		=> $amount,
            'orderid'		=> $orderid,
            'bill_name'		=> $order->getContact()->getName(),
            'bill_email'	=> $order->getContact()->get('email', 'default'),
            'bill_mobile'	=> $order->getContact()->get('phone', 'default'),
            'bill_desc'		=> str_replace(array('“', '”', '«', '»'), '"', $order->description),
            'country'		=> "MY",
            'vcode'		=> $vcode,
            'returnurl'        	=> $this->getRelayUrl(),
        );

        $view = wa()->getView();

        $view->assign(
            array(
                'url'           => wa()->getRootUrl(),
                'hidden_fields' => $hidden_fields,
                'form_url'      => $this->getEndpointUrl(),
                'auto_submit'   => $auto_submit,
                'plugin'        => $this,
            )
        );
        
        return $view->fetch($this->path.'/templates/payment.html');
    }

    /**
     * Plugin initialization for processing callbacks received from payment gateway.
     *
     * To process callback URLs of the form /payments.php/molpal/*,
     * corresponding app and id must be determined for correct initialization of plugin settings.
     * @param array $request Request data array ($_REQUEST)
     * @return waPayment
     * @throws waPaymentException
     */
     
    protected function callbackInit($request)
    {
        // parsing data to obtain order id as well as ids of corresponding app and plugin setup instance responsible
        // for callback processing
        if (isset($request['orderid']) && preg_match('/^(.+)_(.+)_(.+)$/', $request['orderid'], $match)) {
            $this->app_id = $match[1];
            $this->merchant_id = $match[2];
            $this->order_id = $match[3];
        } else {
            throw new waPaymentException('Invalid invoice number');
        }
        
        // calling parent's method to continue plugin initialization
        return parent::callbackInit($request);
    }

    /**
     * Actual processing of callbacks from payment gateway.
     *
     * Request parameters are checked and app's callback handler is called, if necessary.
     * Plugin settings are already initialized and available.
     * IPN (Instant Payment Notification)
     * @throws waPaymentException
     * @param array $request Request data array ($_REQUEST) received from gateway
     * @return array Associative array of optional callback processing result parameters:
     *     'redirect' => URL to redirect user upon callback processing
     *     'template' => path to template to be used for generation of HTML page displaying callback processing results;
     *                   false if direct output is used
     *                   if not specified, default template displaying message 'OK' is used
     *     'header'   => associative array of HTTP headers ('header name' => 'header value') to be sent to user's
     *                   browser upon callback processing, useful for cases when charset and/or content type are
     *                   different from UTF-8 and text/html
     *
     *     If a template is used, returned result is accessible in template source code via $result variable,
     *     and method's parameters via $params variable
     */
    protected function callbackHandler($request)
    {
        // verifying that order id was received within callback
        if (!$request['orderid']) {
            throw new waPaymentException('Invalid invoice numbers');
        }

        // verifying that plugin's essential settings values have been read and plugin has been correctly initialized
        if (!$request['tranID']) {
            throw new waPaymentException('Empty merchant datas');
        }
        
        $transaction_data = $this->formalizeData($request);
        $tm = new waTransactionModel();
       
        $res = $tm->getByFields(
            array(
                'native_id' => $transaction_data['orderid'],
                'plugin'    => $this->id,
            )
        );
        
        $vkey = $this->verifyKey;
        /********************************
        *Don't change below parameters
        ********************************/
        $nbcb       =    $_POST['nbcb'];
        $tranID     =    $_POST['tranID'];
        $orderid    =    $_POST['orderid'];
        $status     =    $_POST['status'];
        $merchant   =    $_POST['domain'];
        $amount     =    $_POST['amount'];
        $currency   =    $_POST['currency'];
        $appcode    =    $_POST['appcode'];
        $paydate    =    $_POST['paydate'];
        $skey       =    $_POST['skey']; //Security hashstring returned by MOLPay
           
        if ($nbcb==1) {
           //callback IPN feedback to notified MOLPay
             echo "CBTOKEN:MPSTATOK"; exit;
        }else{
             $_POST[treq]    =    1; // Additional parameter for IPN

            /***********************************************************
            * Snippet code in purple color is the enhancement required
            * by merchant to add into their return script in order to
            * implement backend acknowledge method for IPN
            ************************************************************/
            while ( list($k,$v) = each($_POST) ) {
                $postData[]= $k."=".$v;
                }
                $postdata        = implode("&",$postData);
                $url             = "https://www.onlinepayment.com.my/MOLPay/API/chkstat/returnipn.php";
                $ch              = curl_init();
                curl_setopt($ch, CURLOPT_POST                   , 1         );
                curl_setopt($ch, CURLOPT_POSTFIELDS             , $postdata );
                curl_setopt($ch, CURLOPT_URL                    , $url      );
                curl_setopt($ch, CURLOPT_HEADER               , 1         );
                curl_setopt($ch, CURLINFO_HEADER_OUT            , TRUE      );
                curl_setopt($ch, CURLOPT_RETURNTRANSFER         , 1         );
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER         , FALSE     );
                curl_setopt($ch, CURLOPT_SSLVERSION           , 6         );  // use only TLSv1.2
                $result = curl_exec( $ch );
                curl_close( $ch );
        }


        /***********************************************************
        * To verify the data integrity sending by MOLPay
        ************************************************************/
        $key0 = md5($tranID.$orderid.$status.$merchant.$amount.$currency);
        $key1 = md5($paydate.$merchant.$key0.$appcode.$vkey);
        //key1 : Hashstring generated on Merchant system 
        // either $merchant or $domain could be one from POST
        // and one that predefined internally 
        // by right both values should be identical
        if( $skey === $key1 ){
            if ($request['status'] == "00")
            {                                
                $transaction_data['state'] = self::STATE_CAPTURED;
                $r_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $order);
                $transaction_data = $this->saveTransaction($transaction_data, $request);
                $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
            }
            else if($request['status'] == "11")
            {
                $transaction_data['state'] = self::STATE_DECLINED;
                $r_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_DECLINE, $order);
            }
            else if($request['status'] == "22")
            {
                $transaction_data['state'] = self::STATE_AUTH;
                $r_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $order);
            }
        } elseif( $skey != $key1 ){
             $transaction_data['state'] = self::STATE_DECLINED;
             $r_url = $this->getAdapter()->getBackUrl(waAppPayment::URL_DECLINE, $order);
        }        
        if (!empty($result['error'])) {
            throw new waPaymentException(
                'Forbidden (validate error): '.$result['error']
            );
        }

        wa()
            ->getResponse()
            ->addHeader('Content-Type', 'application/json', true);
        echo json_encode(array('code' => 0));      

        header("Location:".$r_url);
                
        return array(
            'template' => false, // this plugin generates response without using a template
        );
    }

    /**
     * Converts raw transaction data received from payment gateway to acceptable format.
     *
     * @param array $request Raw transaction data
     * @return array $transaction_data Formalized data
     */
    protected function formalizeData($request)
    {
        // obtaining basic request information
        $transaction_data = parent::formalizeData(null);

        // adding various data:
        // transaction id assigned by payment gateway
        $transaction_data['native_id'] = ifset($request['orderid']);
        // amount
        $transaction_data['amount'] = ifset($request['amount']);
        // currency code
        $transaction_data['currency_id'] = ifset($request['currency']);

        $transaction_data['type'] = self::OPERATION_AUTH_ONLY;
        $transaction_data['result'] = 1;
        $transaction_data['order_id'] = $this->order_id;
        $transaction_data['view_data'] = implode("\n", $request);

        return $transaction_data;
    }

    /**
     * @return string Payment gateway's callback URL
     */
    private function getEndpointUrl()
    {        
        return 'https://www.onlinepayment.com.my/MOLPay/pay/'.$this->merchantID.'/';
    }

    /**
     * Requests current transaction status from payment gateway.
     *
     * @throws waException
     * @param array $data Transaction data
     * @return string Response received from payment gateway
     */
    private function notifyValidate($data)
    {
        $data = array_merge(array('cmd' => '_notify-validate'), $data);
        unset($data['result']);
        $app_error = $response = null;

        //check available PHP extension
        if (!extension_loaded('curl') || !function_exists('curl_init')) {
            throw new waException('PHP extension cURL not available');
        }

        //try to init cUrl
        if (!($ch = curl_init())) {
            throw new waException('curl init error');
        }

        if (curl_errno($ch) != 0) {
            throw new waException('curl init error: '.curl_errno($ch));
        }

        $url = $this->getEndpointUrl();

        $host = parse_url($url, PHP_URL_HOST);

        $headers = array(
            'Connection: close',
            'Host: '.$host,
        );

        @curl_setopt($ch, CURLOPT_URL, $url);
        @curl_setopt($ch, CURLOPT_POST, 1);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        @curl_setopt($ch, CURLOPT_USERAGENT, sprintf('Webasyst %s plugin (%s)', $this->id, $host));
        @curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        @curl_setopt($ch, CURLE_OPERATION_TIMEOUTED, 120);

        $response = @curl_exec($ch);
        if (curl_errno($ch) != 0) {
            $app_error = 'curl error: '.curl_errno($ch);
        }
        curl_close($ch);
        if ($app_error) {
            throw new waException($app_error);
        }
        if (empty($response)) {
            throw new waException('Empty server response');
        }
        return $response;
    }

    private function getUniqueTransaction($transaction_data)
    {
        $transaction_model = new waTransactionModel();
        return $transaction_model->getByFields(
            array(
                'plugin'      => $this->id,
                'app_id'      => $this->app_id,
                'merchant_id' => $this->merchant_id,
                'native_id'   => $transaction_data['native_id']
            )
        );
    }
}
