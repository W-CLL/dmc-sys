<?php

namespace app\admin\controller;


use app\admin\model\Admin;
use app\common\controller\Backend;
use think\Db;
use app\admin\model\StoreMoneyLog as StoreMoneyLogModel;

/**
 * Class StoreMoneyLog
 * @package app\admin\controller
 * 资金流水
 */
Class StoreMoneyLog extends Backend{

    public function index()
    {

        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $where = [];
            $store_ids = $this->get_store_ids();
            if (is_array($store_ids)) {
                if (empty($store_ids)) {
                    return json(["total" => 0, "rows" => []]);
                }
                $where["store_id"] = ["in",$store_ids];
            }

            $list = Db::name("store_money_log")
                ->field("id,store_id,advertiser_id,money,type,explain,create_time")
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            foreach ($list as $k=>$v){
                $list[$k]['store_username'] = Db::name("store")->where("id",$v['store_id'])->value("username");
            }
            $count = Db::name("store_money_log")->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }


    public function recharge_list()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $where = ["type"=>["=",3]];
            $store_ids = $this->get_store_ids();
            if (is_array($store_ids)) {
                if (empty($store_ids)) {
                    return json(["total" => 0, "rows" => []]);
                }
                $where["store_id"] = ["in",$store_ids];
            }

            $list = StoreMoneyLogModel::with("StoreAdminAccess")
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();


            $admin_data = Admin::admin_nickname();

            foreach ($list as $key=>$vel){
                $list[$key]['salesman'] = '';
                foreach ($vel["store_admin_access"] as $k=>$v){
                    if (empty($list[$key]['salesman'])){
                        $list[$key]['salesman'] = $admin_data[$v["admin_id"]];
                    }else{
                        $list[$key]['salesman'] .= "," . $admin_data[$v["admin_id"]];
                    }
                }
            }
            $count = Db::name("store_money_log")->where($where)->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }

    public function auditing($ids = null)
    {
        if ($this->request->isPost()) {
            $status = input("status");
            $auditing_explain = input("auditing_explain");
            if ($status == 1 || $status == 2){
                if (Db::name("store_money_log")->where(["id"=>$ids,"status"=>0])->update(["auditing_admin_id"=>$this->auth->id,"auditing_explain"=>$auditing_explain,"status"=>$status,'update_time'=>time()])){
                    $this->success("修改成功","store_money_log/recharge_list");
                }
                $this->error("该账单已审核","store_money_log/recharge_list");
            }
            $this->error("参数错误，请刷新后重试","store_money_log/recharge_list");
        }
        $data = Db::name("store_money_log")->where(["id"=>$ids])->find();
        $this->assign("data",$data);
        return $this->view->fetch();
    }


}

