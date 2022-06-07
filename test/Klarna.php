<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 13:55:07 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-06 20:54:36
 */

namespace Netflying\KlarnaTest;

use Netflying\Payment\common\Utils;
use Netflying\PaymentTest\Data;

use Netflying\Klarna\data\Merchant;

class Klarna
{

    protected $url = '';

    public $type = 'Klarna';

    protected $merchant = [];

    /**
     * @param $url 回调通知等相对路径
     *
     * @param string $url 站点回调通知相对路径
     */
    public function __construct($url='')
    {
        $this->url = $url;
    }

    /**
     * 商家数据结构
     *
     * @return this
     */
    public function setMerchant(array $realMerchant = [])
    {
        $url = $this->url . '?type=' . $this->type;
        $returnUrl = $url .'&act=return_url&async=0&sn={$sn}';
        $notifyUrl = $url.'&act=notify_url&async=0&sn={$sn}';
        // $liveApiUrl = [
        //     'eu' => "https://api.klarna.com/",
        //     'na' => "https://api-na.klarna.com/",
        //     'oc' => "https://api-oc.klarna.com/",
        // ];
        // $testApiUrl = [
        //     'eu' => "https://api.playground.klarna.com/",
        //     'na' => "https://api-na.playground.klarna.com/",
        //     'oc' => "https://api-oc.playground.klarna.com/",
        // ];
        $merchant = [
            'type' => $this->type,
            'is_test' => 1,
            'merchant' => '****',
            'api_account' => [
                'username' => '*****',
                'password' => '*****',
            ],
            'api_data' => [
                'endpoint_domain' => 'https://api.playground.klarna.com',
                'endpoint'   => '/payments/v1/authorizations/{$token}/order',
                'client_session_url' => '/payments/v1/sessions',
                'capture_url' => '/ordermanagement/v1/orders/{$id}/captures',
                'orders_url' => '/ordermanagement/v1/orders/{$id}',
                'authorization_token' => null,
                'return_url' => $returnUrl,
                'notify_url' => $notifyUrl,
            ]
        ];
        $merchant = Utils::arrayMerge($merchant,$realMerchant);
        $this->merchant = $merchant;
        return $this;
    }

    /**
     * 提交支付
     *
     * @return Redirect
     */
    public function pay()
    {
        $Data = new Data;
        $Order = $Data->order();
        $Log = new Log;
        $Merchant = new Merchant($this->merchant);
        $class = "Netflying\\Klarna\\lib\\".$this->type;
        $Payment = new $class($Merchant);
        $redirect = $Payment->log($Log)->purchase($Order);
        return $redirect;
    }


}
