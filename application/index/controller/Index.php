<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use jlqc\FundManagement;
use think\Cache;
use think\Db;

class Index extends Frontend
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function index()
    {

        return $this->view->fetch();
    }


    public function get_qc_money($advertiser_id = '',$store_id=8)
    {
        // 1795937699753995
        if (empty($advertiser_id)) {
            $advertiser_id = input("advertiser_id");
        }
        if(!$advertiser_id){
            $this->error('请输入正确的ID');
        }
        $company = Db::name("company")->where(['advertiser_id' => $advertiser_id, "store_id" => $store_id])->find();
        if ($company) {
            $access_token = Cache::get("qc_access_token");
            $qc_money = FundManagement::account_balance_wallet($access_token, $advertiser_id);
            $qc_money1 = FundManagement::account_balance($access_token, $advertiser_id);
            $return_code = FundManagement::$auth_return_code;
            dump($qc_money);
            dump($qc_money1);
                die;
            if(in_array($qc_money['code'],$return_code)){
                return json(["code" => 0, "msg" => "千川授权已失效，请联系管理员"]);
//                $this->error('千川授权已失效，请联系管理员');
            }
            $money = $qc_money['data']['account_total'] / 100000;
            return json(["code" => 1, "data" => ["money" => $money, "account_type" => $company['account_type']], "msg" => "请求成功"]);
        }
        return json(["code" => 0, "msg" => "请求失败，请刷新后重新请求"]);
    }


}
