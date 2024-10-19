<?php

namespace app\store\controller;


use app\common\controller\Store;
use think\Db;

Class MoneyLog extends Store{
    //钱包资金记录
    public function index()
    {

        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);


            $list = Db::name("store_money_log")
                ->where(['store_id'=>$this->auth->id])
                ->field("id,money,account_type,advertiser_id,type,explain,balance_surplus,credit_limit_surplus,account_type,create_time")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("store_money_log")->where(['store_id'=>$this->auth->id])->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();


    }

}

