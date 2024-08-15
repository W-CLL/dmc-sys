<?php

namespace jlqc;



use Requests;
use think\Cache;

Class AccessToken{
    public static function get_access_token($app_id,$secret,$auth_code){
        $data = array(
            "app_id"=>$app_id,
            "secret"=>$secret,
            "grant_type"=>"auth_code",
            "auth_code"=>$auth_code,
        );
        $url = "https://ad.oceanengine.com/open_api/oauth2/access_token/";
        return Requests::post($url,$data);
    }

    public static function refresh_token_save($app_id,$secret){
        $url = "https://api.oceanengine.com/open_api/oauth2/refresh_token/";
        $data = array(
            "app_id"=>$app_id,
            "secret"=>$secret,
            "grant_type"=>"refresh_token",
            "refresh_token"=>Cache::get("qc_refresh_token"),
        );

        return Requests::post($url,$data);
    }
}






