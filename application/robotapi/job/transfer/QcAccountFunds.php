<?php

namespace app\robotapi\job\transfer;

use app\robotapi\model\QueueRobot;
use think\Db;
use think\Cache;
use think\Env;
use think\Exception;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\TransferRecords;
use app\robotapi\model\Store;

use jlqc\FundManagement;

class QcAccountFunds
{
    public function doJob($data)
    {
        Db::startTrans();
        try {
            if($data['transfer_records_data']['transfer_direction'] == 1){
                $store_refund_model = new StoreRefund();
                //添加当前折扣百分比下的充值记录
                $store_refund_model->addStoreRefundRecord($data['money'], $data['transfer_records_data']);
            }else{
                $store_refund_model = new StoreRefund();
                //扣除最新折扣百分比的充值记录
                $store_refund_model->getRealRefundRebate($data['transfer_records_data']);
            }
            $transfer_records_model = new TransferRecords();
            // 生成订单
            $transfer_records_id = $transfer_records_model->insertGetId($data['transfer_records_data']);
            if (!$transfer_records_id) {
                throw new Exception("生成转账记录失败");
            }
            // 扣除费用
            $this->deductingFees($data['transfer_records_data']);
            // 发起转账
            $advertiser_id = Env::get('dmc_ad_config.advertiser_id');
            $target_account_detail_list[] = [
                'account_id' => (int)$data['transfer_records_data']['advertiser_id'],
                'transfer_capital_detail_list' => [[
                    'capital_type' => 'PREPAY_GENERAL',
                    'transfer_amount' => (int)($data['transfer_records_data']['money'] * 100),
                ]]
            ];
            $transfer_direction = $data['transfer_records_data']['transfer_direction'] == 1 ? "TRANSFER_IN" : "TRANSFER_OUT";
            list($result_data) = FundManagement::create_transfer(
                Cache::get("qc_access_token"),
                $transfer_records_id,
                $advertiser_id,
                $advertiser_id,
                $target_account_detail_list,
                $transfer_direction,
                "robot");
            if (!isset($result_data['code']) || !isset($result_data['message']) || $result_data['code'] != 0 || $result_data['message'] != "OK") {
                throw new Exception("发起转账失败");
            }
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $transfer_records_model->where(["id" => ["=", $transfer_records_id]])->update([
            "transfer_serial" => $result_data['data']['transfer_serial'],
            "record" => json_encode($result_data, JSON_UNESCAPED_UNICODE),
            "update_time" => time(),
        ]);
        $queue = new QueueRobot();
        $queue->addQueue('千川账户【查询转账信息】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\transfer\QueryTransferInfo',
                "transfer_records_id" => $transfer_records_id,
                "handle" => "QcAccountTransfer",   // 此处传入的参数是需要执行逻辑的方法名
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }

    private function deductingFees($data){
        if ($data["transfer_direction"] == 1) {
            //转入
            $store_model = new Store();
            $sql = $store_model->where(["id" => ["=", $data['store_id']]]);
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";
            if ($data["deduction_balance"] > 0) {
                $sql->where(["$prefix"."money" => [">=", $data["deduction_balance"]]])->dec("$prefix"."money", $data["deduction_balance"]);
            }
            if ($data["deduction_credit_limit"] > 0) {
                $sql->where(["$prefix"."credit_limit" => [">=", $data["deduction_credit_limit"]]])->dec("$prefix"."credit_limit", $data["deduction_credit_limit"]);
                $sql->inc("$prefix"."spending_credit_limit", $data["deduction_credit_limit"]);
            }
            if (!$sql->update(["update_time" => time()])) {
                throw new Exception("扣款失败");
            }
        }
    }
}