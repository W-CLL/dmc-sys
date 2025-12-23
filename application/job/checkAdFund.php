<?php

namespace app\job;

use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Exception;
use think\queue\Job;
use Requests;
use function fast\e;

class checkAdFund
{

    public function fire(Job $job, $data)
    {
//        $jobId = json_decode($job->getRawBody(), true)['id'];
//        $queueModel = new \app\common\model\Queue();
//        $queueData = $queueModel->where('job_id', $jobId)->find();
//        if (!$queueData) {
//            $job->delete();
//            return '';
//        }
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $job->delete();
        }
    }


    public function doJob($data)
    {
        $warning_value = Db::name('wechat_group')->where("group_id", $data['group_id'])->value("qc_warning");
        switch ($data['type']){
            case 1:
                $params = [
                    'account_ids' => json_encode(array_map('intval', $data['advertiser_id_list']), JSON_UNESCAPED_UNICODE),
                    'account_type' => 'QIANCHUAN',
                ];
                $res = FundManagement::get_adv_balance($params);
                if($res['code'] == 0){
                    foreach ($res['data']['list'] as $item){
                        if($item['balance'] <= $warning_value * 100){
                            $is_send = Cache::get('send_warning_msg_'.$item['account_id']);
                            if(!$is_send){
                                if (!isset($msg)){
                                    $msg = "⚠ 注 意 ⚠：\n 下列【千川账户】余额💰不足，请及时充值！\n 🔔".$item['account_id'] . "，💰余额：" . number_format($item['balance'] / 100, 2) . "元";
                                }else{
                                    $msg .= "\n 🔔".$item['account_id'] . "，💰余额：" . number_format($item['balance'] / 100, 2) . "元";
                                }
                                Cache::set('send_warning_msg_'.$item['account_id'],1,1800);
                            }
                        }
                    }
                }
                break;
            case 2:
                $params = [
                    'account_id' => Db::name('qc_config')->where("id",1)->value("advertiser_id"),
                    'account_type' => 'AGENT',
                    'wallet_id_list' => json_encode(array_map('intval', $data['sub_wallet_id_list']), JSON_UNESCAPED_UNICODE)
                ];
                $res = FundManagement::get_wallet_balance($params);
                if($res['code'] == 0){
                    foreach ($res['data']['shared_wallet_balance_info'] as $item){
                        if($item['basic_balance_info']['total_balance'] <= $warning_value){
                            $is_send = Cache::get('send_warning_msg_'.$item['wallet_id']);
                            if(!$is_send){
                                if (!isset($msg)){
                                    $msg = "⚠ 注 意 ⚠：\n 下列【千川子钱包】余额💰不足，请及时充值！\n 🔔".$item['wallet_id'] . "，💰余额：" .$item['total_balance'] . "元";
                                }else{
                                    $msg .= "\n 🔔".$item['wallet_id'] . "，💰余额：" .$item['total_balance']. "元";
                                }
                                Cache::set('send_warning_msg_'.$item['wallet_id'],1,1800);
                            }
                        }
                    }
                }
                break;
        }
        if (isset($msg)) {
           return $this->sendMsg($msg, $data);
        }
        return true;
    }


    private function sendMsg($msg, $data): bool
    {
        $url_list = [
//            'http://test.frp.zebranumber.cn/add_task',   // 测试
            'http://robot1.frp.zebranumber.cn/add_task',
            'http://robot2.frp.zebranumber.cn/add_task',
        ];
        do{
            if (empty($url_list)){
                return false;
            }
            $url = $url_list[array_rand($url_list)];
            $params = [
                "group_wxid" => $data['group_id'],
                "sender_name" => "企微外部联系人",  // 不需@人
                "message" => ['msg'=>$msg],
                "msg_wxid" => "",
            ];
            $res = Requests::post($url, json_encode($params, JSON_UNESCAPED_UNICODE), array(
                'Content-Type:' . 'application/json'
            ));
            if ($res == 'ok'){
                $bool = false;
            }else{
                $bool = true;
                unset($url_list[array_search($url, $url_list)]);
            }
        }while($bool);
        return true;
    }



}