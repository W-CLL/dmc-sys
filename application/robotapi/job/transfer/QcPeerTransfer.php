<?php

namespace app\robotapi\job\transfer;

use app\robotapi\model\QueueRobot;
use jlqc\FundManagement;
use think\Db;
use think\Env;
use think\Exception;
use think\Cache;

use app\robotapi\model\StoreRefund;
use app\robotapi\model\TransferRecords;
class QcPeerTransfer
{
    public function doJob($data)
    {
        Db::startTrans();
        try {
            $transfer_records_model = new TransferRecords();
            $transfer_records_id = $transfer_records_model->insertGetId($data['transfer_records_data']);
            if (!$transfer_records_id) {
                throw new Exception("生成转账记录失败");
            }
            $this->inheritanceRatio($data['original_data']['initiate_adv_id'], $data['original_data']['receive_adv_id'], $data['original_data']['amount']);

            $target_account_detail_list[] = [
                'account_id' => (int)$data['original_data']['receive_adv_id'],
                'transfer_capital_detail_list' => [[
                    'capital_type' => 'PREPAY_GENERAL',
                    'transfer_amount' => (int)($data['original_data']['amount'] * 100),
                ]]
            ];
            list($result_data) = FundManagement::create_transfer(
                Cache::get("qc_access_token"),
                $transfer_records_id,
                Env::get('dmc_ad_config.advertiser_id'),
                $data['original_data']['initiate_adv_id'],
                $target_account_detail_list,
                'TRANSFER_IN',
                "robotPeer");
            if (!isset($result_data['code']) || !isset($result_data['message']) || $result_data['code'] != 0 || $result_data['message'] != "OK") {
                \think\Log::write($result_data,'peer_transfer_error');
                throw new Exception($result_data['message']);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $transfer_records_model->where(["id" => ["=", $transfer_records_id]])->update([
            "transfer_serial" => $result_data['data']['transfer_serial'],
            "record" => json_encode($result_data, JSON_UNESCAPED_UNICODE),
            "update_time" => time(),
        ]);
        $queue = new QueueRobot();
        $queue->addQueue('同级互转【查询转账信息】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
            [
                "job_class" => '\app\robotapi\job\transfer\QueryTransferInfo',
                "transfer_records_id" => $transfer_records_id,
                "handle" => "QcPeerTransfer",   // 此处传入的参数是需要执行逻辑的方法名
                "callback_data" => $data['callback_data'],
            ]);
        return true;
    }


    /**
     * @param $advertiser_id_initiate
     * @param $advertiser_id_target
     * @param $money
     * @return void
     * @throws Exception
     * 同级互转继承返点比例
     */
    private function inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money)
    {
        $store_refund_model = new StoreRefund();
        $info = $store_refund_model->getOneRefundInfo($advertiser_id_initiate);
        if ($info) {
            $wallet = [];
            if ($info['credit'] >= $money){
                $wallet['credit'] = $money;
                $wallet['wallet'] = 0;
            }else{
                $wallet['credit'] = $info['credit'];
                $wallet['wallet'] = $money - $info['credit'];
            }
            $data = [
                'money' => $money,
                'store_id' => $info['store_id'],
                'discount_percentage' => $info['discount_percentage'],
                'platform_id' => $advertiser_id_target,
                'account_type' => $info['type'],
            ];
            $store_refund_model->addStoreRefundRecord($wallet, $data);
            $info->wallet -= $wallet['wallet'];
            $info->credit -= $wallet['credit'];
            $info->save(); // 扣除原本号的金额记录
        }
    }

}