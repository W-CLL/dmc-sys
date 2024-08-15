<?php

namespace baidu;
use Requests;
Class AccessToken
{
    public static function get_access_token($app_key, $secret)
    {
        $data = array(
            "grant_type" => "client_credentials",
            "client_id" => $app_key,
            "client_secret" => $secret,
        );
        $url = "https://aip.baidubce.com/oauth/2.0/token";
        return Requests::post($url, $data);
    }
}

