<?php

namespace app\robotapi\job\tencent;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\TencentAccount;
use app\robotapi\model\TencentShareWallet;
use think\Cache;
use think\Db;
use think\Exception;
use app\robotapi\model\TencentTransferLog;
use app\robotapi\model\TencentStore;
use app\robotapi\model\TencentTransactionLog;
use app\robotapi\model\TencentWalletTransferLog;
class SubsequentOperations
{
    public function doJob($data)
    {
        try {
            $method = $data['handle'];
            if (!is_string($method)) {
                throw new Exception("handle 必须是字符串类型，当前值：" . json_encode($method));
            }

            if (!method_exists($this, $method)) {
                throw new Exception("找不到对应的方法：" . $method);
            }

            return $this->$method($data);
        }catch (Exception $e){
//            $this->callBack($data["callback_data"], "服务内部错误");
            throw new Exception($e->getMessage());   // 重新抛出异常
        }
    }


    private function TencentTransfer($data){
        $transfer_records_model = new TencentTransferLog();
        $transfer_records_data = $transfer_records_model->where(["id" => $data["transfer_records_id"]])->find();
        $store_model = new TencentStore();
        $store_info = $store_model->where("store_id", $transfer_records_data["store_id"])->lock(true)->find();
        $operate = $transfer_records_data["transfer_direction"] == 1 ? "腾讯广告转入" : "腾讯广告退款";
        $type = $transfer_records_data["account_type"] == 1 ? "（公）" : "（私）";
        $money_log_data = [
            'store_id' => $transfer_records_data['store_id'],
            'tencent_account_id' => $transfer_records_data['tencent_account_id'],
            'account_id' => $transfer_records_data['account_id'],
            'transfer_log_id' => $transfer_records_data['id'],
            'money' => $transfer_records_data['money'],
            'account_type' =>$transfer_records_data['account_type'],
            'rebate' => $transfer_records_data['rebate'],
            'discount_percentage' => $transfer_records_data['discount_percentage'],
            'create_time' => time(),
            'from' => 2
        ];
        Db::startTrans();
        try {
            if ($transfer_records_data['account_type'] == 1){
                $prefix = 'public_';
            }else{
                $prefix = 'private_';
            }
            if($transfer_records_data['transfer_direction'] == 1){
                $money_log_data['actual_money'] = $transfer_records_data['actual_money'];
                $money_log_data["deduction_balance"] = $transfer_records_data["deduction_balance"];
                $money_log_data['deduction_credit_limit'] = $transfer_records_data["deduction_credit_limit"];
                $money_log_data['type'] = 4;
                $money_log_data['explain'] = "转入腾讯广告账户[".$transfer_records_data['account_id']."]，返点：".$transfer_records_data['rebate']."，扣除余额：".$transfer_records_data['deduction_balance']."，扣除授信额度：".$transfer_records_data['deduction_credit_limit']."，实际扣除金额：".$transfer_records_data['actual_money']."【单位：元】";
                $money_log_data['balance_surplus'] = $store_info[$prefix.'money_tencent'];
                $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit_tencent'];
            }else{
                $money_log_data['type'] = 5;
                $money_log_data["actual_money"] = $transfer_records_data["actual_money"] - $transfer_records_data["rebate"];
                $money_log_data['explain'] = "腾讯广告账户[".$transfer_records_data['account_id']."]转出，转出金额：".$transfer_records_data['money']."，扣除返点：".$transfer_records_data['rebate']."，预计到账金额：".$transfer_records_data['actual_money'];
                if($store_info[$prefix.'spending_credit_limit_tencent'] >= $money_log_data['actual_money']){
                    $money = 0.00;
                    $credit_limit = (float)$money_log_data['actual_money'];
                    $spending_credit_limit = (float)$money_log_data['actual_money'];
                }else{
                    $money = (float)$money_log_data['actual_money'] - (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                    $credit_limit = (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                    $spending_credit_limit = (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                }
                $sql = $store_model->where([
                    'id'=>['=',$store_info['id']]
                ])
                    ->inc($prefix.'money_tencent',$money)
                    ->inc($prefix.'credit_limit_tencent',$credit_limit)
                    ->dec($prefix.'spending_credit_limit_tencent',$spending_credit_limit);
                $money_log_data["deduction_credit_limit"] = $spending_credit_limit;
                $money_log_data['explain'] .= "，归还已使用授信额度：".$spending_credit_limit."，实际到账金额：".$money."【单位：元】";
                $money_log_data['balance_surplus'] = $store_info[$prefix.'money_tencent'] + $money;
                $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit_tencent'] + $credit_limit;
                if(!$sql->update(["update_time" => time()])){
                    throw new Exception('金额变更失败');
                }
            }
            $log_model = new TencentTransactionLog();
            $logId = $log_model->insertGetId($money_log_data);
            if(!$logId){
                throw new Exception('金额变更记录失败');
            }
            $img_url = $this->createTransferImg($transfer_records_data);
            if (!$transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['image' => $img_url])) {
                throw new Exception('转账成功，状态更新失败');
            }

            $name = $transfer_records_data["transfer_direction"] == 1 ? "同步腾讯广告充值记录":"同步腾讯广告退款记录";
            $queueModel = new \app\common\model\Queue();
            $queueModel->addQueue($name, "app\job\SyncCharge",
                "syncCharge",
                ["log_id" => $data["transfer_records_id"], 'data' => $transfer_records_data],
                "tencent_transfer_log"
            );
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $bool = $this->checkRemaining($data["callback_data"]);

        if ($bool) {
            $msg = Cache::get($data["callback_data"]["msg_uuid"]."msg") ? Cache::get($data["callback_data"]["msg_uuid"]."msg") : "";
            $transfer_log_id = Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") ? Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") : "";
            $transfer_log_id .= $data["transfer_records_id"];
            $msg .= "{$operate}成功！\n钱包余额{$type}：" . $money_log_data["balance_surplus"] . "\n授信余额{$type}：" . $money_log_data["credit_limit_surplus"] . "\n已使用授信额度{$type}：" . number_format((($store_info[$prefix."spending_credit_limit_tencent"] + $store_info[$prefix."credit_limit_tencent"]) - $money_log_data["credit_limit_surplus"]), 2)."\n\n";
            $merge_img_url = $this->createMergeImg($transfer_log_id);
            $this->callBack($data["callback_data"], $msg, $merge_img_url);
            Cache::rm($data["callback_data"]["msg_uuid"]."msg");
            Cache::rm($data["callback_data"]["msg_uuid"]."transfer_log_id");
        }else{
            if($transfer_records_data['transfer_direction'] == 2){
                $msg = Cache::get($data["callback_data"]["msg_uuid"]."msg") ? Cache::get($data["callback_data"]["msg_uuid"]."msg") : "";
                $msg .= "{$operate}成功！\n钱包余额{$type}：" . $money_log_data["balance_surplus"] . "\n授信余额{$type}：" . $money_log_data["credit_limit_surplus"] . "\n已使用授信额度{$type}：" . number_format((($store_info[$prefix."spending_credit_limit_tencent"] + $store_info[$prefix."credit_limit_tencent"]) - $money_log_data["credit_limit_surplus"]), 2)."\n\n";
                Cache::set($data["callback_data"]["msg_uuid"]."msg", $msg, 1800);
            }
            $transfer_log_id = Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") ? Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") : "";
            $transfer_log_id .= $data["transfer_records_id"].',';
            Cache::set($data["callback_data"]["msg_uuid"]."transfer_log_id", $transfer_log_id, 1800);
        }
        return true;
    }



    private function TencentWalletTransfer($data){
        $transfer_records_model = new TencentWalletTransferLog();
        $transfer_records_data = $transfer_records_model->where(["id" => $data["transfer_records_id"]])->find();
        $store_model = new TencentStore();
        $store_info = $store_model->where("store_id", $transfer_records_data["store_id"])->lock(true)->find()->toArray();
        $operate = $transfer_records_data["transfer_direction"] == 1 ? "腾讯广告共享钱包转入" : "腾讯广告共享钱包退款";
        $type = $transfer_records_data["account_type"] == 1 ? "（公）" : "（私）";

        $money_log_data = [
            'store_id' => $store_info['store_id'],
            'swtl_id' => $transfer_records_data['id'],
            'sub_wallet_id' => $transfer_records_data['sub_wallet_id'],
            'money' => $transfer_records_data['money'],
            'account_type' =>$transfer_records_data['account_type'],
            'rebate' => $transfer_records_data['rebate'],
            'discount_percentage' => $transfer_records_data['discount_percentage'],
            'create_time' => time(),
            'from' => 2
        ];
        Db::startTrans();
        try {
            if ($transfer_records_data['account_type'] == 1){
                $prefix = 'public_';
            }else{
                $prefix = 'private_';
            }
            if($transfer_records_data['transfer_direction'] == 1){
                $money_log_data['actual_money'] = $transfer_records_data['actual_money'];
                $money_log_data["deduction_balance"] = $transfer_records_data["deduction_balance"];
                $money_log_data['deduction_credit_limit'] = $transfer_records_data["deduction_credit_limit"];
                $money_log_data['type'] = 8;
                $money_log_data['explain'] = "转入腾讯广告子钱包[".$transfer_records_data['sub_wallet_id']."]，返点：".$transfer_records_data['rebate']."，扣除余额：".$transfer_records_data['deduction_balance']."，扣除授信额度：".$transfer_records_data['deduction_credit_limit']."，实际扣除金额：".$transfer_records_data['actual_money']."【单位：元】";
                $money_log_data['balance_surplus'] = (float)$store_info[$prefix.'money_tencent'];
                $money_log_data['credit_limit_surplus'] = (float)$store_info[$prefix.'credit_limit_tencent'];
            }else{
                $money_log_data['type'] = 9;
                $money_log_data["actual_money"] = $transfer_records_data["actual_money"] - $transfer_records_data["rebate"];
                $money_log_data['explain'] = "腾讯广告子钱包[".$transfer_records_data['sub_wallet_id']."]转出，转出金额：".$transfer_records_data['money']."，扣除返点：".$transfer_records_data['rebate']."，预计到账金额：".$transfer_records_data['actual_money'];
                if($store_info[$prefix.'spending_credit_limit_tencent'] >= $money_log_data['actual_money']){
                    $money = 0.00;
                    $credit_limit = (float)$money_log_data['actual_money'];
                    $spending_credit_limit = (float)$money_log_data['actual_money'];
                }else{
                    $money = (float)$money_log_data['actual_money'] - (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                    $credit_limit = (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                    $spending_credit_limit = (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                }
                $sql = $store_model->where([
                    'id'=>['=',$store_info['id']]
                ])
                    ->inc($prefix.'money_tencent',$money)
                    ->inc($prefix.'credit_limit_tencent',$credit_limit)
                    ->dec($prefix.'spending_credit_limit_tencent',$spending_credit_limit);
                $money_log_data["deduction_credit_limit"] = $spending_credit_limit;
                $money_log_data['explain'] .= "，归还已使用授信额度：".$spending_credit_limit."，实际到账金额：".$money."【单位：元】";
                $money_log_data['balance_surplus'] = (float)$store_info[$prefix.'money_tencent'] + (float)$money;
                $money_log_data['credit_limit_surplus'] = (float)$store_info[$prefix.'credit_limit_tencent'] + (float)$credit_limit;
                if(!$sql->update(["update_time" => time()])){
                    throw new Exception('金额变更失败');
                }
            }
            $log_model = new TencentTransactionLog();
            $logId = $log_model->insertGetId($money_log_data);
            if(!$logId){
                throw new Exception('金额变更记录失败');
            }
            $img_url = $this->createTransferImg($transfer_records_data);
            if (!$transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['image' => $img_url])) {
                throw new Exception('转账成功，状态更新失败');
            }
            //添加同步转账记录任务
            //暂时转入账户才同步
            $name = $transfer_records_data["transfer_direction"] == 1 ? "同步腾讯广告共享钱包充值记录":"同步腾讯广告共享钱包退款记录";
            $queueModel = new \app\common\model\Queue();
            $queueModel->addQueue($name, "app\job\SyncCharge",
                "syncCharge",
                ["log_id" => $data["transfer_records_id"], 'data' => $transfer_records_data],
                "tencent_wallet_transfer_log"
            );
            Db::commit();
        }catch (Exception $e){
            Db::rollback();
            throw new Exception($e->getMessage()); // 重新抛出异常
        }
        $msg = "{$operate}成功！\n钱包余额{$type}：" . $money_log_data["balance_surplus"] . "\n授信余额{$type}：" . $money_log_data["credit_limit_surplus"] . "\n已使用授信额度{$type}：" . number_format((($store_info[$prefix."spending_credit_limit_tencent"] + $store_info[$prefix."credit_limit_tencent"]) - $money_log_data["credit_limit_surplus"]), 2);
        $this->callBack($data["callback_data"], $msg, $img_url);
        return true;
    }


    private function createTransferImg($transfer_records_data){
        if (isset($transfer_records_data['sub_wallet_id'])){

            $model = new TencentShareWallet();
            $info = $model->getByWalletId($transfer_records_data['sub_wallet_id']);
            $array = [
                'name' => $info['sub_wallet_name'],
                'id' => $info['sub_wallet_id'],
            ];
            $prefix = '共享钱包';
        }else{

            $model = new TencentAccount();
            $info = $model->getByAccountId($transfer_records_data['account_id']);
            $array = [
                'name' => $info['name'],
                'id' => $info['account_id'],
            ];
            $prefix = '';
        }
        $account_info = [
            'name' => '广州浣熊数字信息科技有限公司',
            'id' => 64568612,
        ];
        $money = number_format($transfer_records_data['money'], 2);
        if ($transfer_records_data['transfer_direction'] == 1) {
            $transfer_type = $prefix."加款";
            $transfer_in = $array['name'] . "\n转入方ID：" . $array['id'];
            $transfer_out = $account_info['name'] . "\n转出方ID：" . $account_info['id'];
        } else if ($transfer_records_data['transfer_direction'] == 2) {
            $transfer_type = $prefix."退款";
            $money = '-'.$money;
            $transfer_in = $account_info['name'] . "\n转入方ID：" . $account_info['id'];
            $transfer_out = $array['name'] . "\n转出方ID：" . $array['id'];
        }
        $img_data = [
            date('Y-m-d H:i:s',$transfer_records_data['update_time']),
            $transfer_out,
            $transfer_in,
            $transfer_type,
            $money,
            $transfer_records_data['order_uid']
        ];
        $day = date('Ymd');
        $path = ROOT_PATH . 'public/tencent_transfer_images/' . $day . '/';
        $file_name = (int)round(microtime(true) * 1000) . '.png';
        if (!file_exists($path)) {
            $created = mkdir($path, 0755, true);
            if (!$created) {
                // 错误处理
                throw new Exception("目录创建失败: {$path}");
            }
        }
        $res = generateTransferImg($img_data, [
            '转账时间',
            '转出方',
            '转入方',
            '转账类型',
            '转账金额',
            '订单号'
            ], $path, $file_name);
        if ($res) {
            return 'tencent_transfer_images/' . $day . '/' . $file_name;
        } else {
            throw new Exception($res);
        }
    }

    private function createMergeImg(string $transfer_id){
        $transfer_id_list = explode(',', $transfer_id);
        $model = new TencentTransferLog();
        $transfer_records_list = $model->where(["id" => ["in", $transfer_id_list]])->select();
        $combined_data = [];
        $headers = [
            '转账时间',
            '转出方',
            '转入方',
            '转账类型',
            '转账金额',
            '订单号'
        ];

        foreach ($transfer_records_list as $transfer_records_data) {
            if (isset($transfer_records_data['sub_wallet_id'])) {
                $model = new TencentShareWallet();
                $info = $model->getByWalletId($transfer_records_data['sub_wallet_id']);
                $array = [
                    'name' => $info['sub_wallet_name'],
                    'id' => $info['sub_wallet_id'],
                ];
                $prefix = '共享钱包';
            } else {
                $model = new TencentAccount();
                $info = $model->getByAccountId($transfer_records_data['account_id']);
                $array = [
                    'name' => $info['name'],
                    'id' => $info['account_id'],
                ];
                $prefix = '';
            }

            $account_info = [
                'name' => '广州浣熊数字信息科技有限公司',
                'id' => 64568612,
            ];

            $money = number_format($transfer_records_data['money'], 2);
            if ($transfer_records_data['transfer_direction'] == 1) {
                $transfer_type = $prefix."加款";
                $transfer_in = $array['name'] . "\n转入方ID：" . $array['id'];
                $transfer_out = $account_info['name'] . "\n转出方ID：" . $account_info['id'];
            } else if ($transfer_records_data['transfer_direction'] == 2) {
                $transfer_type = $prefix."退款";
                $money = '-'.$money;
                $transfer_in = $account_info['name'] . "\n转入方ID：" . $account_info['id'];
                $transfer_out = $array['name'] . "\n转出方ID：" . $array['id'];
            }

            $combined_data[] = [
                date('Y-m-d H:i:s',$transfer_records_data['update_time']),
                $transfer_out,
                $transfer_in,
                $transfer_type,
                $money,
                $transfer_records_data['order_uid']
            ];
        }

        $day = date('Ymd');
        $path = ROOT_PATH . 'public/tencent_transfer_images/' . $day . '/';
        $file_name = (int)round(microtime(true) * 1000) . '.png';

        if (!file_exists($path)) {
            $created = mkdir($path, 0755, true);
            if (!$created) {
                throw new Exception("目录创建失败: {$path}");
            }
        }

        $res = generateTransferImg($combined_data, $headers, $path, $file_name);

        if ($res) {
            return 'tencent_transfer_images/' . $day . '/' . $file_name;
        } else {
            throw new Exception($res);
        }
    }


    private function callback($data, $msg, $img_url = ''){
        $url = $data["url"];
        $msg = "您于" . date("Y-m-d H:i:s", $data["time"])."发起的请求结果如下：\n" . $msg;
        $msg_data = [
            "msg" => $msg,
        ];
        if(!empty($img_url)){
            $imagePath = ROOT_PATH . "public/" .$img_url;
            // 检查文件是否存在
            if (!file_exists($imagePath)) {
                var_dump('不存在');
                die;
            }
            $imageData = file_get_contents($imagePath);

            // 可以使用 gzip 压缩图片二进制数据
            $compressedData = gzencode($imageData, 9); // 9 是最高压缩等级
            $msg_data['img_data'] = base64_encode($compressedData);
        }
        $params = [
            "group_wxid" => $data["group_id"],
            "sender_name" => $data["sender_name"],
            "message" => $msg_data,
            "msg_wxid" => $data["msg_uuid"],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('回调请求', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',[
            "job_class" => '\app\robotapi\job\sendMsg\Send',
            "url" => $url,
            "params" => $params,
        ]);
    }


    private function checkRemaining($data){
        $queue = new QueueRobot();
        $count = $queue->getRemaining($data["msg_uuid"]);
        if ($count > 1){  // 检查是不是最后一条
            return false;
        }else{
            return true;
        }
    }
}