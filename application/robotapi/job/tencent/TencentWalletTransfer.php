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
    private function writeLog($type, $message, $context = [])
    {
        $logDir = __DIR__ . '/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/wallet_transfer_' . date('Y-m-d') . '.log';
        $logData = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'context' => $context
        ];
        file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function doJob($data)
    {
        $this->writeLog('INFO', '开始执行转账任务', [
            'store_id' => $data['transfer_records_data']['store_id'] ?? '',
            'money' => $data['transfer_records_data']['money'] ?? '',
            'direction' => $data['transfer_records_data']['transfer_direction'] ?? ''
        ]);

        Db::startTrans();
        try {
            if($data['transfer_records_data']['transfer_direction'] == 1){
                $refund_model = new TencentRefund();
                $refund_model->addStoreRefundRecord($data['money'], $data['transfer_records_data'],2);
            }else{
                $refund_model = new TencentRefund();
                $refund_model->getRealRefundRebate($data['transfer_records_data'],2);
            }

            $transfer_records_model = new TencentWalletTransferLog();
            $transfer_records_id = $transfer_records_model->insertGetId($data['transfer_records_data']);
            if (!$transfer_records_id) {
                $this->writeLog('ERROR', '生成转账记录失败', ['data' => $data['transfer_records_data']]);
                throw new Exception("生成转账记录失败");
            }
            $this->writeLog('INFO', '转账记录已创建', ['transfer_records_id' => $transfer_records_id]);

            $this->deductingFees($data['transfer_records_data']);
            $this->writeLog('INFO', '扣款完成');

            $maxRetry = 3;
            $retryCount = 0;
            $lastError = '';
            do{
                if (++$retryCount > $maxRetry) {
                    $this->writeLog('ERROR', '转账重试次数超过限制', [
                        'max_retry' => $maxRetry,
                        'last_error' => $lastError,
                        'transfer_data' => $data['transfer_records_data']
                    ]);
                    throw new Exception("转账重试次数超过限制");
                }
                $this->writeLog('INFO', "发起API转账请求 (第{$retryCount}次)", [
                    'sub_wallet_id' => $data['transfer_records_data']['sub_wallet_id'] ?? '',
                    'amount' => $data['transfer_records_data']['money'] ?? ''
                ]);
                $transfer_result = $this->initiateTransfer($data['transfer_records_data']);
                if ($transfer_result['code'] != 0) {
                    $lastError = $transfer_result['message'] ?? $transfer_result['message_cn'] ?? json_encode($transfer_result);
                    $this->writeLog('WARN', "API调用失败 (第{$retryCount}次)", [
                        'code' => $transfer_result['code'],
                        'error' => $lastError,
                        'response' => $transfer_result
                    ]);
                }
            }while($transfer_result['code'] != 0);

            $this->writeLog('INFO', 'API转账成功', ['order_uid' => $transfer_result['data']['external_bill_no'] ?? '']);
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            $this->writeLog('ERROR', '事务回滚', ['error' => $e->getMessage()]);
            throw new Exception($e->getMessage());
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