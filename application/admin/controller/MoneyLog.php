<?php

namespace app\admin\controller;


use app\common\controller\Backend;
use think\Db;

Class MoneyLog extends Backend{

    public function index()
    {

        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);


            $list = Db::name("money_log")
                ->field("id,money,type,explain,create_time")
//                ->order($sort, $order)
                ->order('create_time desc')
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("money_log")->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }


}

