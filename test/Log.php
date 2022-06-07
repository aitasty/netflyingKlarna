<?php
/*
 * @Author: He.Bin 
 * @Date: 2022-05-31 16:07:25 
 * @Last Modified by: He.Bin
 * @Last Modified time: 2022-06-05 16:19:18
 */
namespace Netflying\KlarnaTest;
use Netflying\Payment\lib\LogInterface;

class Log implements LogInterface
{
    public function save($request,$response) 
    {
        $data = [
            'request' => $request,
            'response' => $response
        ];
        @file_put_contents(__DIR__.'/../../../../log/klarna.txt',json_encode($data));
    }
}