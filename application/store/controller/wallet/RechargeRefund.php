<?php

namespace app\store\controller\wallet;


use app\common\controller\Store;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Exception;


Class RechargeRefund extends Store{

    //千川转入转出
//    public function index()
//    {
//        $store = Db::name("store")->where(["id"=>["=",$this->auth->id]])->find();
//        if (request()->isAjax()){
//            if (time() > strtotime(date('Y-m-d') . ' 23:50:00')){
//                $this->error("今日转账已停止");
//            }
//            $company_advertiser_id = input("advertiser_id");
//            $company = Db::name("company")->where(['advertiser_id'=>$company_advertiser_id,"store_id"=>$this->auth->id])->field('id,account_type')->find();
//            if (empty($company)){
//                $this->error("请选择千川账户");
//            }
//            $transaction_type = input("transaction_type");
//            $money = input("money");
//            if (!is_numeric($money) || $money < 0){
//                $this->error("请输入正确金额");
//            }
//            $access_token = Cache::get("qc_access_token");
//            $advertiser_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
//            $store = Db::name("store")->where("id",$this->auth->id)->find();
//
//            $transfer_records_data = [
//                "store_id" => $store["id"],
//                "company_id"    =>  $company['id'],
//                "account_type" => $company['account_type'],
//                "advertiser_id" =>  $company_advertiser_id,
//                "transfer_direction" => 1,
//                "money" => $money,
//                "create_time" => time()
//            ];
//            if ($company['account_type'] == 1){
//                $money_key = 'public_money';
//                $transfer_records_data['discount_percentage'] = $store['public_discount_percentage'];
//                if ($transaction_type == 1) {
//                    if (($store['public_money'] + $store['public_credit_limit']) < $money) {
//                        $this->error("余额不足");
//                    }
//                }
//                $before_money = $store['public_money'];
//            }else{
//                $money_key = 'private_money';
//                $transfer_records_data['discount_percentage'] = $store['private_discount_percentage'];
//                if ($transaction_type == 1) {
//                    if (($store['private_money'] + $store["private_credit_limit"]) < $money) {
//                        $this->error("余额不足");
//                    }
//                }
//                $before_money = $store['private_money'];
//            }
//
//
//            if ($transaction_type == 1) {
//                $transfer_records_data['transfer_direction'] = 1;
//                $transfer_direction = 'TRANSFER_IN';
//                $remark = "抖秒冲转入";
//                $deduction_money = round( $money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                $transfer_records_data['actual_money'] = $deduction_money;
//                $today_money = $before_money - $money + $deduction_money;
//            }elseif ($transaction_type == 2){
//                $transfer_records_data['transfer_direction'] = 2;
//                $transfer_direction = 'TRANSFER_OUT';
//                $remark = "抖秒冲转出";
//                $deduction_money = round( $money -($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                $transfer_records_data['actual_money'] = $money;
//                $today_money = $before_money + $money - $deduction_money;
//            }else{
//                $this->error("参数异常请刷新后重新操作");
//            }
//
//
//
//            $target_account_detail_list[] = [
//                'account_id' => (int)$company_advertiser_id,
//                'transfer_capital_detail_list' => [[
//                    'capital_type' => 'PREPAY_GENERAL',
//                    'transfer_amount' => (int)($money * 100),
//                ]]
//            ];
//
//
//            $transfer_records_id = Db::name("transfer_records")->insertGetId($transfer_records_data);
//
//            if (!$transfer_records_id){
//                $this->error("生成转账记录失败");
//            }
//
//            //发起转账
//            $data = FundManagement::create_transfer($access_token,$transfer_records_id,$advertiser_id,$advertiser_id,$target_account_detail_list,$transfer_direction,$remark);
//            if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK"){
//                $this->error("发起转账失败");
//            }
//
//            $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
//            $transfer_records_data['record'] = json_encode($data,JSON_UNESCAPED_UNICODE);
//            $transfer_records_data['update_time'] = time();
//            Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update($transfer_records_data);
//            $explain_record = [];
//            //查询转账状态
//            for ($i=1;$i <= 3;$i++ ){
//                $transfer_detail_data = FundManagement::transfer_detail($access_token,$transfer_records_id,$advertiser_id,$data['data']['transfer_serial']);
//                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK"){
//                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS'){
//                        //转账成功
//                        Db::startTrans();
//                        try{
//                            $money_log = [
//                                "store_id" => $store["id"],
//                                "company_id" => $company['id'],
//                                "advertiser_id" => $company_advertiser_id,
//                                "transfer_records_id" => $transfer_records_id,
//                                "account_type" => $company['account_type'],
//                                "before_money" => $before_money,
//                                "today_money" => $today_money,
//                                "money" => $money,
//                                "create_time" => time()
//                            ];
//                            if ($transaction_type == 1){
//                                if (!Db::name("store")->where(["id"=>["=",$store["id"]],$money_key=>[">=",$money]])->update([$money_key=>$money_log['today_money']])){
//                                    throw new \Exception('转账成功，平台扣款失败');
//                                }
//                                $money_log['type'] = 4;
//                                $money_log['explain'] = "转入千川".$money."元,扣除返点".$deduction_money."元,实际扣款".round($money - $deduction_money,2) . "元";
//                            }else{
//                                if (!Db::name("store")->where(["id"=>["=",$store["id"]]])->update([$money_key=>$money_log['today_money']])){
//                                    throw new \Exception('转账成功，平台打款失败');
//                                }
//                                $money_log['type'] = 5;
//                                $money_log['explain'] = "千川转出".$money."元,扣除返点".$deduction_money."元,实际到账".round($money - $deduction_money,2) . "元";
//                            }
//                            if (!Db::name("store_money_log")->insert($money_log)){
//                                throw new \Exception('转账成功，资金记录写入失败');
//                            }
//                            if (!Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>1])){
//                                throw new \Exception('转账成功，状态更新失败');
//                            }
//
//                            Db::commit();
//                        } catch (\Exception $e) {
//                            Db::rollback();
//                            Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>6,'explain'=>$e->getMessage()]);
//                            $this->error($e->getMessage());
//                        }
//                        $this->success();
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER'){
//                        //未转账
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>3]);
//                        usleep( 500000 * $i);
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING'){
//                        //转账中
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>4]);
//                        if ($i == 3){
//                            $this->error("转账中，请稍后刷新");
//                        }
//                        usleep( 500000 * $i);
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED'){
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>2,'explain'=>$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
//                        //转账失败
//                        $this->error("转账失败,".$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
//                    }
//                }else{
//                    $explain_record[] = $transfer_records_data;
//                    if ($i == 3){
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>5,'explain_record'=>json_encode($explain_record,JSON_UNESCAPED_UNICODE),'explain'=>"查询转账状态失败",'update_time'=>time()]);
//                        $this->error("查询转账状态失败");
//                    }
//                    usleep( 500000 * $i);
//                }
//            }
//        }
//
//        $company_data = Db::name("company")->where("store_id",$this->auth->id)->field("advertiser_id")->select();
//        $this->assign("company_data",$company_data);
//        $this->assign("public_money",$store['public_money']);
//        $this->assign("private_money",$store['private_money']);
//        $this->assign("public_credit_limit",$store['public_credit_limit']);
//        $this->assign("private_credit_limit",$store['private_credit_limit']);
//        $this->assign("public_spending_credit_limit",$store['public_spending_credit_limit']);
//        $this->assign("private_spending_credit_limit",$store['private_spending_credit_limit']);
//        $this->assign("public_discount_percentage",$store['public_discount_percentage']);
//        $this->assign("private_discount_percentage",$store['private_discount_percentage']);
//        return $this->view->fetch();
//    }

