<?php


namespace jlqc;

use Requests;
class AccountRelationship
{
    //获取已授权的账户（店铺/代理商）
    public static function shop($app_id,$secret,$access_token){

        $url = "https://ad.oceanengine.com/open_api/oauth2/advertiser/get?access_token=".$access_token;
        return Requests::get($url);
    }

    //获取代理商账户关联的广告账户列表
    public static function advertiser_select($access_token,$advertiser_id,$page=1,$page_size=10){
        $url = "https://ad.oceanengine.com/open_api/2/agent/advertiser/select?advertiser_id=".$advertiser_id."&page=".$page."&page_size=".$page_size;

        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }


}













