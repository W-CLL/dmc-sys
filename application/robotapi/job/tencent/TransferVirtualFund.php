<?php

namespace app\robotapi\job\tencent;

use app\common\model\txgg\TencentRefund;
use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentTransferLog;
use think\Exception;
use think\Db;
use txgg\Fund;

class TransferVirtualFund
{
    public function doJob($data){
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
        $transfer_data = $data['data'];
        // 确保 recommend_amount 是纯数字格式（去除可能的千位分隔符）
        $recommend_amount = is_string($check['data']['recommend_amount']) ? 
            floatval(str_replace(',', '', $check['data']['recommend_amount'])) : 
            $check['data']['recommend_amount'];
        $transfer_data['money'] = $recommend_amount / 100;  // 直接使用数值，不使用number_format
        try {
            $transfer_records_model = new TencentTransferLog();
            // 生成订单
            $transfer_records_id = $transfer_records_model->insertGetId($transfer_data);
            if (!$transfer_records_id) {
                throw new Exception("生成转账记录失败");
            }

            $this->inheritanceRatio($data['account_id'], $data['to_account_id'], $transfer_data['money']); // 继承返点比例
            // 发起转账
            $res = Fund::accountToAccountTransfer([
                'account_id' => (int)$data['account_id'],
                'to_account_id' => (int)$data['to_account_id'],
                'fund_type' => 'FUND_TYPE_COMPENSATE_VIRTUAL',
                'amount' => (float)$check['data']['recommend_amount'],
                'external_bill_no' => uniqid('hx-'),
                'pre_fetch_amount' => 0,
            ])['data'];
            if ($res['code'] != 0) {
                throw new Exception("发起转账失败");
            }
        }catch (Exception $e){
            throw new Exception($e->getMessage());
        }
        $update['order_uid'] = $res['data']['external_bill_no'];
        $update['record'] = json_encode($res, JSON_UNESCAPED_UNICODE);
        $update['update_time'] = time();
        $transfer_records_model->where('id', $transfer_records_id)->update($update);
        $queue = new QueueRobot();
        $queue->addQueue('腾讯广告【转账后续操作】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\tencent\SubsequentOperations',
                "transfer_records_id" => $transfer_records_id,
                "account_id" => $data['account_id'],
                "to_account_id" => $data['to_account_id'],
                "handle" => "TransferVirtualFund",   // 此处传入的参数是需要执行逻辑的方法名
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }

    private function inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money)
    {
        $refund_model = new TencentRefund();
        do{
            $bool = false;
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
                $info->save(); // 扣除原本号的金额记录
            }
        }while($bool);
    }

}