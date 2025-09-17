<?php

namespace txgg;

use think\Env;

class Base
{
    protected static $url = 'https://api.e.qq.com/v3.0/';
    protected static $common_params = [];

    protected static function initCommonParams()
    {
        self::$common_params['access_token'] = Env::get('txgg.access_token');
        self::$common_params['timestamp'] = time();
        self::$common_params['nonce'] = uniqid();
    }
}