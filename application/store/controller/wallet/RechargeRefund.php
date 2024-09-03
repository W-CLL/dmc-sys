<?php

namespace app\store\controller\wallet;


use app\store\model\StoreMoneyLog;
use app\common\controller\Store;
use app\store\model\StoreRefund;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Exception;


class RechargeRefund extends Store
{

    public function checkParam()
    {
        if (time() > strtotime(date('Y-m-d') . ' 23:50:00')) {
            $this->error("今日转账已停止");
        }

        $company_advertiser_id = input("advertiser_id");
        $transaction_type = input("transaction_type");
        $money = input("money");

        $account_info = $this->get_qc_money($company_advertiser_id);
        $account_data = $account_info->getData();

        if ($money > $account_data['data']['money'] && $transaction_type == 2) {
            $this->error('千川账户余额不足，不能转出');
        }

        $company = Db::name("company")->where(['advertiser_id' => $company_advertiser_id, "store_id" => $this->auth->id])->field('id,account_type')->find();
        if (empty($company)) {
            $this->error("请选择千川账户");
        }
        if (!is_numeric($money) || $money < 0) {
            $this->error("请输入正确金额");
        }

        $transfer_records_data = [
            "store_id" => $this->auth->id,
            "company_id" => $company['id'],
            "account_type" => $company['account_type'],
            "advertiser_id" => $company_advertiser_id,
            "transfer_direction" => $transaction_type,
            "money" => $money,
            "create_time" => time()
        ];


        $store = Db::name("store")->where("id", $this->auth->id)->find();
        if ($company['account_type'] == 1) {
            //公账
            $transfer_records_data['discount_percentage'] = $store['public_discount_percentage'];
            $balance = $store["public_money"];
            $credit_limit = $store["public_credit_limit"];
        } else {
            $transfer_records_data['discount_percentage'] = $store['private_discount_percentage'];
            $balance = $store["private_money"];
            $credit_limit = $store["private_credit_limit"];
        }

        $rebate = round($money - ($money * 100) / ($transfer_records_data['discount_percentage'] * 100), 2);

        if (($money - $rebate) > ($balance + $credit_limit) && $transaction_type == 1) {
            $this->error('钱包余额不足，不能转入！');
        }

        return [$money, $balance, $credit_limit, $company, $company_advertiser_id, $transfer_records_data];

    }

