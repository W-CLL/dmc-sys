<?php

namespace app\robotapi\job\transfer;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\TransferRecords;
use app\robotapi\model\Store;
use app\robotapi\model\StoreMoneyLog;
use think\Db;
use think\Cache;
use think\Env;
use think\Exception;

use jlqc\FundManagement;

class QueryTransferInfo
{
    public function doJob($data)
    {
        try {
            $transfer_records_model = new TransferRecords();
            $transfer_records_data = $transfer_records_model->where(["id" => $data["transfer_records_id"]])->find();
            $transfer_detail_data = FundManagement::transfer_detail(
                Cache::get("qc_access_token"),
                generate_random_string(16),
                Env::get('dmc_ad_config.advertiser_id'),
                $transfer_records_data["transfer_serial"]);
            if (!isset($transfer_detail_data['code']) || !isset($transfer_detail_data['message']) || $transfer_detail_data['code'] != 0 && $transfer_detail_data['message'] != "OK") {
                throw new Exception("查询转账信息失败");
            }
            $method = $data['handle'];
            if (!is_string($method)) {
                throw new Exception("handle 必须是字符串类型，当前值：" . json_encode($method));
            }

            if (!method_exists($this, $method)) {
                throw new Exception("找不到对应的方法：" . $method);
            }

            return $this->$method($transfer_records_data, $transfer_detail_data, $data);
        }catch (Exception $e){
//            $this->callBack($data["callback_data"], "服务内部错误");
            throw new Exception($e->getMessage());   // 重新抛出异常
        }


    }

