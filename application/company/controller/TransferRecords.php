<?php

namespace app\company\controller;


use app\common\controller\Company;
use think\Db;

Class TransferRecords extends Company{

    public function index()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);

            $list = Db::name("transfer_records")
                ->where("company_id",$this->auth->id)
                ->field("id,transfer_direction,money,transfer_serial,status,explain,image,create_time")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("transfer_records")->where(['company_id'=>$this->auth->id])->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

}