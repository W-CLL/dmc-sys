<?php

namespace app\robotapi\job\transfer;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\TransferRecords;
use app\robotapi\model\Store;
use app\robotapi\model\StoreMoneyLog;
use app\robotapi\model\Company;
use think\Db;
use think\Cache;
use think\Env;
use think\Exception;

use jlqc\FundManagement;

class QueryTransferInfo
{
    public function doJob($data)
    {
        try {
            $transfer_records_model = new TransferRecords();
            $transfer_records_data = $transfer_records_model->where(["id" => $data["transfer_records_id"]])->find();
            $transfer_detail_data = FundManagement::transfer_detail(
                Cache::get("qc_access_token"),
                generate_random_string(16),
                $data["agent_id"],
                $transfer_records_data["transfer_serial"]);
            if (!isset($transfer_detail_data['code']) || !isset($transfer_detail_data['message']) || $transfer_detail_data['code'] != 0 && $transfer_detail_data['message'] != "OK") {
                throw new Exception("查询转账信息失败");
            }
            $method = $data['handle'];
            if (!is_string($method)) {
                throw new Exception("handle 必须是字符串类型，当前值：" . json_encode($method));
            }

            if (!method_exists($this, $method)) {
                throw new Exception("找不到对应的方法：" . $method);
            }

            return $this->$method($transfer_records_data, $transfer_detail_data, $data);
        }catch (Exception $e){
//            $this->callBack($data["callback_data"], "服务内部错误");
            throw new Exception($e->getMessage());   // 重新抛出异常
        }


    }

