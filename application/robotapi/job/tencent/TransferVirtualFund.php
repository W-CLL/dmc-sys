<?php

namespace app\robotapi\job\tencent;

use app\common\model\txgg\TencentRefund;
use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentTransferLog;
use app\robotapi\model\TransferFailure;
use think\Exception;
use think\Db;
use txgg\Fund;

class TransferVirtualFund
{
    public function doJob($data){
        // 查询可转出金额（在事务外）
        $check = Fund::accountToAccountTransfer([
            'account_id' => (int)$data['account_id'],
            'to_account_id' => (int)$data['to_account_id'],
            'fund_type' => 'FUND_TYPE_COMPENSATE_VIRTUAL',
            'amount' => 0,
            'pre_fetch_amount' => 1,
        ])['data'];
        if ($check['code'] != 0){
            throw new Exception('获取可操作虚拟补偿金失败');
        }
        
        // 验证金额
        $recommend_amount = is_string($check['data']['recommend_amount']) ? 
            floatval(str_replace(',', '', $check['data']['recommend_amount'])) : 
            $check['data']['recommend_amount'];
        if ($recommend_amount <= 0) {
            throw new Exception('可转出金额必须大于0');
        }
        
        $transfer_data = $data['data'];
        $transfer_data['money'] = $recommend_amount / 100;
        
        // 开始事务（数据库操作）
        Db::startTrans();
        try {
            $transfer_records_model = new TencentTransferLog();
            $transfer_records_id = $transfer_records_model->insertGetId($transfer_data);
            if (!$transfer_records_id) {
                throw new Exception("生成转账记录失败");
            }

            $this->inheritanceRatio($data['account_id'], $data['to_account_id'], $transfer_data['money']);
            
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            throw new Exception($e->getMessage());
        }
        
        // 事务提交后，调用接口（在事务外）
        try {
            $res = Fund::accountToAccountTransfer([
                'account_id' => (int)$data['account_id'],
                'to_account_id' => (int)$data['to_account_id'],
                'fund_type' => 'FUND_TYPE_COMPENSATE_VIRTUAL',
                'amount' => (float)$check['data']['recommend_amount'],
                'external_bill_no' => uniqid('hx-'),
                'pre_fetch_amount' => 0,
            ])['data'];
            if ($res['code'] != 0) {
                throw new Exception("发起转账失败: " . ($res['message_cn'] ?? $res['message'] ?? '未知错误'));
            }
        } catch (Exception $e) {
            // 接口失败，需要补偿回滚数据库
            try {
                Db::startTrans();
                $transfer_records_model->where('id', $transfer_records_id)->delete();
                // TODO: 恢复 inheritanceRatio 的操作（复杂，可能需要单独处理）
                Db::commit();
            } catch (Exception $rollbackEx) {
                // 回滚失败，记录日志
                error_log("TransferVirtualFund 回滚失败: " . $rollbackEx->getMessage());
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
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            error_log("TransferVirtualFund 订单号更新失败，记录到失败表: " . $e->getMessage());
            
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

    private function inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money)
    {
        $refund_model = new TencentRefund();
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
                    $refund_model->addStoreRefundRecord($wallet, $data);
                    $info->wallet -= $wallet['wallet'];
                    $info->credit -= $wallet['credit'];
                    
                    if (!$info->save()) {
                        throw new Exception('返点记录扣除失败');
                    }
                    
                    Db::commit();
                } catch (Exception $e) {
                    Db::rollback();
                    throw new Exception('继承返点比例失败: ' . $e->getMessage());
                }
            }
        }while($bool);
    }

}