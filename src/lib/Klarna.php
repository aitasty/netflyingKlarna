<?php

namespace Netflying\Klarna\lib;

use Exception;

use Netflying\Payment\common\Utils;
use Netflying\Payment\common\Request as Rt;
use Netflying\Payment\lib\PayInterface;
use Netflying\Payment\lib\Request;

use Netflying\Payment\data\Merchant;
use Netflying\Payment\data\Order;
use Netflying\Payment\data\Redirect;
use Netflying\Payment\data\OrderPayment;
use Netflying\Payment\data\RequestCreate;


class Klarna extends PayInterface
{
    protected $merchant = null;
    //日志对象
    protected $log = '';
    //创建是否自动捕获
    protected $autoCaptrue = false;
    //设置订单运费名称
    protected $shippingTitle = '';
    //有效订单状态
    protected static $validArr = [
        'accepted',
        'authorized',
        'captured',
        'part_captured'
    ];

    public function __construct(Merchant $Merchant, $log = '')
    {
        $this->merchant($Merchant);
        $this->log($log);
    }
    /**
     * 初始化商户
     * @param Merchant $Merchant
     * @return self
     */
    public function merchant(Merchant $Merchant)
    {
        $this->merchant = $Merchant;
        return $this;
    }
    public function merchantUrl(Order $order)
    {
        $sn = $order['sn'];
        $merchant = $this->merchant;
        $apiData = $merchant['api_data'];
        $sn = $order['sn'];
        $urlReplace = function ($val) use ($sn) {
            return str_replace('{$sn}', $sn, $val);
        };
        $urlData = Utils::modeData([
            'return_url' => '',
            'notify_url' => '',
        ], $apiData, [
            'return_url' => $urlReplace,
            'notify_url' => $urlReplace,
        ]);
        $apiData = array_merge($apiData, $urlData);
        $merchant->setApiData($apiData);
        $this->merchant = $merchant;
        return $this;
    }
    /**
     * 日志对象
     */
    public function log($Log = '')
    {
        $this->log = $Log;
        return $this;
    }
    public function getAutoCapture()
    {
        return $this->autoCaptrue;
    }
    public function setAutoCaption(bool $auto)
    {
        $this->autoCaptrue = $auto;
        return $this;
    }
    public function getShippingTitle()
    {
        return $this->shippingTitle;
    }
    public function setShippingTitle(string $title)
    {
        $this->shippingTitle = $title;
        return $this;
    }
    public function purchase(Order $Order): Redirect
    {
        $this->merchantUrl($Order);
        $apiData = $this->merchant['api_data'];
        $token = $apiData['authorization_token'];
        $url = str_replace('{$token}', $token, $apiData['endpoint_domain'] . $apiData['endpoint']);
        $orderData = $this->orderData($Order);
        $orderData['merchant_urls'] = Utils::mapData([
            'confirmation' => '',
            'notification' => '',
            'push' => '',
        ], $apiData, [
            'confirmation' => 'return_url',
            'notification' => 'notify_url',
            'push' => 'notify_url'
        ]);
        $orderData['billing_address'] = $this->billingData($Order);
        $orderData['shipping_address'] = $this->shippingData($Order);
        $res = $this->request($url, $orderData);
        if ($res['status'] != '200') {
            return $this->errorRedirect();
        }
        $rs = Utils::mapData([
            'url' => '',
            'order_id' => ''
        ], $res['body'], [
            'url' => 'redirect_url'
        ]);
        return $this->toRedirect($rs['url'], ['order_id' => $rs['order_id']]);
    }

    /**
     * 获取客户端会话ID与初始化client_token
     *
     * @return string
     */
    public function clientToken(Order $Order)
    {
        $apiData = $this->merchant['api_data'];
        $url = $apiData['endpoint_domain'] . $apiData['client_session_url'];
        $orderData = $this->orderData($Order);
        $res = $this->request($url, $orderData);
        $token = '';
        if ($res['code'] == '200') {
            $arr = Utils::mapData(['client_token' => ''], json_decode($res['body'], true));
            $token = $arr['client_token'];
        }
        return $token;
    }

    /**
     * 异步通知回调
     */
    public function notify()
    {
        $data = Utils::mapData([
            'sn' => '',
            'order_id' => '', //通道订单id
        ], Rt::receive());
        $server = $_SERVER;
        $key = isset($server['HTTP_KLARNA_IDEMPOTENCY_KEY']) ? $server['HTTP_KLARNA_IDEMPOTENCY_KEY'] : '';
        $orders = $this->orders($data['order_id'], $key);
        $payment = Utils::mapData([
            'sn' => $data['sn'],
            'status_descrip' => '',
            'currency' => '',
            'amount' => '',
            'refunded_amount' => '',
            'captured_amount' => '',
            'pay_id' => $data['order_id'],
            'pay_sn' => $data['order_id'],
        ], $orders, [
            'status_descrip' => 'status', //"AUTHORIZED","CAPTURED","PART_CAPTURED"
            'currency' => 'purchase_currency',
            'amount' => 'order_amount', //order_amount, captured_amount, refunded_amount
        ]);
        $status = 0;
        if (in_array(strtolower($payment['status_descrip']), self::$validArr)) {
            $status = 1;
        }
        if (!empty($payment['refunded_amount'])) {
            $status = -1;
        }
        $payment['status'] = $status;
        $payment['merchant'] = $this->merchant['merchant'];
        $payment['type'] = $this->merchant['type'];
        return new OrderPayment($payment);
    }

