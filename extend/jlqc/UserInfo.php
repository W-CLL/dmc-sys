<?php
namespace jlqc;
use Requests;
Class UserInfo {

    //获取授权时登录用户信息
    public static function get_user_info($access_token){
        $url = "https://ad.oceanengine.com/open_api/2/user/info/";
        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }

    public static function public_info($access_token,$advertiser_ids){
        $url = "https://ad.oceanengine.com/open_api/2/advertiser/public_info?advertiser_ids=".$advertiser_ids;
        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }
}
