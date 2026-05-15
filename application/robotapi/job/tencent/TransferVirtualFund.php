<?php

namespace app\robotapi\job\tencent;

use app\common\model\txgg\TencentRefund;
use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentTransferLog;
use app\robotapi\model\TransferFailure;
use think\Exception;
use think\Db;
use txgg\Fund;

class TransferVirtualFund extends TencentBaseJob
{
    public function doJob($data){
        $this->writeLog('INFO', '开始执行虚拟补偿金转账任务', [
            'account_id' => $data['account_id'] ?? '',
            'to_account_id' => $data['to_account_id'] ?? '',
        ]);
        
        // 查询可转出金额（在事务外）
        $check = Fund::accountToAccountTransfer([
            'account_id' => (int)$data['account_id'],
            'to_account_id' => (int)$data['to_account_id'],
            'fund_type' => $data['fund_type'],
            'amount' => 0,
            'pre_fetch_amount' => 1,
        ])['data'];
        if ($check['code'] != 0){
            $this->writeLog('ERROR', '获取可操作余额失败', ['code' => $check['code']]);
            throw new Exception('获取可操作余额失败');
        }

        // 验证金额
        $recommend_amount = is_string($check['data']['recommend_amount']) ? 
            floatval(str_replace(',', '', $check['data']['recommend_amount'])) : 
            $check['data']['recommend_amount'];

        
        $transfer_data = $data['data'];
        $transfer_data['money'] = $data['amount'] ?? round($recommend_amount / 100, 2);
        if ($recommend_amount < round($transfer_data['money'] * 100, 2)) {
            $this->writeLog('ERROR', '可转出金额不足', ['recommend_amount' => $recommend_amount]);
            throw new Exception('可转出金额不足');
        }

        // 开始事务（数据库操作）
        Db::startTrans();
        try {
            $transfer_records_model = new TencentTransferLog();
            $transfer_records_id = $transfer_records_model->insertGetId($transfer_data);
            if (!$transfer_records_id) {
                $this->writeLog('ERROR', '生成转账记录失败', ['data' => $transfer_data]);
                throw new Exception("生成转账记录失败");
            }
            $this->writeLog('INFO', '转账记录已创建', ['transfer_records_id' => $transfer_records_id]);

            // 记录 inheritanceRatio 的操作结果，用于后续回滚
            $inheritance_result = $this->inheritanceRatio($data['account_id'], $data['to_account_id'], $transfer_data['money']);
            $this->writeLog('INFO', '返点比例继承完成', $inheritance_result);
            
            // 将操作结果存储到 transfer_records 中，方便后续查询和回滚
            $transfer_records_model->where('id', $transfer_records_id)->update([
                'inheritance_data' => json_encode($inheritance_result, JSON_UNESCAPED_UNICODE),
                'update_time' => time()
            ]);
            
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            $this->writeLog('ERROR', '事务回滚', ['error' => $e->getMessage()]);
            throw new Exception($e->getMessage());
        }
        
        // 事务提交后，调用接口（在事务外）
        try {
            $res = Fund::accountToAccountTransfer([
                'account_id' => (int)$data['account_id'],
                'to_account_id' => (int)$data['to_account_id'],
                'fund_type' => $data['fund_type'],
                'amount' => (int)($transfer_data['money'] * 100),
                'external_bill_no' => uniqid('hx-'),
                'pre_fetch_amount' => 0,
            ])['data'];
            if ($res['code'] != 0) {
                $this->writeLog('ERROR', 'API转账失败', ['code' => $res['code'], 'message' => $res['message_cn'] ?? $res['message']]);
                throw new Exception("发起转账失败: " . ($res['message_cn'] ?? $res['message'] ?? '未知错误'));
            }
            $this->writeLog('INFO', 'API转账成功', ['external_bill_no' => $res['data']['external_bill_no']]);
        } catch (Exception $e) {
            // 接口失败，需要补偿回滚数据库
            try {
                // 先读取 inheritance_data 字段，因为删除转账记录后无法再读取
                $transfer_record = $transfer_records_model->where('id', $transfer_records_id)->find();
                $inheritance_data = $transfer_record ? json_decode($transfer_record['inheritance_data'], true) : [];
                
                Db::startTrans();
                // 删除转账记录
                $transfer_records_model->where('id', $transfer_records_id)->delete();
                
                // 恢复 inheritanceRatio 的操作（传递读取到的操作数据）
                $this->restoreInheritanceRatio($data['account_id'], $inheritance_data);
                
                Db::commit();
                $this->writeLog('INFO', '数据库补偿回滚成功');
            } catch (Exception $rollbackEx) {
                // 回滚失败，记录日志
                $this->writeLog('ERROR', '数据库补偿回滚失败', ['error' => $rollbackEx->getMessage()]);
            }
            throw new Exception($e->getMessage());
        }
        
        // 接口成功，更新订单号（在事务内）
        try {
            Db::startTrans();
            $update['order_uid'] = $res['data']['external_bill_no'];
            $update['record'] = json_encode($res, JSON_UNESCAPED_UNICODE);
            $update['update_time'] = time();
            $updateResult = $transfer_records_model->where('id', $transfer_records_id)->update($update);
            if (!$updateResult) {
                throw new Exception('订单号更新失败');
            }
            $this->writeLog('INFO', '订单号更新成功');
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            $this->writeLog('ERROR', '订单号更新失败，记录到失败表', ['error' => $e->getMessage()]);
            
            // 接口已成功，但数据库更新失败
            TransferFailure::recordFailure(
                TransferFailure::TYPE_UPDATE_ORDER_UID,
                $transfer_records_id,
                $res['data']['external_bill_no'],
                $res,
                $e->getMessage(),
                $transfer_data
            );
            
            // 不抛出异常，任务标记为成功
            $this->writeLog('WARN', '任务标记为成功，等待定时任务重试更新订单号');
            $updateResult = true;
        }
        
        // 添加后续任务（在事务外）
        $queue = new QueueRobot();
        $queue->addQueue('腾讯广告【转账后续操作】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\tencent\SubsequentOperations',
                "transfer_records_id" => $transfer_records_id,
                "account_id" => $data['account_id'],
                "to_account_id" => $data['to_account_id'],
                "handle" => "TransferVirtualFund",
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }

    /**
     * 继承返点比例
     * @param int $advertiser_id_initiate 发起方账户ID
     * @param int $advertiser_id_target 目标方账户ID
     * @param float $money 转账金额
     * @return array 返回操作记录，用于回滚
     * @throws Exception
     */
    private function inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money)
    {
        $refund_model = new TencentRefund();
        $operations = []; // 记录所有操作，用于回滚
        
        do{
            $bool = false;
            
            // 在事务外查询
            $info = $refund_model->getOneRefundInfo($advertiser_id_initiate);
            if ($info) {
                $wallet = [];
                if($info['wallet']+$info['credit'] < $money){
                    $wallet['credit'] = $info['credit'];
                    $wallet['wallet'] = $info['wallet'];
                    $money -= $info['wallet']+$info['credit'];
                    $bool = true;
                }else{
                    if ($info['credit'] >= $money){
                        $wallet['credit'] = $money;
                        $wallet['wallet'] = 0;
                    }else{
                        $wallet['credit'] = $info['credit'];
                        $wallet['wallet'] = $money - $info['credit'];
                    }
                }
                
                // 添加事务保护
                Db::startTrans();
                try {
                    $data = [
                        'money' => $money,
                        'store_id' => $info['store_id'],
                        'discount_percentage' => $info['discount_percentage'],
                        'platform_id' => $advertiser_id_target,
                        'account_type' => $info['type'],
                    ];
                    $record_id = $refund_model->addStoreRefundRecord($wallet, $data);
                    $original_wallet = $info->wallet;
                    $original_credit = $info->credit;
                    $info->wallet -= $wallet['wallet'];
                    $info->credit -= $wallet['credit'];
                    
                    if (!$info->save()) {
                        throw new Exception('返点记录扣除失败');
                    }
                    
                    // 记录操作，用于回滚
                    $operations[] = [
                        'record_id' => $record_id,
                        'store_id' => $info['store_id'],
                        'discount_percentage' => $info['discount_percentage'],
                        'platform_id' => $advertiser_id_target,
                        'account_type' => $info['type'],
                        'wallet_deducted' => $wallet['wallet'],
                        'credit_deducted' => $wallet['credit'],
                        'original_wallet' => $original_wallet,
                        'original_credit' => $original_credit,
                    ];
                    
                    $this->writeLog('INFO', '继承返点操作', [
                        'record_id' => $record_id,
                        'wallet_deducted' => $wallet['wallet'],
                        'credit_deducted' => $wallet['credit'],
                    ]);
                    
                    Db::commit();
                } catch (Exception $e) {
                    Db::rollback();
                    throw new Exception('继承返点比例失败: ' . $e->getMessage());
                }
            }
        }while($bool);
        
        return $operations;
    }