    /**
     * 捕获订单
     *
     * @param string $id 提交成功返回的order_id
     * @param string $amount 金额
     * @param string $idempotencyKey
     * @return void
     */
    public function capture($id, $amount, $idempotencyKey = '')
    {
        $amount = (int)$amount;
        if (empty($amount)) {
            return '';
        }
        $apiData = $this->merchant['api_data'];
        $url = str_replace('{$id}', $id, $apiData['capture_url']);
        $url = $apiData['endpoint_domain'] . $url;
        $header = [];
        if (!empty($idempotencyKey)) {
            $header['Klarna-Idempotency-Key'] = $idempotencyKey;
        }
        $res = $this->request($url, ['captured_amount' => $amount], $header);
        $arr = explode("\r\n", $res);
        $location = "";
        if (!empty($arr)) {
            foreach ($arr as $v) {
                $kVal = explode(': ', trim($v));
                $key = isset($kVal[0]) ? $kVal[0] : '';
                if ($key == 'location') {
                    $location = isset($kVal[1]) ? $kVal[1] : '';
                }
            }
        }
        return $location;
    }
    /**
     * 获取订单详情
     *
     * @param string $id 提交时成功返回的order_id
     * @param string $idempotencyKey 头部 HTTP_KLARNA_IDEMPOTENCY_KEY
     * @return array
     */
    public function orders($id, $idempotencyKey)
    {
        $apiData = $this->merchant['api_data'];
        $url = str_replace('{$id}', $id, $apiData['orders_url']);
        $url = $apiData['endpoint_domain'] . $url;
        $header = [];
        if (!empty($idempotencyKey)) {
            $header['Klarna-Idempotency-Key'] = $idempotencyKey;
        }
        $res = $this->request($url, "", $header);
        if ($res['code'] != '200') {
            return [];
        }
        return !empty($res['body']) ? json_decode($res['body'], true) : [];
    }

