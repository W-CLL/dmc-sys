<?php

namespace app\admin\controller;


use app\common\controller\Backend;
use think\Db;

Class TransferRecords extends Backend
{

    public function _initialize()
    {
        parent::_initialize();
    }

    public function index($ids = null)
    {
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $company_id = input("company_id");
            $list = Db::name("transfer_records")
                ->where("company_id",$company_id)
                ->field("id,transfer_direction,money,transfer_serial,status,explain,image,create_time")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("transfer_records")->where(['company_id'=>$company_id])->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $this->assign("company_id",$ids);
        return $this->view->fetch();
    }

    public function store_list($ids = null)
    {
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $store_id = input("store_id");
            $list = Db::name("transfer_records")
                ->where("store_id",$store_id)
                ->field("id,advertiser_id,transfer_direction,money,transfer_serial,status,explain,image,create_time")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("transfer_records")->where(['store_id'=>$store_id])->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $this->assign("store_id",$ids);
        return $this->view->fetch();
    }

}