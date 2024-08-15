<?php

namespace app\admin\controller;


use app\common\controller\Backend;
use think\Db;
//废弃
Class RechargeLog extends Backend{

    public function _initialize()
    {
        parent::_initialize();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $list = Db::name("recharge_log")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("recharge_log")->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }

    public function auditing($ids = null)
    {
        if ($this->request->isPost()) {
            $status = input("status");
            if ($status == 1 || $status == 2){
                if (Db::name("recharge_log")->where(["id"=>$ids,"status"=>0])->update(["admin_id"=>$this->auth->id,"status"=>$status,'update_time'=>time()])){
                    $this->success("修改成功","recharge_log/index");
                }
                $this->error("该账单已审核","recharge_log/index");
            }
            $this->error("参数错误，请刷新后重试","recharge_log/index");
        }
        $data = Db::name("recharge_log")->where(["id"=>$ids])->find();
        $this->assign("data",$data);
        return $this->view->fetch();
    }
}