    /**
     * 货币支持国家及条件
     */
    public static function currencySupportCountry($currency)
    {
        if (empty($currency)) {
            return [];
        }
        $list = self::supportCountry();
        $data = [];
        foreach ($list as $v) {
            if (strtolower($v['currency']) == strtolower($currency)) {
                $data[] = $v;
            }
        }
        return $data;
    }
    /**
     * 支持的国家，及国家所对应的语言货币
     * @return array
     */
    public static function supportCountry()
    {
        $list = [
            'AU' => [ // Australia
                'country' => 'AU',
                'local'   => 'en-AU',
                'currency' => 'AUD',
                'location' => 'oc',
                'phone' => 1,
            ],
            'AT' => [ // Austria
                'country' => 'AT',
                'local'   => 'en-AT', // de-AT, en-AT
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'BE' => [ // Belgium
                'country' => 'BE',
                'local'   => 'nl-BE', //nl-BE, fr-BE
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'CA' => [ // Canada
                'country' => 'CA',
                'local'   => 'en-CA', //en-CA, fr-CA
                'currency' => 'CAD',
                'location' => 'na',
                'phone' => 1,
            ],
            'DK' => [ // Denmark
                'country' => 'DK',
                'local'   => 'da-DK', //da-DK, en-DK
                'currency' => 'DKK',
                'location' => 'eu',
                'phone' => 1,
            ],
            'FI' => [ // Finland
                'country' => 'FI',
                'local'   => 'fi-FI', //fi-FI, sv-FI, en-FI
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'FR' => [ // France
                'country' => 'FR',
                'local'   => 'fr-FR', //fr-FR, en-FR
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'DE' => [ // Germany
                'country' => 'DE',
                'local'   => 'de-DE', //de-DE, en-DE
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'IE' => [ // Ireland (Republic of Ireland)
                'country' => 'IE',
                'local'   => 'en-IE',
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'IT' => [ // Italy
                'country' => 'IT',
                'local'   => 'it-IT',
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'NL' => [ // Netherlands
                'country' => 'NL',
                'local'   => 'nl-NL', //nl-NL, en-NL
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 0,
            ],
            'NO' => [ // Norway
                'country' => 'NO',
                'local'   => 'nb-NO', //nb-NO, en-NO
                'currency' => 'NOK',
                'location' => 'eu',
                'phone' => 1,
            ],
            'PL' => [ // Poland
                'country' => 'PL',
                'local'   => 'pl-PL', //pl-PL, en-PL
                'currency' => 'PLN',
                'location' => 'eu',
                'phone' => 1,
            ],
            'PT' => [ // Portugal
                'country' => 'PT',
                'local'   => 'pt-PT', //pt-PT, en-PT
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'ES' => [ // Spain
                'country' => 'ES',
                'local'   => 'es-ES',
                'currency' => 'EUR',
                'location' => 'eu',
                'phone' => 1,
            ],
            'SE' => [ // Sweden
                'country' => 'SE',
                'local'   => 'sv-SE', //sv-SE, en-SE
                'currency' => 'SEK',
                'location' => 'eu',
                'phone' => 1,
            ],
            'CH' => [ // Switzerland
                'country' => 'CH',
                'local'   => 'de-CH', //de-CH, fr-CH, it-CH, en-CH
                'currency' => 'CHF',
                'location' => 'eu',
                'phone' => 0,
            ],
            'GB' => [ // United Kingdom	
                'country' => 'GB',
                'local'   => 'en-GB',
                'currency' => 'GBP',
                'location' => 'eu',
                'phone' => 1,
            ],
            'US' => [ // United States
                'country' => 'US',
                'local'   => 'en-US',
                'currency' => 'USD',
                'location' => 'na',
                'phone' => 1,
            ],
        ];
        return $list;
    }
    /**
     * 订单数据结构
     */
    protected function orderData(Order $Order)
    {
        $currencyData = self::currencySupportCountry($Order['currency']);
        $lines = $this->orderLinesData($Order);
        $data = Utils::mapData([
            'locale'      => $currencyData['locale'],
            'purchase_country'   => $currencyData['country'],
            'purchase_currency'  => $Order['currency'],
            'order_amount'     => $Order['purchase_amount'],
            'order_lines' => $lines
        ], []);
        return $data;
    }
    protected function orderLinesData(Order $Order)
    {
        $lines = [];
        foreach ($Order['products'] as $k => $v) {
            $line = Utils::mapData([
                'name'      => '',
                'quantity'     => 1,
                'total_amount' => 0,
                'unit_price'   => 0,
                'total_discount_amount' => 0,
                'total_tax_amount'  => 0,
                'tax_rate' => 0,
                'image_url' => '',
                'product_url' => '',
            ], $v);
            $totalAmount = Utils::calmul($line['unit_price'], $line['quantity']);
            $line['total_amount'] = $totalAmount - $line['total_discount_amount'];
            $line['tax_rate'] = Utils::caldiv($line['total_tax_amount'], $totalAmount);
            $lines[] = $line;
        }
        //shipping product
        if ($Order['freight'] > 0) {
            $line = Utils::mapData([
                'name'      => $this->shippingTitle,
                'quantity'     => 1,
                'total_amount' => $Order['freight'],
                'unit_price'   => $Order['freight'],
                'total_discount_amount' => 0,
                'total_tax_amount'  => 0,
                'tax_rate' => 0,
                'image_url' => '',
                'product_url' => '',
            ], []);
            $lines[] = $line;
        }
        return $lines;
    }

    protected function shippingData(Order $Order)
    {
        return $this->addressData($Order, 'shipping');
    }
    protected function billingData(Order $Order)
    {
        return $this->addressData($Order, 'billing');
    }
    /**
     * 地址数据模型
     *
     * @param Order $Order
     * @param string $type [shipping,billing]
     * @return void
     */
    protected function addressData(Order $Order, $type)
    {
        $address = $Order['address'][$type];
        $data = Utils::mapData([
            'given_name'      => '',
            'family_name'     => '',
            'email'           => '',
            'country'         => '',
            'region'          => '',
            'city'            => '',
            'postal_code'     => '',
            'street_address'  => '',
        ], $address, [
            'given_name' => 'first_name',
            'family_name' => 'last_name',
            'country' => 'country_code',
        ]);
        return $data;
    }

    /**
     * 错误请求结果
     *
     * @return Redirect
     */
    protected function errorRedirect()
    {
        return new Redirect([
            'status' => 0,
            'url' => '',
            'type' => 'get',
            'params' => [],
            'exception' => []
        ]);
    }
    protected function toRedirect($url, $data = [], $type = 'get')
    {
        return new Redirect([
            'status' => 1,
            'url' => $url,
            'type' => $type,
            'params' => $data,
            'exception' => []
        ]);
    }

    protected function authorizationBasic()
    {
        $apiAccount = $this->merchant['api_account'];
        return base64_encode($apiAccount['username'] . ':' . $apiAccount['password']);
    }

    protected function request($url, $data = [], array $header = [])
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $this->authorizationBasic(),
            //'http_errors' => 'false',
        ];
        $headers = array_merge($headers, ['User-Agent' => strval($userAgent)], $header);
        $post = json_encode($data);
        $res = Request::create(new RequestCreate([
            'type' => 'post',
            'url' => $url,
            'headers' => $headers,
            'data' => $post,
            'log' => $this->log
        ]));
        return $res;
    }
}
