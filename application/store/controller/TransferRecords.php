<?php

namespace app\store\controller;

use app\store\model\TransferRecords as TransferRecordsModel;
use app\common\controller\Store;
use think\Db;

Class TransferRecords extends Store{
    //转账流水
    public function index()
    {
        if ($this->request->isAjax()) {
            $offset = input("offset",0);
            $limit = input("limit",10);
            $where = ["t.store_id"=>$this->auth->id];
            $filter = json_decode(input("filter"),true);
            if (!empty($filter)){
                if (isset($filter["advertiser_id"]) && is_numeric($filter["advertiser_id"])){
                    $where["c.advertiser_id"] = $filter["advertiser_id"];
                }
                if (isset($company_name)){
                    $where["c.company_name"] = $company_name;
                }
            }
            $list = Db::name("transfer_records")
                ->alias('t')
                ->join('company c','c.id = t.company_id')
                ->where($where)
                ->field("t.id,t.company_id,t.transfer_direction,t.money,t.transfer_serial,t.status,t.explain,t.image,t.create_time,c.advertiser_id,c.company_name")
                ->order("t.id", "desc")
                ->limit($offset,$limit)
                ->select();

            $count = Db::name("transfer_records")
                ->alias('t')
                ->join('company c','c.id = t.company_id')
                ->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

}