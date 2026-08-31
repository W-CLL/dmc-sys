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
    public function sendMsg($auth, $msg = '测试不用管',$user="")
    {
//        $base_user = "WangChunLong";
        if ($auth != "dmc-company-name-log") {
            $this->error('非法请求');
        }
        if($user){
//            $touser = $base_user.'|'.$user;
            $touser = $user;
        }else{
//            $touser = $base_user;
            $touser = $user;
        }

        \qywx\Api::send_application_messages($touser, $msg);

    }

}
