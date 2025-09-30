<?php

namespace app\robotapi\job\tencent;

use app\robotapi\model\QueueRobot;
use think\Exception;
use think\Db;
use app\robotapi\model\TencentRefund;
use app\robotapi\model\TencentTransferLog;
use app\robotapi\model\TencentStore;
use txgg\Fund;

class TencentTransfer
{
    public function doJob($data)
    {
        Db::startTrans();
        try {
            if($data['transfer_records_data']['transfer_direction'] == 1){
                $refund_model = new TencentRefund();
                //添加当前折扣百分比下的充值记录
                $refund_model->addStoreRefundRecord($data['money'], $data['transfer_records_data']);
            }else{
                $refund_model = new TencentRefund();
                //扣除最新折扣百分比的充值记录
                $refund_model->getRealRefundRebate($data['transfer_records_data']);
            }

            $transfer_records_model = new TencentTransferLog();
            // 生成订单
            $transfer_records_id = $transfer_records_model->insertGetId($data['transfer_records_data']);
            if (!$transfer_records_id) {
                throw new Exception("生成转账记录失败");
            }
            // 扣除费用
            $this->deductingFees($data['transfer_records_data']);
            // 发起转账
            list($order_uid,$record) = $this->initiateTransfer($data['transfer_records_data']);
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $transfer_records_model->where(["id" => ["=", $transfer_records_id]])->update([
            "order_uid" => $order_uid,
            "record" => $record,
            "update_time" => time(),
        ]);
        $queue = new QueueRobot();
        $queue->addQueue('腾讯广告【转账后续操作】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\tencent\SubsequentOperations',
                "transfer_records_id" => $transfer_records_id,
                "handle" => "TencentTransfer",   // 此处传入的参数是需要执行逻辑的方法名
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }


    /**
     * 发起转账
     * @param $data
     * @return mixed
     */
    private function initiateTransfer($data){
        $agent_balance = Fund::getAgentFundInfo([
            'account_id' => 64568612,
        ])['data'];
        if ($agent_balance['code'] != 0){
            throw new Exception("腾讯广告接口异常");
        }
        $agent_balance_info = [];
        foreach ($agent_balance['data']['list'] as $item){
            $agent_balance_info[$item['fund_type']] = $item['balance'] / 100;
        }
        $res = Fund::getFundAccountInfo([
            'account_id' => (int)$data['account_id'],
        ])['data'];
        if ($res['code'] != 0){
            throw new Exception("腾讯广告接口异常");
        }
        $fund_info = [];
        foreach ($res['data']['list'] as $item){
            $fund_info[$item['fund_type']] = ($item['balance'] - (isset($item['bill_deposit_amount'])? $item['bill_deposit_amount'] :0)) / 100;
        }
        $first_bool = true;
        $second_bool = true;
        switch ($data['transfer_direction']) {
            case '1':
                if ($agent_balance_info['FUND_TYPE_GIFT'] == 0){
                    $transfer = $this->sendRequest($data, $data['money'],'FUND_TYPE_CASH');
                    if ($transfer['code'] != 0){
                        throw new Exception("发起转账失败");
                    }
                    $record = json_encode($transfer, JSON_UNESCAPED_UNICODE);
                    $order_uid = $transfer['data']['external_bill_no'];
                }
                else if ($data['money'] > $agent_balance_info['FUND_TYPE_GIFT']){
                    do{
                        $remaining_amount = $data['money'] - $agent_balance_info['FUND_TYPE_GIFT'];
                        if ($first_bool){
                            $first = $this->sendRequest($data, $agent_balance_info['FUND_TYPE_GIFT'], 'FUND_TYPE_GIFT');
                            if ($first['code'] == 0){
                                $first_order_uid = $first['data']['external_bill_no'];
                                $record1 = json_encode($first, JSON_UNESCAPED_UNICODE);
                                $first_bool = false;
                            }
                        }
                        if ($second_bool){
                            $second = $this->sendRequest($data, $remaining_amount, 'FUND_TYPE_CASH');
                            if ($second['code'] == 0){
                                $second_order_uid = $second['data']['external_bill_no'];
                                $record2 = json_encode($second, JSON_UNESCAPED_UNICODE);
                                $second_bool = false;
                            }
                        }
                        if (!$first_bool && !$second_bool){
                            $record = $record1 . ',' . $record2;
                            $order_uid = $first_order_uid .'、'. $second_order_uid;
                        }
                    }while ($first_bool || $second_bool);
                }elseif ($data['money'] <= $agent_balance_info['FUND_TYPE_GIFT']){
                    $transfer = $this->sendRequest($data, $data['money'], 'FUND_TYPE_GIFT');
                    if ($transfer['code'] != 0){
                        throw new Exception("发起转账失败");
                    }
                    $record = json_encode($transfer, JSON_UNESCAPED_UNICODE);
                    $order_uid = $transfer['data']['external_bill_no'];
                }
                break;
            case '2':
                if ($fund_info['FUND_TYPE_CASH'] == 0){
                    $transfer = $this->sendRequest($data, $data['money'], 'FUND_TYPE_GIFT');
                    if ($transfer['code'] != 0){
                        throw new Exception("发起转账失败");
                    }
                    $record = json_encode($transfer, JSON_UNESCAPED_UNICODE);
                    $order_uid = $transfer['data']['external_bill_no'];
                }
                else if ($data['money'] <= $fund_info['FUND_TYPE_CASH']){
                    $transfer = $this->sendRequest($data, $data['money'], 'FUND_TYPE_CASH');
                    if ($transfer['code'] != 0){
                        throw new Exception("发起转账失败");
                    }
                    $record = json_encode($transfer, JSON_UNESCAPED_UNICODE);
                    $order_uid = $transfer['data']['external_bill_no'];
                }
                elseif ($data['money'] > $fund_info['FUND_TYPE_CASH']){
                    do{
                        $remaining_amount = $data['money'] - $fund_info['FUND_TYPE_CASH'];
                        if ($first_bool){
                            $first = $this->sendRequest($data, $agent_balance_info['FUND_TYPE_CASH'], 'FUND_TYPE_CASH');
                            if ($first['code'] == 0){
                                $first_order_uid = $first['data']['external_bill_no'];
                                $record1 = json_encode($first, JSON_UNESCAPED_UNICODE);
                                $first_bool = false;
                            }
                        }
                        if ($second_bool){
                            $second = $this->sendRequest($data, $remaining_amount, 'FUND_TYPE_GIFT');
                            if ($second['code'] == 0){
                                $second_order_uid = $second['data']['external_bill_no'];
                                $record2 = json_encode($second, JSON_UNESCAPED_UNICODE);
                                $second_bool = false;
                            }
                        }
                        if (!$first_bool && !$second_bool){
                            $record = $record1 . ',' . $record2;
                            $order_uid = $first_order_uid .'、'. $second_order_uid;
                        }
                    }while ($first_bool || $second_bool);
                }
                break;
        }
        return [$order_uid,$record];
    }

    private function sendRequest($data, $money,$fund_type){
        return Fund::transfer([
            'account_id' => $data['account_id'],
            'fund_type' => $fund_type,
            'amount' => $money * 100,
            'transfer_type' => $data['transfer_direction'] == 1 ? 'AGENCY_TO_ADVERTISER' : 'ADVERTISER_TO_AGENCY',
            'external_bill_no' => uniqid('hxsz-zz-'),
            'memo' => $data['remark'],
            'transfer_try_best' => 1,
            'high_frequency_transfer' => 0,
        ])['data'];
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

}