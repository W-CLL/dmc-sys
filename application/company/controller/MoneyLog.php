<?php

namespace app\company\controller;


use app\common\controller\Company;
use think\Db;

Class MoneyLog extends Company{

    public function index()
    {

        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);


            $list = Db::name("money_log")
                ->where(['company_id'=>$this->auth->id])
                ->field("id,money,type,explain,create_time")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("money_log")->where(['company_id'=>$this->auth->id])->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();


    }

}

