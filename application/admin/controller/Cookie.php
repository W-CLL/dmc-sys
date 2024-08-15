<?php

namespace app\admin\controller;


use app\common\controller\Backend;
use think\Cache;
use think\Db;

Class Cookie extends Backend{

    public function index()
    {

        if ($this->request->isPost()) {
            $cookie = input("cookie");
            if (!empty($cookie)){
                Cache::store("redis")->set("jlfz_cookie",$cookie);
                $this->success();
            }
            $this->error("请输入正确内容");
        }
        $cookie = Cache::store("redis")->get("jlfz_cookie");
        $this->assign("cookie",$cookie);
        return $this->view->fetch();


    }

}

