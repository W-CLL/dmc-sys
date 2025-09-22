<?php

namespace app\store\controller\tencent;

use app\common\controller\Store;
use think\Db;

class MoneyLog extends Store
{
    /**
     * 腾讯动账记录
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort", "id");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $where = [];
            
            $start_date = input("start_date");
            $end_date = input("end_date");
            
            $startDate = strtotime($start_date);
            $endDate = strtotime($end_date." 23:59:59");
            
            if($startDate && $endDate){
                $where['create_time'] = ["between", [$startDate, $endDate]];
            }
            
            $money = input('money');
            $is_numeric = is_numeric($money);
            
            if(strlen($money) > 0 && $is_numeric){
                $where['money'] = $money;
            }
            
            $account_id = input('account_id');
            if($account_id){
                $where['account_id'] = $account_id;
            }

            $sub_wallet_id = input('sub_wallet_id');
            if($sub_wallet_id){
                $where['sub_wallet_id'] = $sub_wallet_id;
            }
            
            // 商户只能查看自己的记录
            $where['store_id'] = $this->auth->id;

            $list = Db::name("tencent_transaction_log")
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
                
            $count = Db::name("tencent_transaction_log")->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        
        // 获取当前商户的腾讯子客ID列表
        $account_data = Db::name("tencent_transaction_log")
            ->where(['store_id' => $this->auth->id])
            ->field("account_id")
            ->group("account_id")
            ->select();
            
        // 获取当前商户的腾讯子钱包列表
        $account_wallet_data = Db::name("tencent_transaction_log")
            ->where(['store_id' => $this->auth->id])
            ->field("sub_wallet_id")
            ->group("sub_wallet_id")
            ->select();

        $this->assign('account_data', is_array($account_data) ? $account_data : []);
        $this->assign('account_wallet_data', is_array($account_wallet_data) ? $account_wallet_data : []);
        return $this->view->fetch();
    }
}