<?php

namespace txgg;

class AdvInfo extends Base
{
    public static function getAdvInfo($params){
        self::initCommonParams(); // 确保参数已初始化
        $commonParams = is_array(self::$common_params) ? self::$common_params : [];
        $params = array_merge($commonParams, $params);
        $url = self::$url . 'advertiser/get';
        return sendApiRes($url, $params);
    }
}