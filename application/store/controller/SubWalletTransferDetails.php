<?php

namespace app\store\controller;

use app\common\controller\Store;
use app\store\model\ShareWalletTransferLog as TransferLogModel;
use app\store\model\Store as StoreModel;

class SubWalletTransferDetails extends Store
{
    public function index()
    {
        $TransferLogModel = new TransferLogModel();
        $StoreModel = new StoreModel();
        if ($this->request->isAjax()) {

            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $filter = input("filter", '');

            if ($filter != '') {
                $filter = (array)json_decode($filter, true);
                $where = $this->screen_filter($filter);
            }
            $where['store_id'] = ['=',$this->auth->id];
            $list = $TransferLogModel
                ->with('store,storeMoneyLog')
                ->where($where)
                ->field("id,store_id,sub_wallet_id,main_wallet_id,transfer_direction,money,rebate,actual_money,transfer_serial,status,fail_reason,create_time,update_time,account_type")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = $TransferLogModel->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $store_data = $StoreModel->field("id,username")->select();
        $this->assign('store_data',$store_data);
        return $this->view->fetch();
    }
}