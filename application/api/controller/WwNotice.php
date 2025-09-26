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
    public function sendAutoOrderMsg($auth, $msg = '测试不用管',$user="")
    {
        $base_user = "WuZhongTuan|PanHaoWei|WangChunLong";
        if ($auth != "auto-order") {
            $this->error('非法请求');
        }
        if($user){
            $touser = $base_user.'|'.$user;
        }else{
            $touser = $base_user;
        }

        \qywx\Api::send_application_messages($touser, $msg);

    }

}
