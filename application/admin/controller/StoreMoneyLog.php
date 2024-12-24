<?php
namespace app\admin\controller;


use app\admin\model\Admin;
use app\common\controller\Backend;
use think\Db;
use app\admin\model\StoreMoneyLog as StoreMoneyLogModel;
use DateTime;

/**
 * Class StoreMoneyLog
 * @package app\admin\controller
 * 资金流水
 */
Class StoreMoneyLog extends Backend{


    /**
     * @throws \Exception
     */
    public function _search(&$where)
    {

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

        $account_id = input('account_id');
        if($account_id){
           $where['advertiser_id'] = $account_id;
        }

        $sub_wallet_id = input('sub_wallet_id');
        if($sub_wallet_id){
            $where['explain'] =['like',"%".$sub_wallet_id."%"];
        }

        $store_id = input('store_id');
        if($store_id){
            $where['store_id'] = $store_id;
        }
//        $id = input('id');
//        if($id){
//            $where['id'] = $id;
//        }
//        $interval = $startDate->diff($endDate);
//
//        if ($interval->m > 1 || ($interval->m == 1 && $interval->d > 0)) {
//            $this->error("查询相隔时间不能超过一个月");
//        }

    }
    public function index()
    {

        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $where = [];
            $this->_search($where);

            $store_ids = $this->get_store_ids();
            if (is_array($store_ids)) {
                if (empty($store_ids)) {
                    return json(["total" => 0, "rows" => []]);
                }
                $where["store_id"] = ["in",$store_ids];
            }

            $list = Db::name("store_money_log")
                ->field("id,store_id,advertiser_id,money,type,explain,balance_surplus,credit_limit_surplus,account_type,create_time,swtl_id")
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            foreach ($list as $k=>&$v){
                $list[$k]['store_username'] = Db::name("store")->where("id",$v['store_id'])->value("username");

                    preg_match('/\[(\d+)\]/', $v['explain'], $matches);
                    // 检查是否匹配成功
                    if (isset($matches[1]) && $v['swtl_id']) {
                        $v['sub_id'] =$matches[1];
                    }

            }
            $count = Db::name("store_money_log")->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }

        $account_data =Db::name("company")
            ->field("id,advertiser_id")
            ->group("advertiser_id")
            ->select();
        $sub_wallet_data =Db::name("qc_share_wallet")
            ->field("id,sub_wallet_id")
            ->where(['bind_store_id'=>['gt',0]])
//            ->group("advertiser_id")
            ->select();
        $store_data = Db::name('store')->field("id,username")->select();
        $this->assign('account_data',$account_data?:[]);
        $this->assign('sub_wallet_data',$sub_wallet_data?:[]);
        $this->assign('store_data',$store_data);
        return $this->view->fetch();
    }


    public function recharge_list()
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
            $where = ["type"=>["=",3]];
            $store_ids = $this->get_store_ids();
            if (is_array($store_ids)) {
                if (empty($store_ids)) {
                    return json(["total" => 0, "rows" => []]);
                }
                $where["store_id"] = ["in",$store_ids];
            }
            if($startDate && $endDate){
                $where['create_time'] = ["between",[$startDate,$endDate]];
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
                    $this->success("修改成功");
                }
                $this->error("该账单已审核");
            }
            $this->error("参数错误，请重试");
        }
        $data = Db::name("store_money_log")->where(["id"=>$ids])->find();
        $this->assign("data",$data);
        return $this->view->fetch();
    }
}

