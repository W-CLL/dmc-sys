<?php

namespace app\company\controller\wallet;


use app\common\controller\Company;

use think\Cache;
use think\Db;
use txy\TextRecognition;


Class Recharge extends Company{

    public function index()
    {

        if ($this->request->isAjax()) {
            $order_num = input("order_num");
            $order = Cache::store('redis')->get($order_num);
            if (empty($order)){
                $this->error("充值失败，请刷新后重试");
            }
            $order = json_decode($order,true);
            $company = Db::name("company")->where(['id'=>$this->auth->id])->find();
            // 启动事务
            Db::startTrans();
            try{
                $grant_money = round($company['gift_percentage'] * $order['money'] / 100,2);
                $money = $grant_money+$order['money'];

                Db::name("company")->where(['id'=>$company['id']])->setInc("money",$money);
                Db::name("money_log")->insert([
                    "company_id" => $company['id'],
                    "advertiser_id" => $company['advertiser_id'],
                    "money" => $money,
                    "receipt_image" => $order['image'],
                    "type" => 3,
                    "explain" => "充值" . $order['money'] . "元,赠送" . $grant_money . "元,实际到账" . $money . "元",
                    "create_time" => time()
                ]);
                Db::name("recharge_log")->insert([
                    "company_id" => $company['id'],
                    "advertiser_id" => $company['advertiser_id'],
                    "company_name" => $company['company_name'],
                    "gift_percentage" => $company['gift_percentage'],
                    "money" => $order['money'],
                    "receipt_image" => $order['image'],
                    "gifts_money" => $grant_money,
                    "create_time" => time()
                ]);
                // 提交事务
                Db::commit();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                $this->error("充值失败，请刷新后重试");
            }
            $this->success();
        }
        $money = Db::name("company")->where('id',$this->auth->id)->value('money');
        $this->assign("money",$money);
        return $this->view->fetch();
    }

    public function get_image_info(){
        $image = input("image");
        $config_data = Db::name("qc_config")->where("id",2)->find();
        $data = TextRecognition::get_image_info($config_data['secret'],$config_data['api_key'],request()->domain().$image);
        $money = 0;
        $payee = '';

        foreach ($data['BankSlipInfos'] as $k=>$v){
            if (!$money){
                if ($v['Name'] == "金额" || $v['Name'] == "金额小写" || $v['Name'] == "汇款金额" || $v['Name'] == "小写金额" || $v['Name'] == "转账金额"){
                    $money = floatval(preg_replace('/[^\d.]/','', $v['Value']));
                }
            }
            if (!$payee){
                if ($v['Name'] == "收款人名称" || $v['Name'] == "收款人全称" || $v['Name'] == "收款单位" || $v['Name'] == "收款方户名"){
                    $payee = $v['Value'];
                }
            }
        }
        if ($money && $payee){
            $order_num = date('YmdHis') . mt_rand(1000, 9999);
            Cache::store("redis")->set($order_num,json_encode(['money'=>$money,'image'=>$image],JSON_UNESCAPED_UNICODE),3600);
            return json(['code'=>1,"msg"=>"请求成功","data"=>['money'=>$money,'payee'=>$payee,'order_num'=>$order_num]]);
        }
        return json(['code'=>0,"msg"=>"识别失败"]);
    }

}

