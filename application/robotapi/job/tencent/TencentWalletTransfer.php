<?php

namespace app\robotapi\job\tencent;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentRefund;
use app\robotapi\model\TencentStore;
use app\robotapi\model\TencentWalletTransferLog;
use app\robotapi\model\TransferFailure;
use think\Db;
use think\Env;
use think\Exception;
use txgg\Fund;

class TencentWalletTransfer extends TencentBaseJob
{
    public function doJob($data)
    {
        $this->writeLog('INFO', '开始执行转账任务', [
            'store_id' => $data['transfer_records_data']['store_id'] ?? '',
            'money' => $data['transfer_records_data']['money'] ?? '',
            'direction' => $data['transfer_records_data']['transfer_direction'] ?? ''
        ]);

        // 初始化重试变量（必须在事务外）
        $maxRetry = 3;
        $retryCount = 0;
        $lastError = '';
        
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

            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            $this->writeLog('ERROR', '事务回滚', ['error' => $e->getMessage()]);
            throw new Exception($e->getMessage());
        }
        
        // 事务提交后，调用接口（在事务外）
        do{
            if (++$retryCount > $maxRetry) {
                $this->writeLog('ERROR', '转账重试次数超过限制', [
                    'max_retry' => $maxRetry,
                    'last_error' => $lastError,
                    'transfer_data' => $data['transfer_records_data']
                ]);
                // 接口失败，需要补偿回滚数据库
                try {
                    Db::startTrans();
                    $this->restoreDeduction($data['transfer_records_data']);
                    $transfer_records_model->where('id', $transfer_records_id)->delete();
                    Db::commit();
                    $this->writeLog('INFO', '数据库补偿回滚成功');
                } catch (Exception $rollbackEx) {
                    $this->writeLog('ERROR', '数据库补偿回滚失败', ['error' => $rollbackEx->getMessage()]);
                }
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
        
        // 接口成功，更新订单号（在事务内）
        try {
            Db::startTrans();
            $updateResult = $transfer_records_model->where(["id" => ["=", $transfer_records_id]])->update([
                "order_uid" => $transfer_result['data']['external_bill_no'],
                "record" => json_encode($transfer_result['data'],JSON_UNESCAPED_UNICODE),
                "update_time" => time(),
            ]);
            if (!$updateResult) {
                throw new Exception('订单号更新失败');
            }
            $this->writeLog('INFO', '订单号更新成功');
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            $this->writeLog('ERROR', '订单号更新失败，记录到失败表', ['error' => $e->getMessage()]);
            
            // 接口已成功，但数据库更新失败
            // 记录到失败表，后续通过定时任务重试
            TransferFailure::recordFailure(
                TransferFailure::TYPE_UPDATE_ORDER_UID,
                $transfer_records_id,
                $transfer_result['data']['external_bill_no'],
                $transfer_result['data'],
                $e->getMessage(),
                $data['transfer_records_data']
            );
            
            // 不再抛出异常，任务标记为成功
            $this->writeLog('WARN', '任务标记为成功，等待定时任务重试更新订单号');
        }
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



    private function initiateTransfer($data){
        return Fund::transferToShareWallet([
            'account_id' => (int)Env::get('txgg.agency_'.$data['agency']),
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