<?php

namespace app\api\controller;


use app\common\controller\Api;
use app\common\model\Queue;
use app\store\model\StoreMoneyLog;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use app\store\model\StoreRefund;
use think\Exception;


class Transfer extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    //检查转账中状态的转账记录并更新
    public function transfer_records_save()
    {
        $transfer_records_data = Db::name("transfer_records")->whereBetween("status",[2,5])->select();
        if (empty($transfer_records_data)) {
            return "暂无更新";
        }

        $advertiser_id = Db::name("qc_config")->where("id", 1)->value("advertiser_id");
        $access_token = Cache::get("qc_access_token");
        foreach ($transfer_records_data as $k => $v) {
            $transfer_detail_data = FundManagement::transfer_detail($access_token, $v['id'], $advertiser_id, $v['transfer_serial']);
            if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK") {
                if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS') {
                    //转账成功
                    Db::startTrans();
                    try {
                        $store = Db::name("store")->where("id", $v["store_id"])->lock(true)->find();
                        $money_log = [
                            "store_id" => $v["store_id"],
                            "company_id" => $v['company_id'],
                            "advertiser_id" => $v['advertiser_id'],
                            "transfer_records_id" => $v['id'],
                            "money" => $v['money'],
                            "account_type" => $v['account_type'],
                            //记录到数据库
                            "rebate" => $v["rebate"],
                            "discount_percentage" => $v['discount_percentage'],
                            "create_time" => $v['create_time']
                        ];

                        if ($v["transfer_direction"] == 1) {
                            $money_log["actual_money"] = $v["actual_money"];
                            $money_log["deduction_balance"] = $v["deduction_balance"];
                            $money_log["deduction_credit_limit"] = $v["deduction_credit_limit"];
                            $money_log['type'] = 4;
                            $money_log['explain'] = "转入千川" . $v['money'] . "元,扣除返点" . $v["rebate"] . "元,实际扣款" . $money_log["actual_money"] . "元";
                            if ($money_log["deduction_credit_limit"] > 0) {
                                $money_log["explain"] .= ",扣除余额:" . $v["deduction_balance"] . ",扣除授信额度:" . $v["deduction_credit_limit"];
                            }
                            if ($money_log["account_type"] == 1) {
                                $money_log['balance_surplus'] = $store['public_money'];
                                $money_log['credit_limit_surplus'] = $store['public_credit_limit'];
                            } else {
                                $money_log['balance_surplus'] = $store['private_money'];
                                $money_log['credit_limit_surplus'] = $store['private_credit_limit'];
                            }
                        } else {

                            $money_log['type'] = 5;
                            $money_log["actual_money"] = $v["actual_money"] - $v["rebate"];
                            $money_log['explain'] = "千川转出" . $v['money'] . "元,扣除返点" . $v["rebate"] . "元,到账" . $money_log["actual_money"] . "元";

                            if ($money_log["account_type"] == 1) {
                                //公
                                $sql = Db::name("store")->where("id", $v["store_id"]);
                                if ($store["public_spending_credit_limit"] > 0) {
                                    if ($store["public_spending_credit_limit"] >= $money_log["actual_money"]) {
                                        $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                                        $money_log["explain"] .= ",已使用授信余额扣除:" . $money_log["actual_money"] . "实际到账:0";
                                        $money_log['balance_surplus'] = $store['public_money'];
                                        $money_log['credit_limit_surplus'] = $store['public_credit_limit'] + $money_log["actual_money"];

                                        $sql->dec("public_spending_credit_limit", $money_log["actual_money"])
                                            ->inc("public_credit_limit", $money_log["actual_money"]);
                                    } else {
                                        $money_log["deduction_credit_limit"] = $store["public_spending_credit_limit"];
                                        $actual_money = $money_log["actual_money"] - $store["public_spending_credit_limit"];
                                        $money_log["explain"] .= ",已使用授信余额扣除:" . $store["public_spending_credit_limit"] . ",实际到账:" . $actual_money;
                                        $money_log['balance_surplus'] = $store['public_money'] + $actual_money;
                                        $money_log['credit_limit_surplus'] = $store['public_credit_limit'] + $store["public_spending_credit_limit"];
                                        $sql->inc("public_money", $actual_money)
                                            ->inc("public_credit_limit", $store["public_spending_credit_limit"])
                                            ->dec("public_spending_credit_limit", $store["public_spending_credit_limit"]);
                                    }
                                } else {
                                    $sql->inc("public_money", $money_log["actual_money"]);
                                }
                                $sql->update(["update_time" => time()]);
                            } else {
                                //私
                                $sql = Db::name("store")->where("id", $v["store_id"]);
                                if ($store["private_spending_credit_limit"] > 0) {
                                    if ($store["private_spending_credit_limit"] >= $money_log["actual_money"]) {
                                        $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                                        $money_log["explain"] .= ",已使用授信余额扣除:" . $money_log["actual_money"] . "实际到账:0";
                                        $money_log['balance_surplus'] = $store['private_money'];
                                        $money_log['credit_limit_surplus'] = $store['private_credit_limit'] + $money_log["actual_money"];

                                        $sql->dec("private_spending_credit_limit", $money_log["actual_money"])
                                            ->inc("private_credit_limit", $money_log["actual_money"]);
                                    } else {
                                        $money_log["deduction_credit_limit"] = $store["private_spending_credit_limit"];
                                        $actual_money = $money_log["actual_money"] - $store["private_spending_credit_limit"];
                                        $money_log["explain"] .= ",已使用授信余额扣除:" . $store["private_spending_credit_limit"] . ",实际到账:" . $actual_money;
                                        $money_log['balance_surplus'] = $store['private_money'] + $actual_money;
                                        $money_log['credit_limit_surplus'] = $store['private_credit_limit'] + $store["private_spending_credit_limit"];

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
                        $storeMoneyLogModel = new StoreMoneyLog();
                        $logId = $storeMoneyLogModel->insertGetId($money_log);
                        if (!$logId) {
                            throw new \Exception('转账成功，资金记录写入失败');
                        }
                        if (!Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 1,'explain' => ''])) {
                            throw new \Exception('转账成功，状态更新失败');
                        }

                        Db::commit();
                    } catch (\Exception $e) {
                        Db::rollback();
                        Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 6, 'explain' => $e->getMessage()]);
                        // $this->error($e->getMessage());
                    }
                    // $this->success();
                } else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER') {
                    //未转账
                    Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 3]);

                } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING') {
                    //转账中
                    Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 4]);
                } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED') {
                    Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                    //转账失败
                    // $this->error("转账失败," . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
                }
            } else {
                $explain_record[] = $transfer_records_data;

                Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 5, 'explain_record' => json_encode($explain_record, JSON_UNESCAPED_UNICODE), 'explain' => "查询转账状态失败", 'update_time' => time()]);
                // $this->error("查询转账状态失败");
            }

        }
        return "更新成功,本次共处理" . count($transfer_records_data) . "条数据";
    }

