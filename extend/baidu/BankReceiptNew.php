<?php

namespace baidu;


use Requests;
use think\Cache;


Class BankReceiptNew
{
    public static function get_image_info($image){
        $access_token = Cache::get("baidu_access_token");
        $url = "https://aip.baidubce.com/rest/2.0/ocr/v1/bank_receipt_new?access_token=" . $access_token;
        $header = [
            'Content-Type'=>"application/x-www-form-urlencoded"
        ];
        $data = [
          "url"=>$image
        ];
        return Requests::post($url, $data,$header);
    }

}