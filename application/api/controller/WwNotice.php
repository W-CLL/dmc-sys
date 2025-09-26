<?php

namespace app\api\controller;

use app\common\controller\Api;
use think\Cache;
use think\Controller;

/**
 * 示例接口
 */
class WwNotice extends Controller
{
    public function sendAutoOrderMsg($auth, $msg = '测试不用管')
    {
//        dump($auth);
        if ($auth != "auto-order") {
            $this->error('非法请求');
        }
        \qywx\Api::send_application_messages('WangChunLong|PaoHaoWei', $msg);


    }

}
