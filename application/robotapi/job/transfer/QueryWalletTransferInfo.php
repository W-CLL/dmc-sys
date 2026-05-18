<?php

namespace app\robotapi\job\transfer;

use app\robotapi\model\QueueRobot;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Env;
use think\Exception;

use app\robotapi\model\ShareWalletTransferLog as TransferLogModel;
use app\robotapi\model\Store as StoreModel;
use app\robotapi\model\StoreRefund as StoreRefundModel;
use app\robotapi\model\StoreMoneyLog as StoreMoneyLogModel;
use app\robotapi\model\QcShareWallet as WalletModel;


class QueryWalletTransferInfo
{
    public function doJob($data)
    {
        try {
            $store_model = new StoreModel();
            $transfer_log_model = new TransferLogModel();
            $transfer_data = $transfer_log_model->where(["id" => $data["swtl_id"]])->find();
            $transfer_detail = FundManagement::check_transfer_detail(
                Cache::get("qc_access_token"),
                Env::get('dmc_ad_config.advertiser_id'),
                'AGENT',
                generate_random_string(16),
                $transfer_data['transfer_serial']
            );
            if (!isset($transfer_detail['code']) || !isset($transfer_detail['message']) || $transfer_detail['code'] != 0 && $transfer_detail['message'] != "OK") {
                throw new Exception("查询转账信息失败");
            }
            $operate = $transfer_data["transfer_direction"] == 1 ? "共享钱包充值" : "共享钱包退款";
            Db::startTrans();
            try {
                switch ($transfer_detail['data']['transfer_status']){
                    case "TRANSFER_SUCCESS":
                        $store_info = $store_model->where("id", $transfer_data["store_id"])
//                            ->lock(true)
                            ->find();
                        $img_url = $this->createTransferImg($transfer_data,$transfer_detail);
                        if(!$transfer_log_model->where(["id" => $data["swtl_id"]])->update([
                            "status" => 1,
                            "image" => $img_url,
                            "update_time" => time()
                        ])){
                            throw new Exception("更新转账信息失败");
                        }
                        $store_money_log_data = $this->createStoreMoneyLog($store_info, $transfer_data);
                        $store_money_log_model = new StoreMoneyLogModel();
                        $money_log_id = $store_money_log_model->insertGetId($store_money_log_data);
                        if(!$money_log_id){
                            throw new Exception("资金记录写入失败");
                        }
                        if(!$this->increaseFees($store_money_log_data, $store_info)){
                            throw new Exception("增加dmc余额失败");
                        }
                        if(!$this->changeSubWalletMoneyTotal($transfer_data)){
                            throw new Exception("更新累计额度发生错误");
                        }
                        //添加同步转账记录任务
                        //暂时转入账户才同步
                        $name = $transfer_data["transfer_direction"] == 1 ? "同步共享钱包充值记录":"同步共享钱包退款记录";
                        $queueModel = new \app\common\model\Queue();
                        $queueModel->addQueue($name, "app\job\SyncCharge",
                            "syncCharge",
                            ["log_id" => $data["swtl_id"], 'data' => $transfer_data],
                            "share_wallet_transfer_log"
                        );
                        Db::commit();
                        $suc_msg = $transfer_data['sub_wallet_id'];
                        break;
                    case "TRANSFER_FAILED":
                        if(!$transfer_log_model->where(["id" => $data["swtl_id"]])->update([
                            "status" => 2,
                            "fail_reason" => $transfer_detail['data']['transfer_wallet_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'],
                            "update_time" => time()
                        ])){
                            throw new Exception("更新转账信息失败");
                        }
                        if(!$this->refund($transfer_data)){
                            throw new Exception("退款失败");
                        }
                        Db::commit();
                        $err_msg = "{$transfer_data['sub_wallet_id']}失败原因：" . $transfer_detail['data']['transfer_wallet_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                        break;
                    default:
                        if(Cache::get("swtl_id".$data["swtl_id"])){
                            $this->callback($data, "尊敬的用户您好，由于千川接口请求网络异常导致于该笔充值延缓，请您自行去千川账户查询是否到账，带来不便敬请谅解！");
                            Db::commit();
                        }
                        return false;
                }
            } catch (Exception $e) {
                Db::rollback();
                throw new Exception($e->getMessage()); // 重新抛出异常
            }
            // 发起回调，扔队列
            $bool = $this->checkRemaining($data["callback_data"]);

            if ($bool) {
                $store = $store_model->where("id", $transfer_data["store_id"])->find();
                $count = Cache::get($data["callback_data"]["msg_uuid"]."count") ? Cache::get($data["callback_data"]["msg_uuid"]."count") : 0;
                $total_money = Cache::get($data["callback_data"]["msg_uuid"]."total_money") ? Cache::get($data["callback_data"]["msg_uuid"]."total_money") : 0;
                $cache_suc_id = Cache::get($data["callback_data"]["msg_uuid"]."suc_msg") ? Cache::get($data["callback_data"]["msg_uuid"]."suc_msg") : "";
                $transfer_log_id = Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") ? Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") : "";
                $cache_err_msg = Cache::get($data["callback_data"]["msg_uuid"]."err_msg") ? Cache::get($data["callback_data"]["msg_uuid"]."err_msg") : "";
                if (isset($err_msg)){
                    $cache_err_msg .= $err_msg.'|';
                    $cache_err_msg = str_replace("|", "\n", $cache_err_msg);
                }
                if (isset($suc_msg)){
                    $transfer_log_id .= $data["swtl_id"];
                    $count++;
                    $cache_suc_id .= $suc_msg.'|';
                    $total_money += $transfer_data["actual_money"];
                }
                $msg = "{$operate}！\n总成功次数：{$count}\n操作总金额：{$total_money}\n";
                $msg .= "执行成功的千川ID：{$cache_suc_id}\n";
                $msg .= "钱包余额（公）：" . $store["public_money"] . "\n授信余额（公）：" . $store["public_credit_limit"] . "\n已用授信（公）：" . $store['public_spending_credit_limit'] ."\n";
                $msg .= "钱包余额（私）：" . $store["private_money"] . "\n授信余额（私）：" . $store["private_credit_limit"] . "\n已用授信（私）：" . $store['private_spending_credit_limit'] ."\n";
                $msg .= $cache_err_msg;
                $merge_img_url = $this->createMergeImg($transfer_log_id);
                $this->callBack($data, $msg, $merge_img_url);
                Cache::rm($data["callback_data"]["msg_uuid"]."transfer_log_id");
                Cache::rm($data["callback_data"]["msg_uuid"]."count");
                Cache::rm($data["callback_data"]["msg_uuid"]."total_money");
                Cache::rm($data["callback_data"]["msg_uuid"]."err_msg");
                Cache::rm($data["callback_data"]["msg_uuid"]."suc_msg");
            }else{
                if (isset($err_msg)){
                    $cache_err_msg = Cache::get($data["callback_data"]["msg_uuid"]."err_msg") ? Cache::get($data["callback_data"]["msg_uuid"]."err_msg") : "";
                    $cache_err_msg .= $err_msg.'|';
                    Cache::set($data["callback_data"]["msg_uuid"]."err_msg", $cache_err_msg, 1800);  // 错误信息
                }
                if (isset($suc_msg)){
                    $count = Cache::get($data["callback_data"]["msg_uuid"]."count") ? Cache::get($data["callback_data"]["msg_uuid"]."count") : 0;
                    $count++;
                    Cache::set($data["callback_data"]["msg_uuid"]."count", $count, 1800);  // 统计次数
                    $total_money = Cache::get($data["callback_data"]["msg_uuid"]."total_money") ? Cache::get($data["callback_data"]["msg_uuid"]."total_money") : 0;
                    $total_money += $transfer_data["actual_money"];
                    Cache::set($data["callback_data"]["msg_uuid"]."total_money", $total_money, 1800);  // 统计金额
                    $transfer_log_id = Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") ? Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") : "";
                    $transfer_log_id .= $data["swtl_id"].',';
                    Cache::set($data["callback_data"]["msg_uuid"]."transfer_log_id", $transfer_log_id, 1800);  // 统计日志id
                    $cache_suc_msg = Cache::get($data["callback_data"]["msg_uuid"]."suc_msg") ? Cache::get($data["callback_data"]["msg_uuid"]."suc_msg") : "";
                    $cache_suc_msg .= $suc_msg.'|';
                    Cache::set($data["callback_data"]["msg_uuid"]."suc_msg", $cache_suc_msg, 1800);  // 成功id
                }
            }
            return true;
        }catch (Exception $e){
//            $this->callBack($data, "服务内部错误");
            throw new Exception($e->getMessage());
        }

    }



    private function createStoreMoneyLog($store_info, $transfer_data){
        $money_log_data = [
            'store_id'                  => $store_info['id'],
            'swtl_id'                   => $transfer_data['id'],
            'money'                     => $transfer_data['money'],
            'account_type'              => $transfer_data['account_type'],
            'rebate'                    => $transfer_data['rebate'],
            'discount_percentage'       => $transfer_data['discount_percentage'],
            'create_time'               => $transfer_data['create_time'],
            "from"                      => 2,
        ];

        $prefix = $transfer_data['account_type'] == 1 ? "public_" : "private_";
        switch ($transfer_data['transfer_direction']){
            case 1:
                $money_log_data['actual_money'] = $transfer_data['actual_money'];
                $money_log_data["deduction_balance"] = $transfer_data["deduction_balance"];
                $money_log_data['deduction_credit_limit'] = $transfer_data["deduction_credit_limit"];
                $money_log_data['type'] = 8;
                $money_log_data['explain'] = "转入子钱包[".$transfer_data['sub_wallet_id']."]，返点：".$transfer_data['rebate']."，扣除余额：".$transfer_data['deduction_balance']."，扣除授信额度：".$transfer_data['deduction_credit_limit']."，实际扣除金额：".$transfer_data['actual_money']."【单位：元】";
                $money_log_data['balance_surplus'] = $store_info[$prefix.'money'];
                $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'];
                return $money_log_data;
            case 2:
                $money_log_data['type'] = 9;
                $money_log_data["actual_money"] = $transfer_data["actual_money"] - $transfer_data["rebate"];
                $money_log_data['explain'] = "子钱包[".$transfer_data['sub_wallet_id']."]转出，转出金额：".$transfer_data['money']."，扣除返点：".$transfer_data['rebate']."，预计到账金额：".$transfer_data['actual_money'];
                if($store_info[$prefix.'spending_credit_limit'] >= $money_log_data['actual_money']){
                    $money_log_data["deduction_credit_limit"] = (float)$money_log_data['actual_money'];
                    $money_log_data['explain'] .= "，归还已使用授信额度：".(float)$money_log_data['actual_money']."，实际到账金额：0.00【单位：元】";
                    $money_log_data['balance_surplus'] = $store_info[$prefix.'money'];
                    $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'] + (float)$money_log_data['actual_money'];
                }else{
                    $money_log_data["deduction_credit_limit"] = (float)$store_info[$prefix.'spending_credit_limit'];
                    $money_log_data['explain'] .= "，归还已使用授信额度：".(float)$store_info[$prefix.'spending_credit_limit']."，实际到账金额：".((float)$money_log_data['actual_money'] - (float)$store_info[$prefix.'spending_credit_limit'])."【单位：元】";
                    $money_log_data['balance_surplus'] = $store_info[$prefix.'money'] + ((float)$money_log_data['actual_money'] - (float)$store_info[$prefix.'spending_credit_limit']);
                    $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'] + (float)$store_info[$prefix.'spending_credit_limit'];
                }
                return $money_log_data;
        }
    }


    private function increaseFees($store_money_log_data, $store_info){
        if ($store_money_log_data["type"] == 9){
            $store_model = new StoreModel();
            $sql = $store_model->where("id", $store_money_log_data["store_id"]);
            $prefix = $store_money_log_data["account_type"] == 1 ? "public_" : "private_";
            if($store_info[$prefix.'spending_credit_limit'] >= $store_money_log_data['actual_money']){
                $money = 0.00;
                $credit_limit = (float)$store_money_log_data['actual_money'];
                $spending_credit_limit = (float)$store_money_log_data['actual_money'];
            }else{
                $money = (float)$store_money_log_data['actual_money'] - (float)$store_info[$prefix.'spending_credit_limit'];
                $credit_limit = (float)$store_info[$prefix.'spending_credit_limit'];
                $spending_credit_limit = (float)$store_info[$prefix.'spending_credit_limit'];
            }
            try {
                return $sql->inc($prefix.'money',$money)
                    ->inc($prefix.'credit_limit',$credit_limit)
                    ->dec($prefix.'spending_credit_limit',$spending_credit_limit)
                    ->update(["update_time" => time()]);
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }


    private function changeSubWalletMoneyTotal($data){
        $wallet_model = new WalletModel();
        $sql = $wallet_model->where(['sub_wallet_id' => $data['sub_wallet_id']]);
        $account_type = $data['account_type'] == 1 ? 'public_' : 'private_';
        switch ($data['transfer_direction']) {
            case 1:
                $sql = $sql->inc("transfer_in_sum_".$account_type."cash", $data['actual_money'])
                    ->inc("transfer_in_sum_".$account_type."vr", $data['money']);
                break;
            default:
                $sql = $sql->inc("transfer_in_sum_".$account_type."cash", $data['actual_money'])
                    ->inc("transfer_in_sum_".$account_type."vr", $data['money'] - $data['rebate']);
                break;
        }
        try {
            return $sql->update();
        }catch (Exception $e){
            return false;
        }
    }

    private function refund($transfer_data){
        if ($transfer_data["transfer_direction"] == 1) {
            $store_model = new StoreModel();
            $sql = $store_model->where("id", $transfer_data["store_id"]);
            $prefix = $transfer_data["account_type"] == 1 ? "public_" : "private_";
            try {
                $store_refund_model = new StoreRefundModel();
                $store_refund_model->getRealRefundRebate($transfer_data,2);  // 删除记录
                return $sql->inc($prefix."money", $transfer_data["deduction_balance"])
                    ->inc($prefix."credit_limit", $transfer_data["deduction_credit_limit"])
                    ->dec($prefix."spending_credit_limit", $transfer_data["deduction_credit_limit"])
                    ->update(["update_time" => time()]);
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }

    private function createTransferImg($transfer_data,$transfer_detail){
        if($transfer_detail['data']['transfer_status'] != 'TRANSFER_SUCCESS'){
            return '';
        }
        $transfer_info = $transfer_detail['data']['transfer_wallet_record_list'][0];
        $main_wallet_info = [
            'name' => "广州斑马数字科技有限公司共享钱包",
            'wallet_id' => $transfer_info['main_wallet_id']
        ];
        $res = FundManagement::get_wallet_info_list(
            Cache::get("qc_access_token"),
            Env::get('dmc_ad_config.advertiser_id'),
            json_encode([$transfer_info['sub_wallet_id']]),
            'AGENT');
        if($res['code'] != 0){
            throw new Exception("获取钱包信息失败");
        }
        $sub_wallet_info = [
            'name' => $res['data']['wallet_info'][0]['common_wallet_info']['wallet_name'],
            'wallet_id' => $transfer_info['sub_wallet_id']
        ];
        $money = number_format($transfer_data['money'], 2);
        if ($transfer_data['transfer_direction'] == 1) {
            $transfer_type = "加款";
            $transfer_in = $sub_wallet_info['name'] . "\n钱包ID：" . $sub_wallet_info['wallet_id'];
            $transfer_out = $main_wallet_info['name'] . "\n钱包ID：" . $main_wallet_info['wallet_id'];
        } else if ($transfer_data['transfer_direction'] == 2) {
            $transfer_type = "退款";
            $money = '-'.$money;
            $transfer_in = $main_wallet_info['name'] . "\n钱包ID：" . $main_wallet_info['wallet_id'];
            $transfer_out = $sub_wallet_info['name'] . "\n钱包ID：" . $sub_wallet_info['wallet_id'];
        }
        $img_data = [
            $transfer_detail['data']['transfer_finish_time'],
            $transfer_out,
            $transfer_in,
            $transfer_type,
            '巨量广告/千川/本地推',
            $money,
            'OPENAPI'];
        $day = date('Ymd');
        $path = ROOT_PATH . 'public/share_wallet_images/' . $day . '/';
        $file_name = (int)round(microtime(true) * 1000) . '.png';
        if (!file_exists($path)) {
            $created = mkdir($path, 0755, true);
            if (!$created) {
                // 错误处理
                throw new Exception("目录创建失败: {$path}");
            }
        }
        $headerTexts = ['转账时间', '转出方', '转入方', '转账类型', '业务平台', '转账总金额', '操作人'];
        $res = generateTransferImg($img_data, $headerTexts, $path, $file_name);
        if ($res) {
            return 'share_wallet_images/' . $day . '/' . $file_name;
        } else {
            throw new Exception($res);
        }
    }


    private function createMergeImg(string $transfer_id){
        $transfer_id_list = explode(',', $transfer_id);
        $model = new TransferLogModel();
        $transfer_records_list = $model->where(["id" => ["in", $transfer_id_list]])->select();
        $result = array_column($transfer_records_list, 'sub_wallet_id');
        // 确保每个元素都是整数
        $result = array_map('intval', $result);
        $res = FundManagement::get_wallet_info_list(
            Cache::get("qc_access_token"),
            Env::get('dmc_ad_config.advertiser_id'),
            json_encode($result),
            'AGENT')['data'];
        foreach ($res["wallet_info"] as $item){
            $wallet_info[$item['wallet_id']] = $item['common_wallet_info']['wallet_name'];
        }
        $combined_data = [];
        $headers = [];

        foreach ($transfer_records_list as $transfer_records_data) {
            $model = new WalletModel();
            $info = $model->getBySubWalletId($transfer_records_data['sub_wallet_id']);
            $array = [
                'name' => $wallet_info[$info['sub_wallet_id']],
                'id' => $info['sub_wallet_id'],
            ];
            $prefix = '';

            $main_wallet_info = [
                'name' => "广州斑马数字科技有限公司共享钱包",
                'wallet_id' => $transfer_records_data['main_wallet_id']
            ];



            $money = number_format($transfer_records_data['money'], 2);
            if ($transfer_records_data['transfer_direction'] == 1) {
                $transfer_type = $prefix."加款";
                $transfer_in = $array['name'] . "\n转入方ID：" . $array['id'];
                $transfer_out = $main_wallet_info['name'] . "\n转出方ID：" . $main_wallet_info['wallet_id'];
            } else if ($transfer_records_data['transfer_direction'] == 2) {
                $transfer_type = $prefix."退款";
                $money = '-'.$money;
                $transfer_in = $main_wallet_info['name'] . "\n转入方ID：" . $main_wallet_info['wallet_id'];
                $transfer_out = $array['name'] . "\n转出方ID：" . $array['id'];
            }

            $combined_data[] = [
                date('Y-m-d H:i:s',$transfer_records_data['update_time']),
                $transfer_out,
                $transfer_in,
                $transfer_type,
                '巨量广告/千川/本地推',
                $money,
                'OPENAPI'];
            unset($array);
        }

        $day = date('Ymd');
        $path = ROOT_PATH . 'public/share_wallet_images/' . $day . '/';
        $file_name = (int)round(microtime(true) * 1000) . '.png';

        if (!file_exists($path)) {
            $created = mkdir($path, 0755, true);
            if (!$created) {
                throw new Exception("目录创建失败: {$path}");
            }
        }

        $res = generateTransferImg($combined_data, $headers, $path, $file_name);

        if ($res) {
            return 'share_wallet_images/' . $day . '/' . $file_name;
        } else {
            throw new Exception($res);
        }
    }



    private function checkRemaining($data){
        $queue = new QueueRobot();
        $count = $queue->qcWalletGetRemaining($data["msg_uuid"]);
        if ($count > 1){  // 检查是不是最后一条
            return false;
        }else{
            return true;
        }
    }




    private function callback($data, $msg, $img_url = ''){
        $url = $data["callback_data"]["url"];
        $msg = "您于" . date("Y-m-d H:i:s", $data["callback_data"]["time"])."发起的请求结果如下：\n" . $msg;
        $msg_data = ["msg" => $msg];
        if(!empty($img_url)){
            $imagePath = ROOT_PATH . "public/" .$img_url;
            $imageData = file_get_contents($imagePath);
            // 可以使用 gzip 压缩图片二进制数据
            $compressedData = gzencode($imageData, 9); // 9 是最高压缩等级
            $msg_data['img_data'] = base64_encode($compressedData);
        }
        $params = [
            "group_wxid" => $data["callback_data"]["group_id"],
            "sender_name" => $data["callback_data"]["sender_name"],
            "message" => $msg_data,
            "msg_wxid" => $data["callback_data"]["msg_uuid"],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('回调请求', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',[
            "job_class" => '\app\robotapi\job\sendMsg\Send',
            "url" => $url,
            "params" => $params,
        ]);
    }

}