    private function QcPeerTransfer($transfer_records_data, $transfer_detail_data, $data)
    {
        $transfer_records_model = new TransferRecords();
        $img_url = '';
        switch ($transfer_detail_data['data']['transfer_status']){
            case 'TRANSFER_FAILED':
                $transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                $msg = "同级互转失败\n失败原因：" . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                break;
            case 'TRANSFER_SUCCESS':
                $img_url = $this->createTransferImg($transfer_records_data,$transfer_detail_data);
                $transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 1, 'image' => $img_url]);
                $msg = "同级互转成功";
                break;
            default :
                return false;
        }
        // 发起回调，扔队列
        $this->callBack($data["callback_data"], $msg, $img_url);
        return true;
    }


    private function QcAccountTransfer($transfer_records_data, $transfer_detail_data, $data)
    {
        $transfer_records_model = new TransferRecords();
        $store_model = new Store();
        $operate = $transfer_records_data["transfer_direction"] == 1 ? "千川充值" : "千川退款";
        switch ($transfer_detail_data['data']['transfer_status']){
            case 'TRANSFER_FAILED':
                if(!$transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']])){
                    throw new Exception("更新转账状态失败");
                }
                // 退款处理
                if (!$this->refund($transfer_records_data)){
                    throw new Exception("退款失败");
                }
                $err_msg = "{$transfer_records_data['advertiser_id']}失败原因：" . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                break;
            case 'TRANSFER_SUCCESS':
                Db::startTrans();
                try {
                    $store_money_log_model = new StoreMoneyLog();
                    $store_info = $store_model->where("id", $transfer_records_data["store_id"])
//                        ->lock(true)
                        ->find();
                    $money_log_data = $this->buildMoneyLog($store_info, $transfer_records_data);
                    $money_log_id = $store_money_log_model->insertGetId($money_log_data);
                    if (!$money_log_id) {
                        throw new Exception('转账成功，资金记录写入失败');
                    }
                    if(!$this->increaseFees($money_log_data, $store_info)){
                        throw new Exception("增加dmc余额失败");
                    }
                    $img_url = $this->createTransferImg($transfer_records_data,$transfer_detail_data);
                    if (!$transfer_records_model->where(["id" => $data["transfer_records_id"]])->update(['status' => 1, 'image' => $img_url])) {
                        throw new Exception('转账成功，状态更新失败');
                    }
                    //添加同步转账记录任务
                    //暂时转入账户才同步
                    $name = $transfer_records_data["transfer_direction"] == 1 ? "同步备款充值记录":"同步备款退款记录";
                    $queueModel = new \app\common\model\Queue();
                    $queueModel->addQueue($name, "app\job\SyncCharge",
                        "syncCharge",
                        ["log_id" => $data["transfer_records_id"], 'data' => $transfer_records_data],
                        "transfer_records"
                    );
                    Db::commit();
                    $suc_msg = $transfer_records_data['advertiser_id'];
                    break;
                }catch (Exception $e){
                    Db::rollback();
                    throw new Exception($e->getMessage()); // 重新抛出异常
                }
            default :
                if(Cache::get("transfer_records_id".$data["transfer_records_id"])){
                    $this->callback($data["callback_data"], "尊敬的用户您好，由于千川接口请求网络异常导致于该笔充值延缓，请您自行去千川账户查询是否到账，带来不便敬请谅解！");
                }
                return false;
        }
        // 发起回调，扔队列
        $bool = $this->checkRemaining($data["callback_data"]);

        if ($bool) {
            $store = $store_model->where("id", $transfer_records_data["store_id"])->find();
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
                $transfer_log_id .= $data["transfer_records_id"];
                $count++;
                $cache_suc_id .= $suc_msg.'|';
                $total_money += $transfer_records_data["actual_money"];
            }
            $msg = "{$operate}！\n总成功次数：{$count}\n操作总金额：{$total_money}\n";
            $msg .= "执行成功的千川ID：{$cache_suc_id}\n";
            $msg .= "钱包余额（公）：" . $store["public_money"] . "\n授信余额（公）：" . $store["public_credit_limit"] . "\n已用授信（公）：" . $store['public_spending_credit_limit'] ."\n";
            $msg .= "钱包余额（私）：" . $store["private_money"] . "\n授信余额（私）：" . $store["private_credit_limit"] . "\n已用授信（私）：" . $store['private_spending_credit_limit'] ."\n";
            $msg .= $cache_err_msg;
            $merge_img_url = $this->createMergeImg($transfer_log_id);
            $this->callBack($data["callback_data"], $msg, $merge_img_url);
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
                $total_money += $transfer_records_data["actual_money"];
                Cache::set($data["callback_data"]["msg_uuid"]."total_money", $total_money, 1800);  // 统计金额
                $transfer_log_id = Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") ? Cache::get($data["callback_data"]["msg_uuid"]."transfer_log_id") : "";
                $transfer_log_id .= $data["transfer_records_id"].',';
                Cache::set($data["callback_data"]["msg_uuid"]."transfer_log_id", $transfer_log_id, 1800);  // 统计日志id
                $cache_suc_msg = Cache::get($data["callback_data"]["msg_uuid"]."suc_msg") ? Cache::get($data["callback_data"]["msg_uuid"]."suc_msg") : "";
                $cache_suc_msg .= $suc_msg.'|';
                Cache::set($data["callback_data"]["msg_uuid"]."suc_msg", $cache_suc_msg, 1800);  // 成功id
            }
        }
        return true;
    }


    private function buildMoneyLog($store_info, $transfer_records_data){
        $money_log = [
            "store_id" => $store_info['id'],
            "company_id" => $transfer_records_data['company_id'],
            "advertiser_id" => $transfer_records_data['advertiser_id'],
            "transfer_records_id" => $transfer_records_data['id'],
            "account_type" => $transfer_records_data['account_type'],
            "money" => $transfer_records_data['money'],
            "rebate" => $transfer_records_data["rebate"],
            "discount_percentage" => $transfer_records_data['discount_percentage'],
            "create_time" => $transfer_records_data['create_time'],
            "from" => 2
        ];
        $prefix = $transfer_records_data['account_type'] == 1 ? "public_" : "private_";
        switch ($transfer_records_data['transfer_direction']){
            case 1:
                $money_log["actual_money"] = $transfer_records_data["actual_money"];
                $money_log["deduction_balance"] = $transfer_records_data["deduction_balance"];
                $money_log["deduction_credit_limit"] = $transfer_records_data["deduction_credit_limit"];
                $money_log['type'] = 4;
                $money_log['explain'] = "转入千川" . $transfer_records_data['money'] . "元,扣除返点" . $transfer_records_data["rebate"] . "元,实际扣款" . $transfer_records_data["actual_money"] . "元";
                if ($transfer_records_data["deduction_credit_limit"] > 0) {
                    $money_log["explain"] .= ",扣除余额:" . $transfer_records_data["deduction_balance"] . ",扣除授信额度:" . $transfer_records_data["deduction_credit_limit"];
                }
                $money_log['balance_surplus'] = $store_info[$prefix.'money'];
                $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'];
                return $money_log;
            case 2:
                $money_log['type'] = 5;
                $money_log["actual_money"] = $transfer_records_data["money"] - $transfer_records_data["rebate"];
                $money_log['explain'] = "千川转出" . $transfer_records_data['money'] . "元,扣除返点" . $transfer_records_data["rebate"] . "元,到账" . $money_log["actual_money"] . "元";
                if ($store_info[$prefix."spending_credit_limit"] > 0) {
                    if ($store_info[$prefix."spending_credit_limit"] >= $money_log["actual_money"]) {
                        $money_log["deduction_credit_limit"] = $money_log["actual_money"];
                        $money_log["explain"] .= ",已使用授信余额扣除:" . $money_log["actual_money"] . "实际到账:0";
                        $money_log['balance_surplus'] = $store_info[$prefix.'money'];
                        $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'] + $money_log["actual_money"];
                    } else {
                        $money_log["deduction_credit_limit"] = $store_info[$prefix."spending_credit_limit"];
                        $actual_money = $money_log["actual_money"] - $store_info[$prefix."spending_credit_limit"];
                        $money_log["explain"] .= ",已使用授信余额扣除:" . $store_info[$prefix."spending_credit_limit"] . ",实际到账:" . $actual_money;
                        $money_log['balance_surplus'] = $store_info[$prefix.'money'] + $actual_money;
                        $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'] + $store_info[$prefix."spending_credit_limit"];
                    }
                } else {
                    $money_log['balance_surplus'] = $store_info[$prefix.'money'] + $money_log["actual_money"];
                    $money_log['credit_limit_surplus'] = $store_info[$prefix.'credit_limit'];
                }
                return $money_log;
        }
    }


    private function refund($data){
        if ($data["transfer_direction"] == 1) {
            $store_model = new Store();
            $sql = $store_model->where("id", $data["store_id"]);
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";
            try {
                $store_refund_model = new StoreRefund();
                $store_refund_model->getRealRefundRebate($data);  // 删除记录
                return $sql->inc($prefix."money", $data["deduction_balance"])
                    ->inc($prefix."credit_limit", $data["deduction_credit_limit"])
                    ->dec($prefix."spending_credit_limit", $data["deduction_credit_limit"])
                    ->update(["update_time" => time()]);
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }


    private function increaseFees($money_log, $store_info){
        if ($money_log["type"] == 5){
            $store_model = new Store();
            $sql = $store_model->where("id", $money_log["store_id"]);
            $prefix = $money_log["account_type"] == 1 ? "public_" : "private_";
            if ($store_info[$prefix."spending_credit_limit"] > 0) {
                if ($store_info[$prefix."spending_credit_limit"] >= $money_log["actual_money"]) {
                    $sql->dec($prefix."spending_credit_limit", $money_log["actual_money"])
                        ->inc($prefix."credit_limit", $money_log["actual_money"]);
                } else {
                    $sql->inc($prefix."money", $money_log["actual_money"] - $store_info[$prefix."spending_credit_limit"])
                        ->inc($prefix."credit_limit", $store_info[$prefix."spending_credit_limit"])
                        ->dec($prefix."spending_credit_limit", $store_info[$prefix."spending_credit_limit"]);
                }
            } else {
                $sql->inc($prefix."money", $money_log["actual_money"]);
            }
            try {
                return $sql->update(["update_time" => time()]);
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }

    private function createTransferImg($transfer_records_data,$transfer_detail_data){
        if($transfer_detail_data['data']['transfer_status'] != 'TRANSFER_SUCCESS'){
            return '';
        }
        $transfer_info = $transfer_detail_data['data']['transfer_target_record_list'][0];
        $company_model = new Company();
        $target_account_info = $company_model->where(['advertiser_id' => $transfer_info['target_account_id']])->find();
        $account_info = $company_model->where(['advertiser_id' => $transfer_info['account_id']])->find();
        if($transfer_info['account_id'] == "1739518270441480"){
            $account_info['name'] = "广州斑马数字科技有限公司";
            $account_info['advertiser_id'] = $transfer_info['account_id'];
            $account_info['company_name'] = "广州斑马数字科技有限公司";
        }elseif($transfer_info['account_id'] == "1818673832986633"){
            $account_info['name'] = "广州斑马数字科技有限公司-JDC";
            $account_info['advertiser_id'] = $transfer_info['account_id'];
            $account_info['company_name'] = "广州斑马数字科技有限公司-JDC";
        }
        $money = number_format($transfer_records_data['money'], 2);
        if ($transfer_records_data['transfer_direction'] == 1) {
            $transfer_type = "加款";
            $transfer_in = $target_account_info['name'] . "\n转入方ID：" . $target_account_info['advertiser_id'];
            $transfer_out = $account_info['name'] . "\n转出方ID：" . $account_info['advertiser_id'];
        } else if ($transfer_records_data['transfer_direction'] == 2) {
            $transfer_type = "退款";
            $money = '-'.$money;
            $transfer_in = $account_info['name'] . "\n转入方ID：" . $account_info['advertiser_id'];
            $transfer_out = $target_account_info['name'] . "\n转出方ID：" . $target_account_info['advertiser_id'];
        }
        if ($account_info['company_name'] == $target_account_info['company_name']) {
            $transfer_type = "同级账户转账";
        }
        $img_data = [
            $transfer_detail_data['data']['transfer_finish_time'],
            $transfer_out,
            $transfer_in,
            $transfer_type,
            '通用',
            $money,
            '账户余额',
            'OPENAPI'];
        $day = date('Ymd');
        $path = ROOT_PATH . 'public/transfer_images/' . $day . '/';
        $file_name = (int)round(microtime(true) * 1000) . '.png';
        if (!file_exists($path)) {
            $created = mkdir($path, 0755, true);
            if (!$created) {
                // 错误处理
                throw new Exception("目录创建失败: {$path}");
            }
        }
        $res = generateTransferImg($img_data, [], $path, $file_name);
        if ($res) {
            return 'transfer_images/' . $day . '/' . $file_name;
        } else {
            throw new Exception($res);
        }
    }

    private function checkRemaining($data){
        $queue = new QueueRobot();
        $count = $queue->qcGetRemaining($data["msg_uuid"]);
        if ($count > 1){  // 检查是不是最后一条
            return false;
        }else{
            return true;
        }
    }



    private function createMergeImg(string $transfer_id){
        $transfer_id_list = explode(',', $transfer_id);
        $model = new TransferRecords();
        $transfer_records_list = $model->where(["id" => ["in", $transfer_id_list]])->select();
        $combined_data = [];
        $headers = [];

        foreach ($transfer_records_list as $transfer_records_data) {
            $model = new Company();
            $info = $model->getByAdvId($transfer_records_data['advertiser_id']);
            $array = [
                'name' => $info['name'],
                'id' => $info['advertiser_id'],
            ];
            $prefix = '';

            if($info['agent_id'] == "1739518270441480"){
                $account_info['name'] = "广州斑马数字科技有限公司";
                $account_info['advertiser_id'] = $info['agent_id'];
                $account_info['company_name'] = "广州斑马数字科技有限公司";
            }elseif($info['agent_id'] == "1818673832986633"){
                $account_info['name'] = "广州斑马数字科技有限公司-JDC";
                $account_info['advertiser_id'] = $info['agent_id'];
                $account_info['company_name'] = "广州斑马数字科技有限公司-JDC";
            }

            $money = number_format($transfer_records_data['money'], 2);
            if ($transfer_records_data['transfer_direction'] == 1) {
                $transfer_type = $prefix."加款";
                $transfer_in = $array['name'] . "\n转入方ID：" . $array['id'];
                $transfer_out = $account_info['name'] . "\n转出方ID：" . $account_info['advertiser_id'];
            } else if ($transfer_records_data['transfer_direction'] == 2) {
                $transfer_type = $prefix."退款";
                $money = '-'.$money;
                $transfer_in = $account_info['name'] . "\n转入方ID：" . $account_info['advertiser_id'];
                $transfer_out = $array['name'] . "\n转出方ID：" . $array['id'];
            }

            $combined_data[] = [
                date('Y-m-d H:i:s',$transfer_records_data['update_time']),
                $transfer_out,
                $transfer_in,
                $transfer_type,
                '通用',
                $money,
                '账户余额',
                'OPENAPI'];
            unset($array);
        }

        $day = date('Ymd');
        $path = ROOT_PATH . 'public/transfer_images/' . $day . '/';
        $file_name = (int)round(microtime(true) * 1000) . '.png';

        if (!file_exists($path)) {
            $created = mkdir($path, 0755, true);
            if (!$created) {
                throw new Exception("目录创建失败: {$path}");
            }
        }

        $res = generateTransferImg($combined_data, $headers, $path, $file_name);

        if ($res) {
            return 'transfer_images/' . $day . '/' . $file_name;
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
}