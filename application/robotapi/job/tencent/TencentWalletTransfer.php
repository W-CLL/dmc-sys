<?php

namespace app\robotapi\job\tencent;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentRefund;
use app\robotapi\model\TencentStore;
use app\robotapi\model\TencentWalletTransferLog;
use think\Db;
use think\Exception;
use txgg\Fund;

class TencentWalletTransfer
{
    public function doJob($data)
    {
        Db::startTrans();
        try {
            if($data['transfer_records_data']['transfer_direction'] == 1){
                $refund_model = new TencentRefund();
                //添加当前折扣百分比下的充值记录
                $refund_model->addStoreRefundRecord($data['money'], $data['transfer_records_data'],2);
            }else{
                $refund_model = new TencentRefund();
                //扣除最新折扣百分比的充值记录
                $refund_model->getRealRefundRebate($data['transfer_records_data'],2);
            }

            $transfer_records_model = new TencentWalletTransferLog();
            // 生成订单
            $transfer_records_id = $transfer_records_model->insertGetId($data['transfer_records_data']);
            if (!$transfer_records_id) {
                throw new Exception("生成转账记录失败");
            }
            // 扣除费用
            $this->deductingFees($data['transfer_records_data']);
            // 发起转账
            do{
                $transfer_result = $this->initiateTransfer($data['transfer_records_data']);
                var_dump($transfer_result);die;
            }while($transfer_result['code'] != 0);

            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $transfer_records_model->where(["id" => ["=", $transfer_records_id]])->update([
            "order_uid" => $transfer_result['data']['external_bill_no'],
            "record" => json_encode($transfer_result['data'],JSON_UNESCAPED_UNICODE),
            "update_time" => time(),
        ]);
        $queue = new QueueRobot();
        $queue->addQueue('腾讯广告【转账后续操作】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\tencent\SubsequentOperations',
                "transfer_records_id" => $transfer_records_id,
                "handle" => "TencentWalletTransfer",   // 此处传入的参数是需要执行逻辑的方法名
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }



    private function deductingFees($data){
        if ($data["transfer_direction"] == 1) {
            //转入
            $store_model = new TencentStore();
            $sql = $store_model->where(["store_id" => ["=", $data['store_id']]]);
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";
            if ($data["deduction_balance"] > 0) {
                $sql->where(["$prefix"."money_tencent" => [">=", $data["deduction_balance"]]])->dec("$prefix"."money_tencent", $data["deduction_balance"]);
            }
            if ($data["deduction_credit_limit"] > 0) {
                $sql->where(["$prefix"."credit_limit_tencent" => [">=", $data["deduction_credit_limit"]]])->dec("$prefix"."credit_limit_tencent", $data["deduction_credit_limit"]);
                $sql->inc("$prefix"."spending_credit_limit_tencent", $data["deduction_credit_limit"]);
            }
            if (!$sql->update(["update_time" => time()])) {
                throw new Exception("扣款失败");
            }
        }
    }



    private function initiateTransfer($data){
        return Fund::transferToShareWallet([
            'account_id' => 64568612,
            'to_account_id' => $data['sub_wallet_id'],
            'fund_type' => 'FUND_TYPE_CASH',
            'amount' => (int) ($data['money'] * 100),
            'transfer_type' => $data['transfer_direction'] == 1 ? 'AGENCY_TO_WALLET' : 'WALLET_TO_AGENCY',
            'external_bill_no' => uniqid('hxsz-gx-'),
            'memo' => $data['remark'],
            'transfer_try_best' => 0,
        ])['data'];
    }


}