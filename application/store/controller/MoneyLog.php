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

            $start_date = input("start_date");
            $end_date = input("end_date");

            $startDate =strtotime($start_date);
            $endDate = strtotime($end_date." 23:59:59");

            if($startDate && $endDate){
                $where['create_time'] = ["between",[$startDate,$endDate]];
            }
            $money = input('money');
            $is_numeric = is_numeric($money);

            if(strlen($money)>0 && $is_numeric){
                $where['money'] = $money;
            }
            $where['store_id'] = $this->auth->id;


            $list = Db::name("store_money_log")
                ->where($where)
                ->field("id,money,account_type,advertiser_id,type,explain,balance_surplus,credit_limit_surplus,account_type,create_time")
//                ->order($sort, $order)
                ->order('create_time desc')
                ->limit($offset,$limit)
                ->select();
            foreach ($list as $k=>$v){
                if($v['type'] == 8 || $v['type'] == 9){
                    preg_match('/\[(.*?)\]/', $v['explain'], $matches);
                    $list[$k]['sub_wallet_id'] = $matches[1];
                }
            }
            $count = Db::name("store_money_log")->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();


    }

}