//    public function test()
//    {
//        if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK") {
//            if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS') {
//                //转账成功
//                Db::startTrans();
//                try {
//                    $money_log = [
//                        "company_id" => $v['company_id'],
//                        "advertiser_id" => $v['advertiser_id'],
//                        "transfer_records_id" => $v['id'],
//                        "money" => $v['money'],
//                        "account_type" => $v['account_type'],
//                        //记录到数据库
//                        "rebate" => $v["rebate"],
//                        "discount_percentage" => $v['discount_percentage'],
//                        "create_time" => time()
//                    ];
//                    if ($v['transfer_direction'] == 1) {
//                        if (!Db::name("company")->where(["id" => ["=", $v['company_id']], "money" => [">=", $v['money']]])->setDec("money", $v['money'])) {
//                            throw new \Exception('转账成功，平台扣款失败');
//                        }
//                        $money_log['type'] = 4;
//                        $money_log['explain'] = "转入千川" . $v['money'] . "元";
//                    } else {
//                        if (!Db::name("company")->where(["id" => ["=", $v['company_id']]])->setInc("money", $v['money'])) {
//                            throw new \Exception('转账成功，平台打款失败');
//                        }
//                        $money_log['type'] = 5;
//                        $money_log['explain'] = "千川转出" . $v['money'] . "元";
//                    }
//                    $storeMoneyLogModel = new StoreMoneyLog();
//                    $logId = $storeMoneyLogModel->insertGetId($money_log);
//                    if (!$logId) {
//                        throw new \Exception('转账成功，资金记录写入失败');
//                    }
//                    if (!Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 1])) {
//                        throw new \Exception('转账成功，状态更新失败');
//                    }
//
//                    Db::commit();
//                } catch (\Exception $e) {
//                    Db::rollback();
//                    Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 6, 'explain' => $e->getMessage()]);
//                }
//            } else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER') {
//                //未转账
//                Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 3]);
//
//            } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING') {
//                //转账中
//                Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 4]);
//            } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED') {
//                Db::name("transfer_records")->where(["id" => $v['id']])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
//            }
//        }
//
//
//    }


    // 更新子钱包列表
    public function check_sub_wallet_list()
    {
        $token = Cache::get("qc_access_token");
        $account_id = Db::name("qc_config")->where("id", 1)->value("advertiser_id");
        $account_type = 'AGENT';
        $data = FundManagement::get_wallet_info($token, $account_id, $account_type);
        $info = Db::name('qc_share_wallet')->where(['id' => [">", 0]])->field('sub_wallet_id')->select();
        $out_list = array_diff(array_column($info, 'sub_wallet_id'), $data['data']['sub_wallet_ids']);
        $new_list = array_diff($data['data']['sub_wallet_ids'], array_column($info, 'sub_wallet_id'));
        if (!empty($out_list)) {
            Db::name('qc_share_wallet')->where('sub_wallet_id', 'in', $out_list)->delete();
        }
        if (!empty($new_list)) {
            foreach (array_values($new_list) as $v) {
                $ins[] = [
                    'sub_wallet_id' => $v,
                    'main_wallet_id' => $data['data']['main_wallet_id'],
                ];
            }
            Db::name('qc_share_wallet')->insertAll($ins);
        }
    }


    // 更新子钱包转账记录
    public function update_sub_wallet_transfer_log()
    {
        $token = Cache::get("qc_access_token");
        $account_id = Db::name("qc_config")->where("id", 1)->value("advertiser_id");
        $account_type = 'AGENT';
        $biz_request_no = generate_random_string(10, true);
        $list = Db::name('share_wallet_transfer_log')
            ->where(['status' => ['=', 0], 'transfer_serial' => ['neq', '']])
            ->select();
        Db::startTrans();
        try {
            foreach ($list as $v) {
                $update = [];
                $data = FundManagement::check_transfer_detail($token, $account_id, $account_type, $biz_request_no, $v['transfer_serial']);
                if (!isset($data['data']['transfer_status'])) {
                    \think\Log::write($data, 'err');
                    continue;
                }
                $store_info = Db::name('store')->where('id', $v['store_id'])->find();
                if ($data['data']['transfer_status'] == 'TRANSFER_FAILED') {
                    $update['status'] = 2;
                    $update['fail_reason'] = $data['data']['transfer_wallet_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                    $update['update_time'] = time();
                    // 退款
                    if ($v['account_type'] == 1) {
                        $balance_field = 'public_money';
                        $limit_field = 'public_credit_limit';
                        $spending_field = 'public_spending_credit_limit';
                    } elseif ($v['account_type'] == 2) {
                        $balance_field = 'private_money';
                        $limit_field = 'private_credit_limit';
                        $spending_field = 'private_spending_credit_limit';
                    } else {
                        throw new \Exception('未知的账户类型');
                    }
                    $RefundModel = new StoreRefund();
                    $RefundModel->getRealRefundRebate($v, 2);
                    if ($store_info[$spending_field] < $v['deduction_credit_limit']) {
                        $change = Db::name('store')->where('id', $v['store_id'])->inc($balance_field, $v['deduction_balance'] + $v['deduction_credit_limit'] - $store_info[$spending_field])
                            ->inc($limit_field, $store_info[$spending_field])
                            ->dec($spending_field, $store_info[$spending_field]);
                    } else {
                        $change = Db::name('store')->where('id', $v['store_id'])->inc($balance_field, $v['deduction_balance'])
                            ->inc($limit_field, $v['deduction_credit_limit'])
                            ->dec($spending_field, $v['deduction_credit_limit']);
                    }
                    if (!$change->update()) {
                        throw new \Exception('退款失败');
                    }
                } elseif ($data['data']['transfer_status'] == 'TRANSFER_SUCCESS') {
                    $update['status'] = 1;
                    $update['update_time'] = time();
                    // 生成记录
                    $money_log_data = [
                        'store_id' => $v['store_id'],
                        'swtl_id' => $v['id'],
                        'money' => $v['money'],
                        'account_type' => $v['account_type'],
                        'rebate' => $v['rebate'],
                        'discount_percentage' => $v['discount_percentage'],
                        'create_time' => time()
                    ];
                    if ($v['transfer_direction'] == 1) {
                        $money_log_data['actual_money'] = $v['actual_money'];
                        $money_log_data["deduction_balance"] = $v["deduction_balance"];
                        $money_log_data['deduction_credit_limit'] = $v["deduction_credit_limit"];
                        $money_log_data['type'] = 8;
                        $money_log_data['explain'] = "转入子钱包[" . $v['sub_wallet_id'] . "]，返点：" . $v['rebate'] . "，扣除余额：" . $v['deduction_balance'] . "，扣除授信额度：" . $v['deduction_credit_limit'] . "，实际扣除金额：" . $v['actual_money'] . "【单位：元】";
                        if ($v['account_type'] == 1) {
                            $money_log_data['balance_surplus'] = $store_info['public_money'];
                            $money_log_data['credit_limit_surplus'] = $store_info['public_credit_limit'];
                        } else {
                            $money_log_data['balance_surplus'] = $store_info['private_money'];
                            $money_log_data['credit_limit_surplus'] = $store_info['private_credit_limit'];
                        }
                    } else {
                        $money_log_data['type'] = 9;
                        $money_log_data["actual_money"] = $v["actual_money"] - $v["rebate"];
                        $money_log_data['explain'] = "子钱包[" . $v['sub_wallet_id'] . "]转出，转出金额：" . $v['money'] . "，扣除返点：" . $v['rebate'] . "，预计到账金额：" . $v['actual_money'];
                        if ($v['account_type'] == 1) {
                            if ($store_info['public_spending_credit_limit'] >= $money_log_data['actual_money']) {
                                $public_money = 0.00;
                                $public_credit_limit = (float)$money_log_data['actual_money'];
                                $public_spending_credit_limit = (float)$money_log_data['actual_money'];
                            } else {
                                $public_money = (float)$money_log_data['actual_money'] - (float)$store_info['public_spending_credit_limit'];
                                $public_credit_limit = (float)$store_info['public_spending_credit_limit'];
                                $public_spending_credit_limit = (float)$store_info['public_spending_credit_limit'];
                            }
                            $res = Db::name('store')->where([
                                'id' => ['=', $store_info['id']]
                            ])
                                ->inc('public_money', $public_money)
                                ->inc('public_credit_limit', $public_credit_limit)
                                ->dec('public_spending_credit_limit', $public_spending_credit_limit);
                            $money_log_data["deduction_credit_limit"] = $public_spending_credit_limit;
                            $money_log_data['explain'] .= "，归还已使用授信额度：" . $public_spending_credit_limit . "，实际到账金额：" . $public_money . "【单位：元】";
                            $money_log_data['balance_surplus'] = $store_info['public_money'] + $public_money;
                            $money_log_data['credit_limit_surplus'] = $store_info['public_credit_limit'] + $public_credit_limit;
                        } else {
                            if ($store_info['private_spending_credit_limit'] >= $money_log_data['actual_money']) {
                                $private_money = 0;
                                $private_credit_limit = (float)$money_log_data['actual_money'];
                                $private_spending_credit_limit = (float)$money_log_data['actual_money'];
                            } else {
                                $private_money = (float)$money_log_data['actual_money'] - (float)$store_info['private_spending_credit_limit'];
                                $private_credit_limit = (float)$store_info['private_spending_credit_limit'];
                                $private_spending_credit_limit = (float)$store_info['private_spending_credit_limit'];
                            }
                            $res = Db::name('store')->where([
                                'id' => ['=', $store_info['id']]
                            ])
                                ->inc('private_money', $private_money)
                                ->inc('private_credit_limit', $private_credit_limit)
                                ->dec('private_spending_credit_limit', $private_spending_credit_limit);
                            $money_log_data["deduction_credit_limit"] = $private_spending_credit_limit;
                            $money_log_data['explain'] .= "，归还已使用授信额度：" . $private_spending_credit_limit . "，实际到账金额：" . $private_money . "【单位：元】";
                            $money_log_data['balance_surplus'] = $store_info['private_money'] + $private_money;
                            $money_log_data['credit_limit_surplus'] = $store_info['private_credit_limit'] + $private_credit_limit;
                        }
                        if (!$res->update(["update_time" => time()])) {
                            throw new Exception('转出金额变更失败');
                        }
                        $res = Db::name('qc_share_wallet')->where(['sub_wallet_id' => $v['sub_wallet_id']]);
                        if ($v['transfer_direction'] == 1) {
                            if ($v['account_type'] == 1) {
                                $res = $res->inc('transfer_in_sum_public_cash', $v['actual_money'])
                                    ->inc('transfer_in_sum_public_vr', $v['money']);
                            } else {
                                $res = $res->inc('transfer_in_sum_private_cash', $v['actual_money'])
                                    ->inc('transfer_in_sum_private_vr', $v['money']);
                            }
                        } else {
                            if ($v['account_type'] == 1) {
                                $res = $res->inc('transfer_out_sum_public_cash', $v['actual_money'])
                                    ->inc('transfer_out_sum_public_vr', $v['money'] - $v['rebate']);
                            } else {
                                $res = $res->inc('transfer_out_sum_private_cash', $v['actual_money'])
                                    ->inc('transfer_out_sum_private_vr', $v['money'] - $v['rebate']);
                            }
                        }
                        if (!$res->update()) {
                            throw new \Exception('更新累计额度发生错误');
                        }
                    }
                    $logId = Db::name('store_money_log')->insertGetId($money_log_data);
                    if (!$logId) {
                        throw new Exception('金额变更记录失败');
                    }
                    //添加同步转账记录任务
                    //暂时转入才同步
                    if ($v['transfer_direction'] == 1) {
                        $name = "同步共享钱包充值记录";
                    } else {
                        $name = "同步共享钱包退款记录";
                    }
                    $queueModel = new \app\common\model\Queue();
                    $queueModel->addQueue($name, "app\job\SyncCharge",
                        "syncCharge",
                        ["log_id" => $v['id'], 'data' => $v],
                        "share_wallet_transfer_log"
                    );
                } else {
                    throw new \Exception('转账状态未知，请手动查询');
                }
                if (!Db::name('share_wallet_transfer_log')->where('id', $v['id'])->update($update)) {
                    throw new \Exception('更新失败');
                }
                Db::commit();
            }
        } catch (\Exception $e) {
            \think\Log::write($e->getMessage(), 'Exception');
            Db::rollback();
        }
    }



    // 创建获取广告计划队列 [每天凌晨执行] 1
    public function createQcObjQueue(){
        $queueModel = new Queue();
        $company_info = Db::name('company')->where("id",'>',0)->field('id,advertiser_id')->select();
        $split_array = array_chunk($company_info, 100);
        foreach ($split_array as $k=>$v){
            $data['time_describe'] = ' -1 days';
            $data['data'] = $v;
            $queueModel->addQueue('获取广告计划','app\job\QcObj','createQcObj',$data,'');
        }
    }


    // 创建获取广告计划操作日志队列 [每天凌晨执行]  2
    public function createQcOptQueue(){
        $queueModel = new Queue();
        $data = [];
        $redis = Cache::store('redis')->handler();
        $array = $redis->SMEMBERS('obj_arr');
        foreach ($array as $v) {
            $v = unserialize($v);
            if (!isset($data[$v['advertiser_id']])) {
                $data[$v['advertiser_id']] = [];
            }
            $data[$v['advertiser_id']][] = $v['object_id'];
        }
        $split_array = array_chunk($data, 50, true);
        foreach ($split_array as $v){
            $queue_data['start_time'] = date('Y-m-d H:i:s', strtotime(date('Y-m-d') . ' -1 days'));
            $queue_data['end_time'] = date('Y-m-d H:i:s', strtotime(date('Y-m-d')));
            $queue_data['data'] = $v;
            $queueModel->addQueue('获取广告计划操作日志','app\job\QcOpt','createQcOpt',$queue_data,'');
        }
    }


    // 创建更新广告计划状态队列 [每天凌晨执行]  3
    public function updateObjStatus(){
        $queueModel = new Queue();
        $obj_info = [];
        $redis = Cache::store('redis')->handler();
        $array = $redis->SMEMBERS('obj_arr');
        foreach ($array as $v) {
            $v = unserialize($v);
            $obj_info[$v['object_id']] = $v['advertiser_id'];
        }
        $split_array = array_chunk($obj_info, 100);
        foreach ($split_array as $v){
            $queueModel->addQueue('更新广告计划状态','app\job\updateObjStatus','updateObjStatus',$v,'');
        }
    }



    // 消费缓存数据更新队列状态
    public function consumptionCache(){
        $queueModel = new Queue();
        $redis= Cache::store('redis')->handler();
        for($i=0;$i<=200;$i++){
            $data = $redis->lpop('queue_status_update');
            if(empty($data)){
                break;
            }
            if($data == "Array"){
                continue;
            }
            $data = unserialize($data);
            $job_id = array_keys($data)[0];
            if(!$queueModel->where('job_id',$job_id)->update($data[$job_id])){
                $redis->rpush('queue_status_update',serialize($data));
            }
        }
        echo 'success';
    }

}