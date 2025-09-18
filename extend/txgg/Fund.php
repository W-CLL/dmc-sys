<?php

namespace txgg;

class Fund extends Base
{
    // 转账测试账号：67475647

    public static function getFundAccountInfo($params){
        self::initCommonParams();
        $commonParams = is_array(self::$common_params) ? self::$common_params : [];
        $params = array_merge($commonParams, $params);
        $url = self::$url . 'funds/get';
        return sendApiRes($url, $params);
    }

    public static function transfer($params){
        self::initCommonParams();
        $commonParams = is_array(self::$common_params) ? self::$common_params : [];
        $url = self::$url . 'fund_transfer/add';
        $url .= '?' . http_build_query($commonParams);
        return sendApiRes($url, $params, 'POST');
    }

    public static function getShareWalletInfo($params){
        self::initCommonParams();
        $commonParams = is_array(self::$common_params) ? self::$common_params : [];
        $params = array_merge($commonParams, $params);
        $url = self::$url . 'agency_wallet_list/get';
        return sendApiRes($url, $params);
    }
}