<?php

namespace app\robotapi\job\tencent;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentAccount;
use app\robotapi\model\TencentRefund;
use app\robotapi\model\TencentStore;
use app\robotapi\model\TencentTransferLog;
use think\Exception;
use txgg\Fund;

class TencentRefundAll
{
    public function doJob($data)
    {
        // 执行全额转出
        $res = $this->sendRequest($data,'FUND_TYPE_CASH');
        if ($res['code'] != 0){
            throw new Exception($res['message_cn']);
        }
        try {
            $account_model = new TencentAccount();
            $dmc_balance = new TencentStore();
            $store_refund = new TencentRefund();
            $transfer_log = new TencentTransferLog();
            $refund_model = new TencentRefund();
            $account = $account_model->getByAccountId($data['account_id']);
            $dmc_user = $dmc_balance->getTencentStore($account['store_id']);
            if ($account['account_type'] == 1){
                //对公
                $discount_percentage = !empty(floatval($account['discount_percentage'])) ? $account['discount_percentage'] : $dmc_user['public_discount_percentage_tencent'];
            }else{
                //对私
                $discount_percentage = !empty(floatval($account['discount_percentage'])) ? $account['discount_percentage'] : $dmc_user['private_discount_percentage_tencent'];
            }
            do{
                $last_transfer_info = $store_refund->getSingleItem([
                    'account_type' => $account['account_type'],
                    'store_id' => $account['store_id'],
                    'account_id' => $account['account_id']
                ],1);
                if(!empty($last_transfer_info)){
                    $maxTTO = $last_transfer_info['wallet'] + $last_transfer_info['credit'];
                }
                if(isset($maxTTO) && $res['data']['amount'] > $maxTTO * 100){
                    $transfer_records_data = [
                        "store_id"              => $account['store_id'],
                        "tencent_account_id"    => $account['id'],
                        "account_id"            => $account['account_id'],
                        "account_type"          => $account['account_type'],
                        "transfer_direction"    => 2,
                        "money"                 => number_format(($res['data']['amount'] - $maxTTO * 100) / 100, 2, '.', ''),
                        "discount_percentage"   => $discount_percentage,
                        "remark"                => '',
                        "order_uid"             => $res['data']['external_bill_no'],
                        "record"                => json_encode($res, JSON_UNESCAPED_UNICODE),
                        "create_time"           => time(),
                        "from"                  => 2         // 机器人接口充值
                    ];
                    list($real_rebate,$actual_per) = $refund_model->getRealRefundRebate($transfer_records_data);
                    if (empty($real_rebate)) {
                        if(!empty(floatval($transfer_records_data['discount_percentage']))){
                            $real_rebate = round($transfer_records_data["money"] - ($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100), 2);
                        }else{
                            $real_rebate = 0;
                        }
                    }
                    $bool = true;
                }else{
                    $transfer_records_data = [
                        "store_id"              => $account['store_id'],
                        "tencent_account_id"    => $account['id'],
                        "account_id"            => $account['account_id'],
                        "account_type"          => $account['account_type'],
                        "transfer_direction"    => 2,
                        "money"                 => number_format($res['data']['amount'] / 100, 2, '.', ''),
                        "discount_percentage"   => $discount_percentage,
                        "remark"                => '',
                        "order_uid"             => $res['data']['external_bill_no'],
                        "record"                => json_encode($res, JSON_UNESCAPED_UNICODE),
                        "create_time"           => time(),
                        "from"                  => 2         // 机器人接口充值
                    ];
                    list($real_rebate,$actual_per) = $refund_model->getRealRefundRebate($transfer_records_data);
                    if (empty($real_rebate)) {
                        if(!empty(floatval($transfer_records_data['discount_percentage']))){
                            $real_rebate = round($transfer_records_data["money"] - ($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100), 2);
                        }else{
                            $real_rebate = 0;
                        }
                    }
                    $bool = false;
                }
                $transfer_records_data["rebate"] = $real_rebate;
                $transfer_records_data['actual_money'] = $transfer_records_data["money"];
                $transfer_records_data['discount_percentage'] = $actual_per;
                $transfer_records_id_list[] = $transfer_log->insertGetId($transfer_records_data);
            }while($bool);
        }catch (\Exception $e){
            throw new Exception($e->getMessage());
        }
        $queue = new QueueRobot();
        foreach ($transfer_records_id_list as $transfer_records_id){
            $queue->addQueue('腾讯广告【转账后续操作】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
                [
                    "job_class" => '\app\robotapi\job\tencent\SubsequentOperations',
                    "transfer_records_id" => $transfer_records_id,
                    "handle" => "TencentTransfer",   // 此处传入的参数是需要执行逻辑的方法名
                    "callback_data" => $data['callback_data'],
                ]);
        }
        return true;
    }


    private function sendRequest($data,$fund_type){
        return Fund::transfer([
            'account_id' => $data['account_id'],
            'fund_type' => $fund_type,
            'amount' => (int) 2000000000,
            'transfer_type' => 'ADVERTISER_TO_AGENCY',
            'external_bill_no' => uniqid('hxsz-zz-'),
            'memo' => '全额转出',
            'transfer_try_best' => 1,
        ])['data'];
    }
}