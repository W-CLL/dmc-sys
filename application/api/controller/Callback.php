<?php

namespace app\api\controller;

use app\common\controller\Api;
use think\log;

class Callback extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];
    
    public function callback_test(){
        echo '123';
        $data = input();
        Log::write($data,'notice');
    }

}