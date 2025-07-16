<?php

namespace app\robotapi\job\transfer;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\ShareWalletTransferLog as TransferLogModel;
use app\robotapi\model\Store;

use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Env;
use think\Exception;

class QcSharedWallet
{
    public function doJob($data)
    {
        Db::startTrans();
        try {
            $store_refund_model = new StoreRefund();
            if ($data['insert_data']['transfer_direction'] == 1) {
                //添加当前折扣百分比下的充值记录
                $store_refund_model->addStoreRefundRecord($data['money'], $data['insert_data'],2);
            } else {
                //扣除最新折扣百分比的充值记录
                $store_refund_model->getRealRefundRebate($data['insert_data'],2);
            }
            $transfer_log_model = new TransferLogModel();
            $swtl_id = $transfer_log_model->insertGetId($data['insert_data']);
            if (!$swtl_id) {
                throw new Exception("生成转账记录失败");
            }
            $target_wallet_detail_list = [
                [
                    'sub_wallet_id' => (int)$data['insert_data']['sub_wallet_id'],
                    'transfer_capital_detail_list' => [
                        [
                            'capital_type' => 'PREPAY_GENERAL',
                            'platform' => 'AD_ALL',
                            'transfer_amount' => (float)$data['insert_data']['money'] * 100
                        ]
                    ]
                ]
            ];
            $transfer_direction = $data['insert_data']['transfer_direction'] == 1 ? "TRANSFER_IN" : "TRANSFER_OUT";
            // 扣除费用
            $this->deductingFees($data['insert_data']);
            $result = FundManagement::wallet_transfer(
                Cache::get("qc_access_token"),
                Env::get('dmc_ad_config.advertiser_id'),
                'AGENT',
                generate_random_string(16),
                $data['insert_data']['main_wallet_id'],
                $target_wallet_detail_list,
                $transfer_direction,
                "robot"
            );
            if($result['code'] != 0 && $result['message'] != 'OK'){
                throw new Exception($result['message']);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $transfer_log_model->where(['id' => $swtl_id])->update([
            'record' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'transfer_serial' => $result['data']['transfer_serial'],
            'update_time' => time()
        ]);
        $queue = new QueueRobot();
        $queue->addQueue('共享钱包【查询转账信息】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\transfer\QueryWalletTransferInfo',
                "swtl_id" => $swtl_id,
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }

    private function deductingFees($data)
    {
        if ($data["transfer_direction"] == 1){
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";
            $store_model = new Store();
            $res = $store_model->where([
                'id'=>['=',$data['store_id']],
                $prefix.'money'=>['>=',$data['deduction_balance']],
                $prefix.'credit_limit'=>['>=',$data['deduction_credit_limit']]
            ])
                ->dec($prefix.'money',(float)$data['deduction_balance'])
                ->dec($prefix.'credit_limit',(float)$data['deduction_credit_limit'])
                ->inc($prefix.'spending_credit_limit',(float)$data['deduction_credit_limit']);
            if(!$res->update(["update_time" => time()])){
                throw new Exception('扣款失败');
            }
        }
    }

}