//    public function index()
//    {
//
//        if (request()->isAjax()){
//            if (time() > strtotime(date('Y-m-d') . ' 23:50:00')){
//                $this->error("今日转账已停止");
//            }
//            $company_advertiser_id = input("advertiser_id");
//            $company = Db::name("company")->where(['advertiser_id'=>$company_advertiser_id,"store_id"=>$this->auth->id])->field('id,account_type')->find();
//            if (empty($company)){
//                $this->error("请选择千川账户");
//            }
//
//            $money = input("money");
//            if (!is_numeric($money) || $money < 0){
//                $this->error("请输入正确金额");
//            }
//
//            $transaction_type = input("transaction_type");
//
//
//
//            Db::startTrans();
//            try{
//                $store = Db::name("store")->where("id",$this->auth->id)->lock(true)->find();
//                $transfer_records_data = [
//                    "store_id" => $store["id"],
//                    "company_id"    =>  $company['id'],
//                    "account_type" => $company['account_type'],
//                    "advertiser_id" =>  $company_advertiser_id,
//                    "transfer_direction" => 1,
//                    "money" => $money,
//                    "create_time" => time()
//                ];
//
//                $money_log = [
//                    "store_id" => $store["id"],
//                    "company_id" => $company['id'],
//                    "advertiser_id" => $company_advertiser_id,
//                    "account_type" => $company['account_type'],
//                    "money" => $money,
//                    "create_time" => time()
//                ];
//                if ($company['account_type'] == 1){
//                    //公账
//                    $money_key = 'public_money';
//                    $money_log["before_money"] = $store['public_money'];
//
//                    $transfer_records_data['discount_percentage'] = $store['public_discount_percentage'];
//                    if ($transaction_type == 1) {
//                        //转入
//                        $money_log['type'] = 4;
//                        $transfer_records_data['actual_money'] = $money;
//                        $deduction_money = round( $money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                        $actual_money = $money - $deduction_money;
//                        if (($store['public_money'] + $store['public_credit_limit']) < $actual_money){
//                            $this->error("余额不足");
//                        }
//                        if ($store['public_money'] <= $actual_money){
//                            $transfer_records_data["deduction_balance"] = $store['public_money'];
//                            $transfer_records_data["deduction_credit_limit"] = $actual_money - $store['public_money'];
//                            $money_log["deduction_balance"] = $store['public_money'];
//                            $money_log["deduction_credit_limit"] = $transfer_records_data["deduction_credit_limit"];
//                            $money_log["today_money"] = 0;
//                        }else{
//                            $transfer_records_data["deduction_balance"] = $actual_money;
//                            $money_log["deduction_balance"] = $actual_money;
//                            $money_log["today_money"] = $money_log["before_money"] - $actual_money;
//                        }
//
//                        $transfer_records_data['transfer_direction'] = 1;
//                        $transfer_direction = 'TRANSFER_IN';
//                        $remark = "抖秒冲转入";
//                        $money_log['explain'] = "转入千川".$money."元,扣除返点".$deduction_money."元,实际扣款".$actual_money . "元";
//                        $store_data = ["public_money"=>$money_log["today_money"],'update_time'=>time()];
//                        if ($money_log["deduction_credit_limit"] > 0){
//                            $store_data["public_credit_limit"] = $store['public_credit_limit'] - $money_log["deduction_credit_limit"];
//                            $store_data["public_spending_credit_limit"] = $store["public_spending_credit_limit"] + $money_log["deduction_credit_limit"];
//                            $money_log['explain'] .= ',扣除余额' . $money_log["deduction_balance"] . ",扣除授信额度" . $money_log["deduction_credit_limit"];
//                        }
//                        if (!Db::name("store")->where(["id"=>["=",$store["id"]]])->update($store_data)){
//                            throw new Exception("扣款失败");
//                        }
//                    }else{
//                        //转出
//                        $money_log['type'] = 5;
//                        $transfer_records_data['transfer_direction'] = 2;
//                        $transfer_direction = 'TRANSFER_OUT';
//                        $remark = "抖秒冲转出";
//                        //返点
//                        $deduction_money = round( $money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                        //实际金额
//                        $transfer_records_data['actual_money'] = $money;
//                        $actual_money = $money - $deduction_money;
//                        $store_data = ["update_time"=>time()];
//                        $money_log['explain'] = "千川转出".$money."元,扣除返点".$deduction_money."元,应到账".$actual_money . "元";
//                        if ($store["public_spending_credit_limit"] > 0){
//                            //扣除已使用授信额度
//                            if ($store["public_spending_credit_limit"] >= $actual_money){
//                                $transfer_records_data["deduction_credit_limit"] = $money;
//                                $money_log["deduction_credit_limit"] = $actual_money;
//                                $money_log["today_money"] = $money_log["before_money"];
//                                $money_log['explain'] .= ",已使用授信额度扣除" . $money . ",实际到账0";
//                                $store_data["public_spending_credit_limit"] = $store["public_spending_credit_limit"] - $actual_money;
//                                $store_data["public_credit_limit"] = $store['public_credit_limit'] + $actual_money;
//                            }else{
//                                $transfer_records_data["deduction_credit_limit"] = $actual_money - $store["public_spending_credit_limit"];
//                                $money_log["deduction_credit_limit"] = $store["public_spending_credit_limit"];
//                                $money_log["today_money"] = $money_log["before_money"] + $transfer_records_data["deduction_credit_limit"];
//                                $money_log['explain'] .= ",已使用授信额度扣除" . $money_log["deduction_credit_limit"] . ",实际到账" . $transfer_records_data["deduction_credit_limit"];
//                                $store_data["public_credit_limit"] = $store['public_credit_limit'] + $store["public_spending_credit_limit"];
//                                $store_data["public_spending_credit_limit"] = 0;
//                                $store_data["public_money"] = $store["public_money"] + $actual_money - $store["public_spending_credit_limit"];
//                            }
//                        }else{
//                            $money_log["today_money"] = $money_log["before_money"] + $actual_money;
//                            $money_log['explain'] .= ",实际到账" . $actual_money;
//                            $store_data["public_money"] = $store["public_money"] + $actual_money;
//                        }
//                    }
//
//                }else{
//
//                    $money_key = 'private_money';
//
//                    $money_log["before_money"] = $store['private_money'];
//
//                    $transfer_records_data['discount_percentage'] = $store['private_discount_percentage'];
//                    if ($transaction_type == 1) {
//                        //转入
//                        $money_log['type'] = 4;
//                        $transfer_records_data['actual_money'] = $money;
//                        $deduction_money = round( $money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                        $actual_money = $money - $deduction_money;
//                        if (($store['private_money'] + $store['private_credit_limit']) < $actual_money){
//                            $this->error("余额不足");
//                        }
//                        if ($store['private_money'] <= $actual_money){
//                            $transfer_records_data["deduction_balance"] = $store['private_money'];
//                            $transfer_records_data["deduction_credit_limit"] = $actual_money - $store['private_money'];
//                            $money_log["deduction_balance"] = $store['private_money'];
//                            $money_log["deduction_credit_limit"] = $transfer_records_data["deduction_credit_limit"];
//                            $money_log["today_money"] = 0;
//                        }else{
//                            $transfer_records_data["deduction_balance"] = $actual_money;
//                            $money_log["deduction_balance"] = $actual_money;
//                            $money_log["today_money"] = $money_log["before_money"] - $actual_money;
//                        }
//
//                        $transfer_records_data['transfer_direction'] = 1;
//                        $transfer_direction = 'TRANSFER_IN';
//                        $remark = "抖秒冲转入";
//                        $money_log['explain'] = "转入千川".$money."元,扣除返点".$deduction_money."元,实际扣款".$actual_money . "元";
//                        $store_data = ["private_money"=>$money_log["today_money"],'update_time'=>time()];
//                        if ($money_log["deduction_credit_limit"] > 0){
//                            $store_data["private_credit_limit"] = $store['private_credit_limit'] - $money_log["deduction_credit_limit"];
//                            $store_data["private_spending_credit_limit"] = $store["private_spending_credit_limit"] + $money_log["deduction_credit_limit"];
//                            $money_log['explain'] .= ',扣除余额' . $money_log["deduction_balance"] . ",扣除授信额度" . $money_log["deduction_credit_limit"];
//                        }
//                        if (!Db::name("store")->where(["id"=>["=",$store["id"]]])->update($store_data)){
//                            throw new Exception("扣款失败");
//                        }
//                    }else{
//                        //转出
//                        $money_log['type'] = 5;
//                        $transfer_records_data['transfer_direction'] = 2;
//                        $transfer_direction = 'TRANSFER_OUT';
//                        $remark = "抖秒冲转出";
//                        //返点
//                        $deduction_money = round( $money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                        //实际金额
//                        $transfer_records_data['actual_money'] = $money;
//                        $actual_money = $money - $deduction_money;
//                        $store_data = ["update_time"=>time()];
//                        $money_log['explain'] = "千川转出".$money."元,扣除返点".$deduction_money."元,应到账".$actual_money . "元";
//                        if ($store["private_spending_credit_limit"] > 0){
//                            //扣除已使用授信额度
//                            if ($store["private_spending_credit_limit"] >= $actual_money){
//                                $transfer_records_data["deduction_credit_limit"] = $money;
//                                $money_log["deduction_credit_limit"] = $actual_money;
//                                $money_log["today_money"] = $money_log["before_money"];
//                                $money_log['explain'] .= ",已使用授信额度扣除" . $money . ",实际到账0";
//                                $store_data["private_spending_credit_limit"] = $store["private_spending_credit_limit"] - $actual_money;
//                                $store_data["private_credit_limit"] = $store['private_credit_limit'] + $actual_money;
//                            }else{
//                                $transfer_records_data["deduction_credit_limit"] = $actual_money - $store["private_spending_credit_limit"];
//                                $money_log["deduction_credit_limit"] = $store["private_spending_credit_limit"];
//                                $money_log["today_money"] = $money_log["before_money"] + $transfer_records_data["deduction_credit_limit"];
//                                $money_log['explain'] .= ",已使用授信额度扣除" . $money_log["deduction_credit_limit"] . ",实际到账" . $transfer_records_data["deduction_credit_limit"];
//                                $store_data["private_credit_limit"] = $store['private_credit_limit'] + $store["private_spending_credit_limit"];
//                                $store_data["private_spending_credit_limit"] = 0;
//                                $store_data["private_money"] = $store["private_money"] + $actual_money - $store["private_spending_credit_limit"];
//                            }
//                        }else{
//                            $money_log["today_money"] = $money_log["before_money"] + $actual_money;
//                            $money_log['explain'] .= ",实际到账" . $actual_money;
//                            $store_data["private_money"] = $store["private_money"] + $actual_money;
//                        }
//                    }
//                }
//
//                $money_log["transfer_records_id"] = Db::name("transfer_records")->insertGetId($transfer_records_data);
//                if (!$money_log["transfer_records_id"]){
//                    $this->error("生成转账记录失败");
//                }
//
//                if (!Db::name("store_money_log")->insert($money_log)){
//                    throw new Exception("生成转账记录失败");
//                }
//                Db::commit();
//            } catch (\Exception $e) {
//                Db::rollback();
//
//                $this->error($e->getMessage());
//            }
//
//            $target_account_detail_list[] = [
//                'account_id' => (int)$company_advertiser_id,
//                'transfer_capital_detail_list' => [[
//                    'capital_type' => 'PREPAY_GENERAL',
//                    'transfer_amount' => (int)($money * 100),
//                ]]
//            ];
//
//            $access_token = Cache::get("qc_access_token");
//            $advertiser_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
//            //发起转账
//            $data = FundManagement::create_transfer($access_token,$money_log["transfer_records_id"],$advertiser_id,$advertiser_id,$target_account_detail_list,$transfer_direction,$remark);
//            if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK"){
//                $this->error("发起转账失败");
//            }
//
//            $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
//            $transfer_records_data['record'] = json_encode($data,JSON_UNESCAPED_UNICODE);
//            $transfer_records_data['update_time'] = time();
//            Db::name("transfer_records")->where(["id"=>$money_log["transfer_records_id"]])->update($transfer_records_data);
//
//
//            $explain_record = [];
//            //查询转账状态
//            for ($i=1;$i <= 30;$i++ ){
//                $transfer_detail_data = FundManagement::transfer_detail($access_token,$money_log["transfer_records_id"],$advertiser_id,$data['data']['transfer_serial']);
//                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK"){
//                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS'){
//
//                        if ($transaction_type == 2){
//                            //转出
//                            Db::name("store")->where(["id"=>["=",$money_log["store_id"]]])->update($store_data);
//                        }
//                        if (!Db::name("transfer_records")->where(["id"=>$money_log["transfer_records_id"]])->update(['status'=>1])){
//                            $this->error('转账成功，状态更新失败');
//                        }
//                        $this->success("转账成功");
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER'){
//                        //未转账
//                        Db::name("transfer_records")->where(["id"=>$money_log["transfer_records_id"]])->update(['status'=>3]);
//                        usleep( 500000 * $i);
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING'){
//                        //转账中
//                        Db::name("transfer_records")->where(["id"=>$money_log["transfer_records_id"]])->update(['status'=>4]);
//                        if ($i == 3){
//                            $this->error("转账中，请稍后刷新");
//                        }
//                        usleep( 500000 * $i);
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED'){
//
//                        if ($money_log["type"] == 4){
//                            $store_sql = Db::name("store")->where("id",$this->auth->id);
//                            //转入失败，余额退回
//                            if ($money_log["account_type"] == 1){
//                                //公账
//                                if ($money_log["deduction_credit_limit"] > 0){
//                                    //授信额度
//                                    $store_sql->inc("public_credit_limit",$money_log["deduction_credit_limit"]);
//                                    $store_sql->dec("public_spending_credit_limit",$money_log["deduction_credit_limit"]);
//                                }
//                                if ($money_log["deduction_balance"] > 0){
//                                    //余额
//                                    $store_sql->inc("public_money",$money_log["deduction_balance"]);
//                                }
//                            }else{
//                                //私账
//                                if ($money_log["deduction_credit_limit"] > 0){
//                                    //授信额度
//                                    $store_sql->inc("private_credit_limit",$money_log["deduction_credit_limit"]);
//                                    $store_sql->dec("private_spending_credit_limit",$money_log["deduction_credit_limit"]);
//                                }
//                                if ($money_log["deduction_balance"] > 0){
//                                    //余额
//                                    $store_sql->inc("private_money",$money_log["deduction_balance"]);
//                                }
//                            }
//                        }
//                        Db::name("transfer_records")->where(["id"=>$money_log["transfer_records_id"]])->update(['status'=>2,'explain'=>$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
//                        //转账失败
//                        $this->error("转账失败,".$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
//                    }
//                }else{
//                    $explain_record[] = $transfer_records_data;
//                    if ($i == 30){
//                        Db::name("transfer_records")->where(["id"=>$money_log["transfer_records_id"]])->update(['status'=>5,'explain_record'=>json_encode($explain_record,JSON_UNESCAPED_UNICODE),'explain'=>"查询转账状态失败",'update_time'=>time()]);
//                        $this->error("查询转账状态失败,请联系工作人员");
//                    }
//                    usleep( 500000 * $i);
//                }
//            }
//
//
//
//
//
//
//            $store = Db::name("store")->where("id",$this->auth->id)->find();
//
//            $transaction_type = input("transaction_type");
//            $transfer_records_data = [
//                "store_id" => $store["id"],
//                "company_id"    =>  $company['id'],
//                "account_type" => $company['account_type'],
//                "advertiser_id" =>  $company_advertiser_id,
//                "transfer_direction" => 1,
//                "money" => $money,
//                "create_time" => time()
//            ];
//
//            if ($company['account_type'] == 1){
//                $money_key = 'public_money';
//                $transfer_records_data['discount_percentage'] = $store['public_discount_percentage'];
//                if ($transaction_type == 1) {
//                    if (($store['public_money'] + $store['public_credit_limit']) < $money) {
//                        $this->error("余额不足");
//                    }
//                }
//                $before_money = $store['public_money'];
//            }else{
//                $money_key = 'private_money';
//                $transfer_records_data['discount_percentage'] = $store['private_discount_percentage'];
//                if ($transaction_type == 1) {
//                    if (($store['private_money'] + $store["private_credit_limit"]) < $money) {
//                        $this->error("余额不足");
//                    }
//                }
//                $before_money = $store['private_money'];
//            }
//
//
//            if ($transaction_type == 1) {
//                $transfer_records_data['transfer_direction'] = 1;
//                $transfer_direction = 'TRANSFER_IN';
//                $remark = "抖秒冲转入";
//                $deduction_money = round( $money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                $transfer_records_data['actual_money'] = $deduction_money;
//                $today_money = $before_money - $money + $deduction_money;
//            }elseif ($transaction_type == 2){
//                $transfer_records_data['transfer_direction'] = 2;
//                $transfer_direction = 'TRANSFER_OUT';
//                $remark = "抖秒冲转出";
//                $deduction_money = round( $money -($money * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
//                $transfer_records_data['actual_money'] = $money;
//                $today_money = $before_money + $money - $deduction_money;
//            }else{
//                $this->error("参数异常请刷新后重新操作");
//            }
//
//
//
//            $target_account_detail_list[] = [
//                'account_id' => (int)$company_advertiser_id,
//                'transfer_capital_detail_list' => [[
//                    'capital_type' => 'PREPAY_GENERAL',
//                    'transfer_amount' => (int)($money * 100),
//                ]]
//            ];
//
//
//            $transfer_records_id = Db::name("transfer_records")->insertGetId($transfer_records_data);
//
//            if (!$transfer_records_id){
//                $this->error("生成转账记录失败");
//            }
//
//            //发起转账
//            $data = FundManagement::create_transfer($access_token,$transfer_records_id,$advertiser_id,$advertiser_id,$target_account_detail_list,$transfer_direction,$remark);
//            if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK"){
//                $this->error("发起转账失败");
//            }
//
//            $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
//            $transfer_records_data['record'] = json_encode($data,JSON_UNESCAPED_UNICODE);
//            $transfer_records_data['update_time'] = time();
//            Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update($transfer_records_data);
//            $explain_record = [];
//            //查询转账状态
//            for ($i=1;$i <= 3;$i++ ){
//                $transfer_detail_data = FundManagement::transfer_detail($access_token,$transfer_records_id,$advertiser_id,$data['data']['transfer_serial']);
//                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK"){
//                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS'){
//                        //转账成功
//                        Db::startTrans();
//                        try{
//                            $money_log = [
//                                "store_id" => $store["id"],
//                                "company_id" => $company['id'],
//                                "advertiser_id" => $company_advertiser_id,
//                                "transfer_records_id" => $transfer_records_id,
//                                "account_type" => $company['account_type'],
//                                "before_money" => $before_money,
//                                "today_money" => $today_money,
//                                "money" => $money,
//                                "create_time" => time()
//                            ];
//                            if ($transaction_type == 1){
//                                if (!Db::name("store")->where(["id"=>["=",$store["id"]],$money_key=>[">=",$money]])->update([$money_key=>$money_log['today_money']])){
//                                    throw new \Exception('转账成功，平台扣款失败');
//                                }
//                                $money_log['type'] = 4;
//                                $money_log['explain'] = "转入千川".$money."元,扣除返点".$deduction_money."元,实际扣款".round($money - $deduction_money,2) . "元";
//                            }else{
//                                if (!Db::name("store")->where(["id"=>["=",$store["id"]]])->update([$money_key=>$money_log['today_money']])){
//                                    throw new \Exception('转账成功，平台打款失败');
//                                }
//                                $money_log['type'] = 5;
//                                $money_log['explain'] = "千川转出".$money."元,扣除返点".$deduction_money."元,实际到账".round($money - $deduction_money,2) . "元";
//                            }
//                            if (!Db::name("store_money_log")->insert($money_log)){
//                                throw new \Exception('转账成功，资金记录写入失败');
//                            }
//                            if (!Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>1])){
//                                throw new \Exception('转账成功，状态更新失败');
//                            }
//
//                            Db::commit();
//                        } catch (\Exception $e) {
//                            Db::rollback();
//                            Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>6,'explain'=>$e->getMessage()]);
//                            $this->error($e->getMessage());
//                        }
//                        $this->success();
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER'){
//                        //未转账
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>3]);
//                        usleep( 500000 * $i);
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING'){
//                        //转账中
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>4]);
//                        if ($i == 3){
//                            $this->error("转账中，请稍后刷新");
//                        }
//                        usleep( 500000 * $i);
//                    }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED'){
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>2,'explain'=>$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
//                        //转账失败
//                        $this->error("转账失败,".$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
//                    }
//                }else{
//                    $explain_record[] = $transfer_records_data;
//                    if ($i == 3){
//                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>5,'explain_record'=>json_encode($explain_record,JSON_UNESCAPED_UNICODE),'explain'=>"查询转账状态失败",'update_time'=>time()]);
//                        $this->error("查询转账状态失败");
//                    }
//                    usleep( 500000 * $i);
//                }
//            }
//        }
//        $store = Db::name("store")->where(["id"=>["=",$this->auth->id]])->find();
//        $company_data = Db::name("company")->where("store_id",$this->auth->id)->field("advertiser_id")->select();
//        $this->assign("company_data",$company_data);
//        $this->assign("public_money",$store['public_money']);
//        $this->assign("private_money",$store['private_money']);
//        $this->assign("public_credit_limit",$store['public_credit_limit']);
//        $this->assign("private_credit_limit",$store['private_credit_limit']);
//        $this->assign("public_spending_credit_limit",$store['public_spending_credit_limit']);
//        $this->assign("private_spending_credit_limit",$store['private_spending_credit_limit']);
//        $this->assign("public_discount_percentage",$store['public_discount_percentage']);
//        $this->assign("private_discount_percentage",$store['private_discount_percentage']);
//        return $this->view->fetch();
//    }

    public function index(){

        if (request()->isAjax()){
            if (time() > strtotime(date('Y-m-d') . ' 23:50:00')){
                $this->error("今日转账已停止");
            }
            $company_advertiser_id = input("advertiser_id");
            $company = Db::name("company")->where(['advertiser_id'=>$company_advertiser_id,"store_id"=>$this->auth->id])->field('id,account_type')->find();
            if (empty($company)){
                $this->error("请选择千川账户");
            }
            $transaction_type = input("transaction_type");
            $money = input("money");
            if (!is_numeric($money) || $money < 0){
                $this->error("请输入正确金额");
            }
            $access_token = Cache::get("qc_access_token");
            $advertiser_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");


            $transfer_records_data = [
                "store_id" => $this->auth->id,
                "company_id"    =>  $company['id'],
                "account_type" => $company['account_type'],
                "advertiser_id" =>  $company_advertiser_id,
                "transfer_direction" => $transaction_type,
                "money" => $money,
                "create_time" => time()
            ];


            $store = Db::name("store")->where("id",$this->auth->id)->find();
            if ($company['account_type'] == 1){
                //公账
                $transfer_records_data['discount_percentage'] = $store['public_discount_percentage'];
                $balance = $store["public_money"];
                $credit_limit = $store["public_credit_limit"];
            }else{

                $transfer_records_data['discount_percentage'] = $store['private_discount_percentage'];
                $balance = $store["private_money"];
                $credit_limit = $store["private_credit_limit"];
            }
            $transfer_direction = '';
            $remark = "";
            $this->calculate_deductions($balance,$credit_limit,$transfer_records_data,$transfer_direction,$remark);

            $target_account_detail_list[] = [
                'account_id' => (int)$company_advertiser_id,
                'transfer_capital_detail_list' => [[
                    'capital_type' => 'PREPAY_GENERAL',
                    'transfer_amount' => (int)($money * 100),
                ]]
            ];

            $transfer_records_id = "";
            $data = [];
            Db::startTrans();
            try{
                $transfer_records_id = Db::name("transfer_records")->insertGetId($transfer_records_data);
                if (!$transfer_records_id){
                    throw new Exception("生成转账记录失败");
                }
                if ($transfer_records_data["transfer_direction"] == 1){
                    //转入
                    $sql = Db::name("store")->where(["id"=>["=",$this->auth->id]]);
                    if ($transfer_records_data["deduction_balance"] > 0){
                        if ($transfer_records_data["account_type"] == 1){
                            $sql->where(["public_money"=>[">=",$transfer_records_data["deduction_balance"]]])->dec("public_money",$transfer_records_data["deduction_balance"]);
                        }else{
                            $sql->where(["private_money"=>[">=",$transfer_records_data["deduction_balance"]]])->dec("private_money",$transfer_records_data["deduction_balance"]);
                        }
                    }
                    if ($transfer_records_data["deduction_credit_limit"] > 0){
                        if ($transfer_records_data["account_type"] == 1){
                            $sql->where(["public_credit_limit"=>[">=",$transfer_records_data["deduction_credit_limit"]]])->dec("public_credit_limit",$transfer_records_data["deduction_credit_limit"]);
                            $sql->inc("public_spending_credit_limit",$transfer_records_data["deduction_credit_limit"]);
                        }else{
                            $sql->where(["private_credit_limit"=>[">=",$transfer_records_data["deduction_credit_limit"]]])->dec("private_credit_limit",$transfer_records_data["deduction_credit_limit"]);
                            $sql->inc("private_spending_credit_limit",$transfer_records_data["deduction_credit_limit"]);
                        }
                    }
                    if (!$sql->update(["update_time"=>time()])){
                        throw new Exception("扣款失败");
                    }
                }

                //发起转账
                $data = FundManagement::create_transfer($access_token,$transfer_records_id,$advertiser_id,$advertiser_id,$target_account_detail_list,$transfer_direction,$remark);
                if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK"){
                    throw new Exception("发起转账失败");
                }

                $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
                $transfer_records_data['record'] = json_encode($data,JSON_UNESCAPED_UNICODE);
                $transfer_records_data['update_time'] = time();
                Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update($transfer_records_data);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }

            $explain_record = [];
            //查询转账状态
            for ($i=1;$i <= 3;$i++ ){
                $transfer_detail_data = FundManagement::transfer_detail($access_token,$transfer_records_id,$advertiser_id,$data['data']['transfer_serial']);
                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK"){
                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS'){
                        //转账成功
                        Db::startTrans();
                        try{
                            $store = Db::name("store")->where("id",$this->auth->id)->lock(true)->find();
                            $money_log = [
                                "store_id" => $store["id"],
                                "company_id" => $company['id'],
                                "advertiser_id" => $company_advertiser_id,
                                "transfer_records_id" => $transfer_records_id,
                                "account_type" => $company['account_type'],
                                "money" => $money,
                                "create_time" => time()
                            ];

                            if ($transfer_records_data["transfer_direction"] == 1){
                                $money_log["actual_money"] = $transfer_records_data["actual_money"];
                                $money_log["deduction_balance"] = $transfer_records_data["deduction_balance"];
                                $money_log["deduction_credit_limit"] = $transfer_records_data["deduction_credit_limit"];
                                $money_log['type'] = 4;
                                $money_log['explain'] = "转入千川".$money."元,扣除返点".$transfer_records_data["rebate"]."元,实际扣款" . $money_log["actual_money"] . "元";
                                if ($money_log["deduction_credit_limit"] > 0){
                                    $money_log["explain"] .= ",扣除余额:" . $transfer_records_data["deduction_balance"] . ",扣除授信额度:" . $transfer_records_data["deduction_credit_limit"];
                                }
                            }else{

                                $money_log['type'] = 5;
                                $money_log["actual_money"] = $transfer_records_data["actual_money"] - $transfer_records_data["rebate"];
                                $money_log['explain'] = "千川转出".$money."元,扣除返点".$transfer_records_data["rebate"]."元,到账".$money_log["actual_money"] . "元";

                                if ($money_log["account_type"] == 1){
                                    //公
                                    $sql = Db::name("store")->where("id",$this->auth->id);
                                    if ($store["public_spending_credit_limit"] > 0){
                                        if ($store["public_spending_credit_limit"] >= $money_log["actual_money"]){
                                            $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:".$money_log["actual_money"] . "实际到账:0";

                                            $sql->dec("public_spending_credit_limit",$money_log["actual_money"])
                                                ->inc("public_credit_limit",$money_log["actual_money"]);
                                        }else{
                                            $money_log["deduction_credit_limit"] = $store["public_spending_credit_limit"];
                                            $actual_money = $money_log["actual_money"] - $store["public_spending_credit_limit"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:" . $store["public_spending_credit_limit"] . ",实际到账:" . $actual_money;
                                            $sql->inc("public_money",$actual_money)
                                                ->inc("public_credit_limit",$store["public_spending_credit_limit"])
                                                ->dec("public_spending_credit_limit",$store["public_spending_credit_limit"]);
                                        }
                                    }else{
                                        $sql->inc("public_limit",$money_log["actual_money"]);
                                    }
                                    $sql->update(["update_time" => time()]);
                                }else{
                                    //私
                                    $sql = Db::name("store")->where("id",$this->auth->id);
                                    if ($store["private_spending_credit_limit"] > 0){
                                        if ($store["private_spending_credit_limit"] >= $money_log["actual_money"]){
                                            $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:".$money_log["actual_money"] . "实际到账:0";

                                            $sql->dec("private_spending_credit_limit",$money_log["actual_money"])
                                                ->inc("private_credit_limit",$money_log["actual_money"]);
                                        }else{
                                            $money_log["deduction_credit_limit"] = $store["private_spending_credit_limit"];
                                            $actual_money = $money_log["actual_money"] - $store["private_spending_credit_limit"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:" . $store["private_spending_credit_limit"] . ",实际到账:" . $actual_money;
                                            $sql->inc("private_money",$actual_money)
                                                ->inc("private_credit_limit",$store["private_spending_credit_limit"])
                                                ->dec("private_spending_credit_limit",$store["private_spending_credit_limit"]);
                                        }
                                    }else{
                                        $sql->inc("private_money",$money_log["actual_money"]);
                                    }
                                    $sql->update(["update_time" => time()]);
                                }
                            }
                            if (!Db::name("store_money_log")->insert($money_log)){
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
                    if ($i == 3){
                        Db::name("transfer_records")->where(["id"=>$transfer_records_id])->update(['status'=>5,'explain_record'=>json_encode($explain_record,JSON_UNESCAPED_UNICODE),'explain'=>"查询转账状态失败",'update_time'=>time()]);
                        $this->error("查询转账状态失败");
                    }
                    usleep( 500000 * $i);
                }
            }
        }
        $store = Db::name("store")->where(["id"=>["=",$this->auth->id]])->find();
        $company_data = Db::name("company")->where("store_id",$this->auth->id)->field("advertiser_id,name")->select();
        $this->assign("company_data",$company_data);
        $this->assign("public_money",$store['public_money']);
        $this->assign("private_money",$store['private_money']);
        $this->assign("public_credit_limit",$store['public_credit_limit']);
        $this->assign("private_credit_limit",$store['private_credit_limit']);
        $this->assign("public_spending_credit_limit",$store['public_spending_credit_limit']);
        $this->assign("private_spending_credit_limit",$store['private_spending_credit_limit']);
        $this->assign("public_discount_percentage",$store['public_discount_percentage']);
        $this->assign("private_discount_percentage",$store['private_discount_percentage']);
        return $this->view->fetch();
    }

    /**
     * 计算扣除费用
     * @param $balance
     * @param $credit_limit
     * @param $transfer_records_data
     */
    private function calculate_deductions($balance,$credit_limit,&$transfer_records_data,&$transfer_direction,&$remark){
        if ($transfer_records_data["transfer_direction"] == 1){
            $transfer_direction = 'TRANSFER_IN';
            $remark = "抖秒冲转入";
            $transfer_records_data["rebate"] = round( $transfer_records_data["money"] - ($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
            $transfer_records_data['actual_money'] =number_format($transfer_records_data["money"] - $transfer_records_data["rebate"], 2, '.', '');
            if (($balance + $credit_limit) < $transfer_records_data["actual_money"]){
                $this->error("余额不足");
            }
            if ($transfer_records_data['actual_money'] > $balance){
                $transfer_records_data["deduction_balance"] = $balance;
                $transfer_records_data["deduction_credit_limit"] = $credit_limit - ($credit_limit + $balance - $transfer_records_data['actual_money']);
            }else{
                $transfer_records_data["deduction_balance"] = $transfer_records_data['actual_money'];
                $transfer_records_data["deduction_credit_limit"] = 0;
            }
        }else{
            $transfer_direction = 'TRANSFER_OUT';
            $remark = "抖秒冲转出";
            $transfer_records_data["rebate"] = round( $transfer_records_data["money"] -($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100),2);
            $transfer_records_data['actual_money'] = $transfer_records_data["money"];
        }
    }

    public function get_qc_money(){
        $advertiser_id = input("advertiser_id");
        $company = Db::name("company")->where(['advertiser_id'=>$advertiser_id,"store_id"=>$this->auth->id])->find();
        if ($company){
            $access_token = Cache::get("qc_access_token");
            $qc_money = FundManagement::account_balance($access_token,$advertiser_id);
            $money = $qc_money['data']['account_total'] / 100000;
            return json(["code"=>1,"data"=>["money"=>$money,"account_type"=>$company['account_type']],"msg"=>"请求成功"]);
        }
        return json(["code"=>0,"msg"=>"请求失败，请刷新后重新请求"]);
    }

}