    public function index()
    {

        if (request()->isAjax()) {
            list($money, $balance, $credit_limit, $company, $company_advertiser_id, $transfer_records_data) = $this->checkParam();
            $access_token = Cache::get("qc_access_token");
            $advertiser_id = Db::name("qc_config")->where("id", 1)->value("advertiser_id");
            $transfer_direction = '';
            $remark = "";
            $this->calculate_deductions($balance, $credit_limit, $transfer_records_data, $transfer_direction, $remark);
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
            try {
                $transfer_records_id = Db::name("transfer_records")->insertGetId($transfer_records_data);
                if (!$transfer_records_id) {
                    throw new Exception("生成转账记录失败");
                }
                if ($transfer_records_data["transfer_direction"] == 1) {
                    //转入
                    $sql = Db::name("store")->where(["id" => ["=", $this->auth->id]]);
                    if ($transfer_records_data["deduction_balance"] > 0) {
                        if ($transfer_records_data["account_type"] == 1) {
                            $sql->where(["public_money" => [">=", $transfer_records_data["deduction_balance"]]])->dec("public_money", $transfer_records_data["deduction_balance"]);
                        } else {
                            $sql->where(["private_money" => [">=", $transfer_records_data["deduction_balance"]]])->dec("private_money", $transfer_records_data["deduction_balance"]);
                        }
                    }
                    if ($transfer_records_data["deduction_credit_limit"] > 0) {
                        if ($transfer_records_data["account_type"] == 1) {
                            $sql->where(["public_credit_limit" => [">=", $transfer_records_data["deduction_credit_limit"]]])->dec("public_credit_limit", $transfer_records_data["deduction_credit_limit"]);
                            $sql->inc("public_spending_credit_limit", $transfer_records_data["deduction_credit_limit"]);
                        } else {
                            $sql->where(["private_credit_limit" => [">=", $transfer_records_data["deduction_credit_limit"]]])->dec("private_credit_limit", $transfer_records_data["deduction_credit_limit"]);
                            $sql->inc("private_spending_credit_limit", $transfer_records_data["deduction_credit_limit"]);
                        }
                    }
                    if (!$sql->update(["update_time" => time()])) {
                        throw new Exception("扣款失败");
                    }
                }

                //发起转账
                $data = FundManagement::create_transfer($access_token, $transfer_records_id, $advertiser_id, $advertiser_id, $target_account_detail_list, $transfer_direction, $remark);
                if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK") {
                    throw new Exception("发起转账失败");
                }

                $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
                $transfer_records_data['record'] = json_encode($data, JSON_UNESCAPED_UNICODE);
                $transfer_records_data['update_time'] = time();
                Db::name("transfer_records")->where(["id" => $transfer_records_id])->update($transfer_records_data);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }

            $explain_record = [];
            //查询转账状态
            for ($i = 1; $i <= 3; $i++) {
                $transfer_detail_data = FundManagement::transfer_detail($access_token, $transfer_records_id, $advertiser_id, $data['data']['transfer_serial']);
                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK") {
                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS') {
                        //转账成功
                        Db::startTrans();
                        try {
                            $store = Db::name("store")->where("id", $this->auth->id)->lock(true)->find();
                            $money_log = [
                                "store_id" => $store["id"],
                                "company_id" => $company['id'],
                                "advertiser_id" => $company_advertiser_id,
                                "transfer_records_id" => $transfer_records_id,
                                "account_type" => $company['account_type'],
                                "money" => $money,
                                //记录到数据库
                                "rebate" => $transfer_records_data["rebate"],
                                "discount_percentage" => $transfer_records_data['discount_percentage'],
                                "create_time" => time()
                            ];

                            if ($transfer_records_data["transfer_direction"] == 1) {
                                $money_log["actual_money"] = $transfer_records_data["actual_money"];
                                $money_log["deduction_balance"] = $transfer_records_data["deduction_balance"];
                                $money_log["deduction_credit_limit"] = $transfer_records_data["deduction_credit_limit"];
                                $money_log['type'] = 4;
                                $money_log['explain'] = "转入千川" . $money . "元,扣除返点" . $transfer_records_data["rebate"] . "元,实际扣款" . $money_log["actual_money"] . "元";
                                if ($money_log["deduction_credit_limit"] > 0) {
                                    $money_log["explain"] .= ",扣除余额:" . $transfer_records_data["deduction_balance"] . ",扣除授信额度:" . $transfer_records_data["deduction_credit_limit"];
                                }
                            } else {

                                $money_log['type'] = 5;
                                $money_log["actual_money"] = $transfer_records_data["actual_money"] - $transfer_records_data["rebate"];
                                $money_log['explain'] = "千川转出" . $money . "元,扣除返点" . $transfer_records_data["rebate"] . "元,到账" . $money_log["actual_money"] . "元";

                                if ($money_log["account_type"] == 1) {
                                    //公
                                    $sql = Db::name("store")->where("id", $this->auth->id);
                                    if ($store["public_spending_credit_limit"] > 0) {
                                        if ($store["public_spending_credit_limit"] >= $money_log["actual_money"]) {
                                            $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:" . $money_log["actual_money"] . "实际到账:0";

                                            $sql->dec("public_spending_credit_limit", $money_log["actual_money"])
                                                ->inc("public_credit_limit", $money_log["actual_money"]);
                                        } else {
                                            $money_log["deduction_credit_limit"] = $store["public_spending_credit_limit"];
                                            $actual_money = $money_log["actual_money"] - $store["public_spending_credit_limit"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:" . $store["public_spending_credit_limit"] . ",实际到账:" . $actual_money;
                                            $sql->inc("public_money", $actual_money)
                                                ->inc("public_credit_limit", $store["public_spending_credit_limit"])
                                                ->dec("public_spending_credit_limit", $store["public_spending_credit_limit"]);
                                        }
                                    } else {
                                        $sql->inc("public_limit", $money_log["actual_money"]);
                                    }
                                    $sql->update(["update_time" => time()]);
                                } else {
                                    //私
                                    $sql = Db::name("store")->where("id", $this->auth->id);
                                    if ($store["private_spending_credit_limit"] > 0) {
                                        if ($store["private_spending_credit_limit"] >= $money_log["actual_money"]) {
                                            $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:" . $money_log["actual_money"] . "实际到账:0";

                                            $sql->dec("private_spending_credit_limit", $money_log["actual_money"])
                                                ->inc("private_credit_limit", $money_log["actual_money"]);
                                        } else {
                                            $money_log["deduction_credit_limit"] = $store["private_spending_credit_limit"];
                                            $actual_money = $money_log["actual_money"] - $store["private_spending_credit_limit"];
                                            $money_log["explain"] .= ",已使用授信余额扣除:" . $store["private_spending_credit_limit"] . ",实际到账:" . $actual_money;
                                            $sql->inc("private_money", $actual_money)
                                                ->inc("private_credit_limit", $store["private_spending_credit_limit"])
                                                ->dec("private_spending_credit_limit", $store["private_spending_credit_limit"]);
                                        }
                                    } else {
                                        $sql->inc("private_money", $money_log["actual_money"]);
                                    }
                                    $sql->update(["update_time" => time()]);
                                }
                            }
                            if (!Db::name("store_money_log")->insert($money_log)) {
                                throw new \Exception('转账成功，资金记录写入失败');
                            }
                            if (!Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 1])) {
                                throw new \Exception('转账成功，状态更新失败');
                            }

                            Db::commit();
                        } catch (\Exception $e) {
                            Db::rollback();
                            Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 6, 'explain' => $e->getMessage()]);
                            $this->error($e->getMessage());
                        }
                        $this->success();
                    } else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER') {
                        //未转账
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 3]);
                        usleep(500000 * $i);
                    } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING') {
                        //转账中
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 4]);
                        if ($i == 3) {
                            $this->error("转账中，请稍后刷新");
                        }
                        usleep(500000 * $i);
                    } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED') {
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                        //转账失败
                        $this->error("转账失败," . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
                    }
                } else {
                    $explain_record[] = $transfer_records_data;
                    if ($i == 3) {
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 5, 'explain_record' => json_encode($explain_record, JSON_UNESCAPED_UNICODE), 'explain' => "查询转账状态失败", 'update_time' => time()]);
                        $this->error("查询转账状态失败");
                    }
                    usleep(500000 * $i);
                }
            }
        }
        $store = Db::name("store")->where(["id" => ["=", $this->auth->id]])->find();
        $company_data = Db::name("company")->where("store_id", $this->auth->id)->field("advertiser_id,name")->select();
        $this->assign("company_data", $company_data);
        $this->assign("public_money", $store['public_money']);
        $this->assign("private_money", $store['private_money']);
        $this->assign("public_credit_limit", $store['public_credit_limit']);
        $this->assign("private_credit_limit", $store['private_credit_limit']);
        $this->assign("public_spending_credit_limit", $store['public_spending_credit_limit']);
        $this->assign("private_spending_credit_limit", $store['private_spending_credit_limit']);
        $this->assign("public_discount_percentage", $store['public_discount_percentage']);
        $this->assign("private_discount_percentage", $store['private_discount_percentage']);
        return $this->view->fetch();
    }

    /**
     * 计算扣除费用
     * @param $balance
     * 钱包/授信总余额
     * @param $credit_limit
     * 授信额度
     * @param $transfer_records_data
     * 转账记录字段 [
     * "store_id" => 用户id
     * "company_id" => 公司id,
     * "account_type" => 千川账户类型（公，私）,
     * "advertiser_id" => 千川账户id,
     * "transfer_direction" => 交易类型（2转出，1转入）,
     * "money" => 交易金额,
     * "create_time" => 创建时间
     * ];
     * @param $transfer_direction
     * 转账类型 TRANSFER_IN 转入, TRANSFER_OUT 转出
     * @param $remark
     * 备注
     * @return void
     */
    private function calculate_deductions($balance, $credit_limit, &$transfer_records_data, &$transfer_direction, &$remark)
    {
        $store_refund_model = new StoreRefund();
        if ($transfer_records_data["transfer_direction"] == 1) {
            $transfer_direction = 'TRANSFER_IN';
            $remark = "抖秒冲转入";
            //返点金额 计算方式 交易金额 - （交易金额*100 / 折扣百分比*100）取小数点后两位 1 - (1*100 / 1.1*100) = 0.1;
            $transfer_records_data["rebate"] = round($transfer_records_data["money"] - ($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100), 2);
            //实际交易（扣除）金额 交易金额 - 返点金额
            $transfer_records_data['actual_money'] = number_format($transfer_records_data["money"] - $transfer_records_data["rebate"], 2, '.', '');
            //钱包余额+授信额度 小于实际交易金额
            if (($balance + $credit_limit) < $transfer_records_data["actual_money"]) {
                $this->error("余额不足");
            }
            //实际交易金额 大于 钱包余额
            if ($transfer_records_data['actual_money'] > $balance) {
                //扣除钱包余额
                $transfer_records_data["deduction_balance"] = $balance;
                //扣除授信额度 总授信额度 - （总授信额度+钱包 - 实际扣除金额）
                $transfer_records_data["deduction_credit_limit"] = $credit_limit - ($credit_limit + $balance - $transfer_records_data['actual_money']);
            } else {
                $transfer_records_data["deduction_balance"] = $transfer_records_data['actual_money'];
                $transfer_records_data["deduction_credit_limit"] = 0;
            }
            //实际交易金额
            if ($transfer_records_data['actual_money'] <= $balance) {
                $wallet_money = $transfer_records_data['money'];
                $credit_limit = 0;
            } else {
                $wallet_money = $balance;
                $credit_limit = $transfer_records_data['money'] - $balance;
            }
            //交易金额大于等于钱包余额
            $money = [
                'wallet' => $wallet_money,
                'credit' => $credit_limit,
            ];

            //添加当前折扣百分比下的充值记录
            $store_refund_model->addStoreRefundRecord($money, $transfer_records_data);
        } else {
            $transfer_direction = 'TRANSFER_OUT';
            $remark = "抖秒冲转出";
            //返点金额 计算方式 交易金额 - （交易金额*100 / 折扣百分比*100）取小数点后两位，往上根据百分比扣除
            //$transfer_records_data["rebate"] = round( $transfer_records_data["money"] -($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100),2);

            $real_rebate = $store_refund_model->getRealRefundRebate($transfer_records_data);
            if (empty($real_rebate)) {
                $real_rebate = $transfer_records_data["rebate"] = round($transfer_records_data["money"] - ($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100), 2);
            }
            $transfer_records_data["rebate"] = $real_rebate;
            $transfer_records_data['actual_money'] = $transfer_records_data["money"];
        }
    }

    public function get_qc_money($advertiser_id = '')
    {
        if (empty($advertiser_id)) {
            $advertiser_id = input("advertiser_id");
        }
        if(!$advertiser_id){
            $this->error('请输入正确的ID');
        }
        $company = Db::name("company")->where(['advertiser_id' => $advertiser_id, "store_id" => $this->auth->id])->find();
        if ($company) {
            $access_token = Cache::get("qc_access_token");
//            $qc_money = FundManagement::account_balance($access_token, $advertiser_id);//获取不到赠送余额
            $qc_money = FundManagement::account_balance_wallet($access_token, $advertiser_id);//获取钱包详细信息
            $return_code = FundManagement::$auth_return_code;

            if(in_array($qc_money['code'],$return_code)){
                return json(["code" => 0, "msg" => "千川授权已失效，请联系管理员"]);
//                $this->error('千川授权已失效，请联系管理员');
            }
            $total_money = $qc_money['data']['total_balance_abs'];
            $grant_balance = $qc_money['data']['grant_balance'];
            $actual_money = $total_money - $grant_balance;
            $data =[
                "money" => $actual_money / 100000,
                "total_money" => $actual_money/100000,
                "grant_balance" => $actual_money/100000,
                "account_type" => $company['account_type']
            ];
            return json(["code" => 1, "data" => $data,"msg" => "请求成功"]);
        }
        return json(["code" => 0, "msg" => "请求失败，请刷新后重新请求"]);
    }

}