    private function QcPeerTransfer($transfer_records_data, $transfer_detail_data, $data)
    {
        $transfer_records_model = new TransferRecords();
        switch ($transfer_detail_data['data']['transfer_status']){
            case 'TRANSFER_FAILED':
                $transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                $msg = "同级互转失败，失败原因：" . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                break;
            case 'TRANSFER_SUCCESS':
                $transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 1]);
                $msg = "同级互转成功";
                break;
            default :
                return false;
        }
        // 发起回调，扔队列
        $this->callBack($data["callback_data"], $msg);
        return true;
    }


    private function QcAccountTransfer($transfer_records_data, $transfer_detail_data, $data)
    {
        $transfer_records_model = new TransferRecords();
        switch ($transfer_detail_data['data']['transfer_status']){
            case 'TRANSFER_FAILED':
                if(!$transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']])){
                    throw new Exception("更新转账状态失败");
                }
                // 退款处理
                if (!$this->refund($transfer_records_data)){
                    throw new Exception("退款失败");
                }
                $msg = "千川转账失败，失败原因：" . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                break;
            case 'TRANSFER_SUCCESS':
                Db::startTrans();
                try {
                    $store_model = new Store();
                    $store_money_log_model = new StoreMoneyLog();
                    $store_info = $store_model->where("id", $transfer_records_data["store_id"])->lock(true)->find();
                    $money_log_data = $this->buildMoneyLog($store_info, $transfer_records_data);
                    $money_log_id = $store_money_log_model->insertGetId($money_log_data);
                    if (!$money_log_id) {
                        throw new Exception('转账成功，资金记录写入失败');
                    }
                    if(!$this->increaseFees($money_log_data, $store_info)){
                        throw new Exception("增加dmc余额失败");
                    }
                    if (!$transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 1])) {
                        throw new Exception('转账成功，状态更新失败');
                    }
                    //添加同步转账记录任务
                    //暂时转入账户才同步
                    $name = $transfer_records_data["transfer_direction"] == 1 ? "同步备款充值记录":"同步备款退款记录";
                    $queueModel = new \app\common\model\Queue();
                    $queueModel->addQueue($name, "app\job\SyncCharge",
                        "syncCharge",
                        ["log_id" => $data["transfer_records_id"], 'data' => $transfer_records_data],
                        "transfer_records"
                    );
                    Db::commit();
                    $msg = "千川转账成功！\n钱包余额：" . $money_log_data["balance_surplus"] . "\n授信余额：" . $money_log_data["credit_limit_surplus"];
                    break;
                }catch (Exception $e){
                    Db::rollback();
                    throw new Exception($e->getMessage()); // 重新抛出异常
                }
            default :
                return false;
        }
        // 发起回调，扔队列
        $this->callBack($data["callback_data"], $msg);
        return true;
    }


    private function buildMoneyLog($store_info, $transfer_records_data){
        $money_log = [
            "store_id" => $store_info['id'],
            "company_id" => $transfer_records_data['company_id'],
            "advertiser_id" => $transfer_records_data['advertiser_id'],
            "transfer_records_id" => $transfer_records_data['id'],
            "account_type" => $transfer_records_data['account_type'],
            "money" => $transfer_records_data['money'],
            "rebate" => $transfer_records_data["rebate"],
            "discount_percentage" => $transfer_records_data['discount_percentage'],
            "create_time" => time()
        ];
        $prefix = $transfer_records_data['account_type'] == 1 ? "public_" : "private_";
        switch ($transfer_records_data['transfer_direction']){
            case 1:
                $money_log["actual_money"] = $transfer_records_data["actual_money"];
                $money_log["deduction_balance"] = $transfer_records_data["deduction_balance"];
                $money_log["deduction_credit_limit"] = $transfer_records_data["deduction_credit_limit"];
                $money_log['type'] = 4;
                $money_log['explain'] = "转入千川" . $transfer_records_data['money'] . "元,扣除返点" . $transfer_records_data["rebate"] . "元,实际扣款" . $transfer_records_data["actual_money"] . "元";
                if ($transfer_records_data["deduction_credit_limit"] > 0) {
                    $money_log["explain"] .= ",扣除余额:" . $transfer_records_data["deduction_balance"] . ",扣除授信额度:" . $transfer_records_data["deduction_credit_limit"];
                }
                $money_log['balance_surplus'] = $store_info[$prefix.'money'];
                $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'];
                return $money_log;
            case 2:
                $money_log['type'] = 5;
                $money_log["actual_money"] = $transfer_records_data["money"] - $transfer_records_data["rebate"];
                $money_log['explain'] = "千川转出" . $transfer_records_data['money'] . "元,扣除返点" . $transfer_records_data["rebate"] . "元,到账" . $money_log["actual_money"] . "元";
                if ($store_info[$prefix."spending_credit_limit"] > 0) {
                    if ($store_info[$prefix."spending_credit_limit"] >= $money_log["actual_money"]) {
                        $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                        $money_log["explain"] .= ",已使用授信余额扣除:" . $money_log["actual_money"] . "实际到账:0";
                        $money_log['balance_surplus'] = $store_info[$prefix.'money'];
                        $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'] + $money_log["actual_money"];
                    } else {
                        $money_log["deduction_credit_limit"] = $store_info[$prefix."spending_credit_limit"];
                        $actual_money = $money_log["actual_money"] - $store_info[$prefix."spending_credit_limit"];
                        $money_log["explain"] .= ",已使用授信余额扣除:" . $store_info[$prefix."spending_credit_limit"] . ",实际到账:" . $actual_money;
                        $money_log['balance_surplus'] = $store_info[$prefix.'money'] + $actual_money;
                        $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'] + $store_info[$prefix."spending_credit_limit"];
                    }
                } else {
                    $money_log['balance_surplus'] = $store_info[$prefix.'money'] + $money_log["actual_money"];
                    $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'];
                }
                return $money_log;
        }
    }


    private function refund($data){
        if ($data["transfer_direction"] == 1) {
            $store_model = new Store();
            $sql = $store_model->where("id", $data["store_id"]);
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";
            try {
                $store_refund_model = new StoreRefund();
                $store_refund_model->getRealRefundRebate($data);  // 删除记录
                return $sql->inc($prefix."money", $data["deduction_balance"])
                    ->inc($prefix."credit_limit", $data["deduction_credit_limit"])
                    ->dec($prefix."spending_credit_limit", $data["deduction_credit_limit"])
                    ->update(["update_time" => time()]);
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }


    private function increaseFees($money_log, $store_info){
        if ($money_log["type"] == 5){
            $store_model = new Store();
            $sql = $store_model->where("id", $money_log["store_id"]);
            $prefix = $money_log["account_type"] == 1 ? "public_" : "private_";
            if ($store_info[$prefix."spending_credit_limit"] > 0) {
                if ($store_info[$prefix."spending_credit_limit"] >= $money_log["actual_money"]) {
                    $sql->dec($prefix."spending_credit_limit", $money_log["actual_money"])
                        ->inc($prefix."credit_limit", $money_log["actual_money"]);
                } else {
                    $sql->inc($prefix."money", $money_log["actual_money"] - $store_info[$prefix."spending_credit_limit"])
                        ->inc($prefix."credit_limit", $store_info[$prefix."spending_credit_limit"])
                        ->dec($prefix."spending_credit_limit", $store_info[$prefix."spending_credit_limit"]);
                }
            } else {
                $sql->inc($prefix."money", $money_log["actual_money"]);
            }
            try {
                return $sql->update(["update_time" => time()]);
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }


    private function callback($data, $msg){
        $url = $data["url"];
        $params = [
            "group_wxid" => $data["group_id"],
            "sender_name" => $data["sender_name"],
            "message" => $msg,
            "msg_wxid" => $data["msg_uuid"],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('回调请求', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',[
            "job_class" => '\app\robotapi\job\sendMsg\Send',
            "url" => $url,
            "params" => $params,
        ]);
    }
}