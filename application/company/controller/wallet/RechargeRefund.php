<?php

namespace app\company\controller\wallet;


use app\common\controller\Company;
use jlqc\FundManagement;
use think\Cache;
use think\Db;


Class RechargeRefund extends Company{


    public function index()
    {
        $company = Db::name("company")->where(["id"=>["=",$this->auth->id]])->find();
        if (request()->isAjax()){
            if (time() > strtotime(date('Y-m-d') . ' 23:50:00')){
                $this->error("今日转账已停止");
            }

            $transaction_type = input("transaction_type");
            $money = input("money");
            $access_token = Cache::get("qc_access_token");
            $advertiser_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");


            $transfer_records_data = [
                "company_id"    =>  $this->auth->id,
                "advertiser_id" =>  $this->auth->advertiser_id,
                "transfer_direction" => 1,
                "money" => $money,
                "create_time" => time()
            ];
            if ($transaction_type == 1) {
                if ($company['money'] < $money){
                    $this->error("余额不足");
                }
                $transfer_records_data['transfer_direction'] = 1;
                $transfer_direction = 'TRANSFER_IN';
                $remark = "抖秒冲转入";
            }elseif ($transaction_type == 2){
                $transfer_records_data['transfer_direction'] = 2;
                $transfer_direction = 'TRANSFER_OUT';
                $remark = "抖秒冲转出";
            }else{
                $this->error("参数异常请刷新后重新操作");
            }

            $target_account_detail_list[] = [
                'account_id' => (int)$this->auth->advertiser_id,
                'transfer_capital_detail_list' => [[
                    'capital_type' => 'PREPAY_GENERAL',
                    'transfer_amount' => (int)($money * 100),
                ]]
            ];

            $transfer_records_id = Db::name("transfer_records")->insertGetId($transfer_records_data);
            if (!$transfer_records_id){
                $this->error("生成转账记录失败");
            }
            //发起转账
            $data = FundManagement::create_transfer($access_token,$transfer_records_id,$advertiser_id,$advertiser_id,$target_account_detail_list,$transfer_direction,$remark);
            if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK"){
                $this->error("发起转账失败");
            }
            $transfer_records_data = [];
            $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
            $transfer_records_data['record'] = json_encode($data,JSON_UNESCAPED_UNICODE);
            $transfer_records_data['update_time'] = time();
            Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update($transfer_records_data);
            $explain_record = [];
            //查询转账状态
            for ($i=1;$i <= 10;$i++ ){
                $transfer_detail_data = FundManagement::transfer_detail($access_token,$transfer_records_id,$advertiser_id,$data['data']['transfer_serial']);
                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK"){
                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS'){
                        //转账成功
                        Db::startTrans();
                        try{
                            $money_log = [
                                "company_id" => $this->auth->id,
                                "advertiser_id" => $this->auth->advertiser_id,
                                "transfer_records_id" => $transfer_records_id,
                                "money" => $money,
                                "create_time" => time()
                            ];
                            if ($transaction_type == 1){
                                if (!Db::name("company")->where(["id"=>["=",$this->auth->id],"money"=>[">=",$money]])->setDec("money",$money)){
                                    throw new \Exception('转账成功，平台扣款失败');
                                }
                                $money_log['type'] = 4;
                                $money_log['explain'] = "转入千川".$money."元";
                            }else{
                                if (!Db::name("company")->where(["id"=>["=",$this->auth->id]])->setInc("money",$money)){
                                    throw new \Exception('转账成功，平台打款失败');
                                }
                                $money_log['type'] = 5;
                                $money_log['explain'] = "千川转出".$money."元";
                            }
                            if (!Db::name("money_log")->insert($money_log)){
                                throw new \Exception('转账成功，资金记录写入失败');
                            }
                            if (!Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>1])){
                                throw new \Exception('转账成功，状态更新失败');
                            }

                            Db::commit();
                        } catch (\Exception $e) {
                            Db::rollback();
                            Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>6,'explain'=>$e->getMessage()]);
                            $this->error($e->getMessage());
                        }
                        $this->success();
                    }else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER'){
                        //未转账
                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>3]);
                        usleep( 500000 * $i);
                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING'){
                        //转账中
                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>4]);
                        if ($i == 3){
                            $this->error("转账中，请稍后刷新");
                        }
                        usleep( 500000 * $i);
                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED'){
                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>2,'explain'=>$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                        //转账失败
                        $this->error("转账失败,".$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
                    }
                }else{
                    $explain_record[] = $transfer_records_data;
                    if ($i == 10){
                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>5,'explain_record'=>json_encode($explain_record,JSON_UNESCAPED_UNICODE),'explain'=>"查询转账状态失败",'update_time'=>time()]);
                        $this->error("查询转账状态失败");
                    }
                    usleep( 500000 * $i);
                }
            }
        }

        $access_token = Cache::get("qc_access_token");
        $qc_money = FundManagement::account_balance($access_token,$this->auth->advertiser_id);

        $this->assign("qc_money",$qc_money['data']['account_total'] / 100000);
        $this->assign("money",$company['money']);
        return $this->view->fetch();
    }

}