    /**
     * 恢复继承返点比例的操作（接口失败时调用）
     * @param int $advertiser_id_initiate 发起方账户ID
     * @param array $inheritance_data 继承操作记录（从 inheritance_data 字段读取）
     * @throws Exception
     */
    private function restoreInheritanceRatio($advertiser_id_initiate, $inheritance_data)
    {
        $this->writeLog('INFO', '开始恢复继承返点比例', [
            'advertiser_id_initiate' => $advertiser_id_initiate,
            'operations_count' => count($inheritance_data)
        ]);
        
        if (empty($inheritance_data)) {
            $this->writeLog('WARN', '没有需要恢复的返点记录');
            return;
        }
        
        $refund_model = new TencentRefund();
        $restored_count = 0;
        $restored_wallet = 0;
        $restored_credit = 0;
        
        foreach ($inheritance_data as $operation) {
            try {
                Db::startTrans();
                
                // 获取原始返点信息
                $info = $refund_model->getOneRefundInfo($advertiser_id_initiate);
                if ($info) {
                    // 恢复扣除的金额（使用操作记录中的值）
                    $wallet_to_restore = $operation['wallet_deducted'] ?? 0;
                    $credit_to_restore = $operation['credit_deducted'] ?? 0;
                    
                    $info->wallet += $wallet_to_restore;
                    $info->credit += $credit_to_restore;
                    
                    if (!$info->save()) {
                        throw new Exception('恢复返点余额失败');
                    }
                    
                    $restored_wallet += $wallet_to_restore;
                    $restored_credit += $credit_to_restore;
                    
                    $this->writeLog('INFO', '恢复返点余额', [
                        'record_id' => $operation['record_id'],
                        'wallet_restored' => $wallet_to_restore,
                        'credit_restored' => $credit_to_restore,
                        'new_wallet' => $info->wallet,
                        'new_credit' => $info->credit,
                    ]);
                }
                
                // 删除返点记录
                $record_id = $operation['record_id'] ?? 0;
                if ($record_id > 0) {
                    $refund_model->where('id', $record_id)->delete();
                }
                
                Db::commit();
                $restored_count++;
            } catch (Exception $e) {
                Db::rollback();
                $this->writeLog('ERROR', '恢复返点记录失败', [
                    'operation' => $operation,
                    'error' => $e->getMessage()
                ]);
                // 继续处理下一条记录
            }
        }
        
        $this->writeLog('INFO', '继承返点比例恢复完成', [
            'restored_count' => $restored_count,
            'total_wallet_restored' => $restored_wallet,
            'total_credit_restored' => $restored_credit,
        ]);
    }

}